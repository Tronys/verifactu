<?php
/**
 * Vista detalle de un registro VeriFactu
 * - Generación manual de XML VeriFactu
 * - Firma XAdES
 * - Validación local y XSD
 * - Envío manual a AEAT
 * - Persistencia de respuesta AEAT
 */


// ======================================================
// CARGA ENTORNO DOLIBARR (FORMA CORRECTA)
// ======================================================
$res = 0;
if (!$res && file_exists(__DIR__ . '/../main.inc.php')) {
    $res = require __DIR__ . '/../main.inc.php';
}
if (!$res && file_exists(__DIR__ . '/../../main.inc.php')) {
    $res = require __DIR__ . '/../../main.inc.php';
}
if (!$res && file_exists(__DIR__ . '/../../../main.inc.php')) {
    $res = require __DIR__ . '/../../../main.inc.php';
}
if (!$res) {
    die('Dolibarr environment not found');
}


// ======================================================
// SEGURIDAD
// ======================================================
if (empty($user->rights->verifactu->read)) {
    accessforbidden();
}

// ======================================================
// PARÁMETROS
// ======================================================
$id     = GETPOST('id', 'int');
$action = GETPOST('action', 'alpha');

if (empty($id)) {
    dol_print_error('', 'ID de registro no indicado');
    exit;
}

// ======================================================
// ACCIÓN: GENERAR XML + FIRMA XAdES
// ======================================================
if ($action === 'generate_xml') {

    if (empty($user->rights->verifactu->admin)) accessforbidden();


require_once __DIR__.'/class/VerifactuXmlBuilder.php';
require_once __DIR__.'/aeat/xml/VeriFactuXmlStorage.class.php';
require_once __DIR__.'/class/VerifactuXadesSigner.php';
require_once __DIR__.'/aeat/xml/VeriFactuXmlValidator.class.php';
require_once __DIR__.'/aeat/xml/VeriFactuXsdValidator.class.php';

	
	

    $sql = "SELECT * FROM ".MAIN_DB_PREFIX."verifactu_registry WHERE rowid=".(int)$id;
    $resql = $db->query($sql);

    if (!$resql || !$db->num_rows($resql)) {
        setEventMessage('Registro VeriFactu no encontrado', 'errors');
        header("Location: ?id=".$id);
        exit;
    }

    $registry = $db->fetch_object($resql);

    if (!empty($registry->xml_path)) {
        setEventMessage('El XML ya fue generado previamente', 'warnings');
        header("Location: ?id=".$id);
        exit;
    }

    try {
        require_once __DIR__.'/class/VeriFactuRegistry.class.php';
        require_once __DIR__.'/class/VeriFactuHash.class.php';
        require_once DOL_DOCUMENT_ROOT.'/compta/facture/class/facture.class.php';

        // 1. Reconstruir datos de la factura para el XML
        $facture = new Facture($db);
        if ($facture->fetch((int) $registry->fk_facture) <= 0) {
            throw new Exception('Factura no encontrada (id='.$registry->fk_facture.')');
        }
        $facture->fetch_lines();
        $facture->fetch_thirdparty();

        $nifEmisor    = (string) ($conf->global->MAIN_INFO_SIREN ?? '');
        $nombreEmisor = (string) ($conf->global->MAIN_INFO_SOCIETE_NOM ?? '');
        $tipoFactura  = !empty($registry->tipo_factura) ? $registry->tipo_factura : VeriFactuHash::detectTipoFactura($facture);
        $desgloseIva  = VeriFactuHash::desgloseIva($facture);
        $destinatario = null;
        if ($tipoFactura === 'F1') {
            $tp  = $facture->thirdparty;
            $nif = trim((string) ($tp->idprof1 ?? '')) ?: trim((string) ($tp->tva_intra ?? ''));
            if ($nif) {
                $destinatario = ['nombre' => (string) $tp->name, 'nif' => $nif];
            }
        }

        // 2. Construir XML
        $xmlUnsigned = VerifactuXmlBuilder::build([
            'tipo'           => $registry->record_type,
            'fecha'          => $registry->date_creation,
            'factura'        => $facture->ref,
            'fecha_factura'  => date('Y-m-d', (int) $facture->date),
            'tipo_factura'   => $tipoFactura,
            'nif_emisor'     => $nifEmisor,
            'nombre_emisor'  => $nombreEmisor,
            'total'          => (float) $registry->total_ttc,
            'cuota_total'    => (float) ($registry->cuota_total ?? 0),
            'huella'         => $registry->hash_actual,
            'huella_anterior'=> $registry->hash_anterior,
            'desglose_iva'   => $desgloseIva,
            'destinatario'   => $destinatario,
        ]);

        // 3. Firmar XAdES
        $pfxPath = (string) ($conf->global->VERIFACTU_PFX_PATH ?? '');
        $pfxPass = (string) ($conf->global->VERIFACTU_PFX_PASSWORD ?? '');
        $xmlSigned = VerifactuXadesSigner::sign($xmlUnsigned, $pfxPath, $pfxPass);

        $tsaUrl = trim((string) ($conf->global->VERIFACTU_TSA_URL ?? ''));
        if ($tsaUrl) {
            $xmlSigned = VerifactuXadesSigner::addXadesTimestamp(
                $xmlSigned,
                $tsaUrl,
                (string) ($conf->global->VERIFACTU_TSA_USER ?? ''),
                (string) ($conf->global->VERIFACTU_TSA_PASSWORD ?? '')
            );
        }

        // 3️⃣ Validación interna
        $validation = VeriFactuXmlValidator::validate($xmlSigned);
        if (!$validation['ok']) {
            foreach ($validation['errors'] as $e) {
                setEventMessage($e, 'errors');
            }
            header("Location: ?id=".$id);
            exit;
        }

        // 4️⃣ Validación XSD AEAT
        $xsd = VeriFactuXsdValidator::validate(
            $xmlSigned,
            __DIR__.'/aeat/xsd/VeriFactu_v1_0.xsd'
        );

        if (!$xsd['ok']) {
            foreach ($xsd['errors'] as $e) {
                setEventMessage('XSD: '.$e, 'errors');
            }
            header("Location: ?id=".$id);
            exit;
        }

        // 5️⃣ Guardar XML
        $path = VeriFactuXmlStorage::save($registry, $xmlSigned);

        $db->query("
            UPDATE ".MAIN_DB_PREFIX."verifactu_registry
            SET xml_path = '".$db->escape($path)."'
            WHERE rowid = ".(int)$id
        );

        setEventMessage('✅ XML generado, firmado y validado correctamente');

    } catch (Exception $e) {
        setEventMessage($e->getMessage(), 'errors');
    }

    header("Location: ?id=".$id);
    exit;
}

