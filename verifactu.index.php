<?php 
/**
 * Auditoría VeriFactu
 */

// ======================================================
// CARGA ENTORNO DOLIBARR
// ======================================================
$res = 0;
if (!$res && file_exists(__DIR__.'/../main.inc.php')) $res = require __DIR__.'/../main.inc.php';
if (!$res && file_exists(__DIR__.'/../../main.inc.php')) $res = require __DIR__.'/../../main.inc.php';
if (!$res) die('Dolibarr environment not found');

// ======================================================
// SEGURIDAD
// ======================================================
if (empty($user->rights->verifactu->read)) {
    accessforbidden();
}

// ======================================================
// CLASES
// ======================================================
require_once __DIR__.'/class/VeriFactuRegistry.class.php';
require_once __DIR__.'/class/VeriFactuChainValidator.class.php';
require_once __DIR__.'/class/VerifactuXadesSigner.php';
require_once __DIR__.'/class/VerifactuAeatClient.php';
require_once __DIR__.'/lib/verifactu.lib.php';

// ======================================================
// IDIOMAS
// ======================================================
$langs->loadLangs(['verifactu@verifactu']);

// ======================================================
// ACCIÓN: FIRMAR XML
// ======================================================
if (GETPOST('action', 'alpha') === 'signxml' && $user->rights->verifactu->admin) {

    $id = (int) GETPOST('id', 'int');
    $registry = new VeriFactuRegistry($db);
    $record = $registry->fetchById($id);

    if ($record) {
        try {
            $baseDir = DOL_DATA_ROOT.'/verifactu/XMLverifactu/';
            $xmlPath = $baseDir.$record->xml_vf_path;

            $xml = file_get_contents($xmlPath);

            $xmlSigned = VerifactuXadesSigner::sign(
                $xml,
                $conf->global->VERIFACTU_PFX_PATH,
                $conf->global->VERIFACTU_PFX_PASSWORD
            );

            $status = 'XADES-BES';

            if (!empty($conf->global->VERIFACTU_TSA_URL)) {
                $xmlSigned = VerifactuXadesSigner::addXadesTimestamp(
                    $xmlSigned,
                    $conf->global->VERIFACTU_TSA_URL,
                    $conf->global->VERIFACTU_TSA_USER ?? '',
                    $conf->global->VERIFACTU_TSA_PASSWORD ?? ''
                );
                $status = 'XADES-T';
            }

            $signedFile = 'vf_signed_'.$id.'_'.date('Ymd_His').'.xml';
            file_put_contents($baseDir.$signedFile, $xmlSigned);

            $db->query("
                UPDATE ".MAIN_DB_PREFIX."verifactu_registry
                SET xml_signed_path='".$db->escape($signedFile)."',
                    signature_status='".$db->escape($status)."'
                WHERE rowid=".$id
            );

            setEventMessages('XML firmado correctamente', null, 'mesgs');

        } catch (Exception $e) {
            setEventMessages($e->getMessage(), null, 'errors');
        }
    }

    header('Location: '.$_SERVER['PHP_SELF']);
    exit;
}

// ======================================================
// ACCIÓN: ENVÍO MANUAL AEAT
// ======================================================
if (GETPOST('action', 'alpha') === 'send_aeat' && $user->rights->verifactu->admin) {

    if (!GETPOST('token', 'alpha')) {
        accessforbidden();
    }

    $id = (int) GETPOST('id', 'int');
    $registry = new VeriFactuRegistry($db);
    $record = $registry->fetchById($id);

    // Blindajes y coherencias
    $vfMode = $conf->global->VERIFACTU_MODE ?? 'NOSEND';

    if ($vfMode !== 'SEND') {

        // En modo conservación interna no se permite el envío manual
        setEventMessages('Modo conservación interna (NOSEND) activo: no se permite el envío a AEAT.', null, 'warnings');

    } elseif (!$record) {

        setEventMessages('Registro VeriFactu no encontrado', null, 'errors');

    } elseif (!empty($record->aeat_status) && $record->aeat_status === 'ACCEPTED') {

        // ======================================================
        // BLINDAJE FINAL: NO REENVIAR NUNCA UN ACCEPTED
        // ======================================================
        setEventMessages('Este registro ya fue ACCEPTED por la AEAT y no puede reenviarse.', null, 'warnings');

    } elseif (empty($record->xml_signed_path)) {

        setEventMessages('No existe XML firmado para este registro', null, 'errors');

    } else {

        // Comprobar que el XML firmado existe físicamente (evita "no hace nada" si falta fichero)
        $baseDir = DOL_DATA_ROOT.'/verifactu/XMLverifactu/';
        $signedFull = $baseDir.$record->xml_signed_path;

        if (!is_readable($signedFull)) {
            setEventMessages('El XML firmado no existe o no es accesible en disco: '.$record->xml_signed_path, null, 'errors');
        } else {

            try {

                // Si está vacío o es null, lo dejamos como PENDING antes de enviar (coherencia con cron)
                if (empty($record->aeat_status)) {
                    $db->query("
                        UPDATE ".MAIN_DB_PREFIX."verifactu_registry
                        SET aeat_status='PENDING'
                        WHERE rowid=".$id
                    );
                }

                $payload = $registry->buildAeatPayload($record);

                $client = new VerifactuAeatClient($db);
                $result = $client->send($payload);

                if (!empty($result['status']) && $result['status'] === 'ACCEPTED') {

                    $db->query("
                        UPDATE ".MAIN_DB_PREFIX."verifactu_registry
                        SET aeat_status='ACCEPTED',
                            aeat_csv='".$db->escape($result['csv'] ?? '')."',
                            aeat_sent_at='".$db->idate(dol_now())."'
                        WHERE rowid=".$id
                    );

                    setEventMessages('Registro enviado correctamente a la AEAT', null, 'mesgs');

                } else {
                    // Mantener PENDING para reintento por cron/manual
                    if (empty($record->aeat_status) || $record->aeat_status !== 'PENDING') {
                        $db->query("
                            UPDATE ".MAIN_DB_PREFIX."verifactu_registry
                            SET aeat_status='PENDING'
                            WHERE rowid=".$id
                        );
                    }

                    $msg = 'El envío no fue aceptado por la AEAT (se mantiene PENDING).';
                    if (!empty($result['message'])) {
                        $msg .= ' '.$result['message'];
                    }
                    setEventMessages($msg, null, 'warnings');
                }

            } catch (Exception $e) {
                // Mantener PENDING para reintento
                if (empty($record->aeat_status) || $record->aeat_status !== 'PENDING') {
                    $db->query("
                        UPDATE ".MAIN_DB_PREFIX."verifactu_registry
                        SET aeat_status='PENDING'
                        WHERE rowid=".$id
                    );
                }
                setEventMessages('Error enviando a la AEAT: '.$e->getMessage().' (se mantiene PENDING)', null, 'errors');
            }
        }
    }

    header('Location: '.$_SERVER['PHP_SELF']);
    exit;
}

// ======================================================
// FILTROS
// ======================================================
$filter_ref       = GETPOST('ref', 'alpha');
$filter_date_from = GETPOST('date_from', 'alpha');
$filter_date_to   = GETPOST('date_to', 'alpha');

// PAGINACIÓN
$page   = max(0, GETPOST('page', 'int'));
$limit  = 25;
$offset = $page * $limit;

// ======================================================
// OBJETOS
// ======================================================
$registry = new VeriFactuRegistry($db);

// ======================================================
// DATOS
// ======================================================
$total = $registry->countFiltered($filter_ref, $filter_date_from, $filter_date_to);
$records = $registry->fetchFiltered(
    $filter_ref,
    $filter_date_from,
    $filter_date_to,
    $limit,
    $offset
);

// ======================================================
// FLAGS PARA VISTA
// ======================================================
$baseDir = DOL_DATA_ROOT.'/verifactu/XMLverifactu/';
foreach ($records as $r) {

    $r->xml_vf_fullpath     = $r->xml_vf_path ? $baseDir.$r->xml_vf_path : '';
    $r->xml_signed_fullpath = $r->xml_signed_path ? $baseDir.$r->xml_signed_path : '';

    $r->has_vf_xml     = ($r->xml_vf_fullpath && file_exists($r->xml_vf_fullpath));
    $r->has_signed_xml = ($r->xml_signed_fullpath && file_exists($r->xml_signed_fullpath));
    $r->can_sign       = ($r->has_vf_xml && !$r->has_signed_xml);
}

// ======================================================
// VISTA
// ======================================================
llxHeader('', 'Auditoría VeriFactu');
print load_fiche_titre('Auditoría VeriFactu');

// ======================================================
// FORMULARIO FILTROS
// ======================================================
print '<form method="GET">';
print '<input type="text" name="ref" placeholder="Factura" value="'.dol_escape_htmltag($filter_ref).'"> ';
print '<input type="date" name="date_from" value="'.dol_escape_htmltag($filter_date_from).'"> ';
print '<input type="date" name="date_to" value="'.dol_escape_htmltag($filter_date_to).'"> ';
print '<input class="button" type="submit" value="Filtrar"> ';
print '</form>';

// ======================================================
// LISTADO
// ======================================================
include __DIR__.'/tpl/registry_list.tpl.php';

// ======================================================
// PAGINACIÓN
// ======================================================
print '<div class="pagination">';
$nbPages = ceil($total / $limit);
for ($i = 0; $i < $nbPages; $i++) {
    print ($i == $page)
        ? ' <strong>'.($i+1).'</strong> '
        : ' <a href="?page='.$i.'">'.($i+1).'</a> ';
}
print '</div>';

llxFooter();