// ======================================================
// CARGA REGISTRO
// ======================================================
$sql = "
    SELECT r.*, f.ref
    FROM ".MAIN_DB_PREFIX."verifactu_registry r
    LEFT JOIN ".MAIN_DB_PREFIX."facture f ON f.rowid = r.fk_facture
    WHERE r.rowid = ".(int)$id;

$resql = $db->query($sql);

if (!$resql || !$db->num_rows($resql)) {
    dol_print_error('', 'Registro no encontrado');
    exit;
}

$record = $db->fetch_object($resql);

// ======================================================
// VISTA
// ======================================================
llxHeader('', 'Detalle VeriFactu');

print load_fiche_titre('Registro VeriFactu #'.$record->rowid);

// ======================================================
// ACCIONES
// ======================================================
print '<div class="tabsAction">';

if (!empty($user->rights->verifactu->admin) && empty($record->xml_path)) {
    print '<a class="butAction" href="?id='.$record->rowid.'&action=generate_xml">Generar XML</a>';
}

if (!empty($record->xml_path)) {
print '<a class="butAction" target="_blank" href="'
    . DOL_URL_ROOT
    . '/document.php?modulepart=verifactu&entity='.(int)$conf->entity
    . '&file=' . urlencode($record->xml_path)
    . '">Descargar XML</a>';
}







print '</div>';

// ======================================================
// DATOS REGISTRO
// ======================================================
print '<div class="fichecenter">';
print '<table class="border centpercent">';

print '<tr><td width="25%">Factura</td><td>'.$record->ref.'</td></tr>';
print '<tr><td>Tipo</td><td>'.$record->record_type.'</td></tr>';
print '<tr><td>Fecha</td><td>'.dol_print_date($record->date_creation, 'dayhour').'</td></tr>';
print '<tr><td>Total</td><td>'.price($record->total_ttc).'</td></tr>';
print '<tr><td>Estado AEAT</td><td>'.($record->aeat_status ?: '<em>No enviado</em>').'</td></tr>';
print '<tr><td>CSV AEAT</td><td>'.($record->aeat_csv ?: '-').'</td></tr>';

print '</table>';
print '</div>';

llxFooter();

