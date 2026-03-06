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


require_once DOL_DOCUMENT_ROOT.'/custom/verifactu/aeat/xml/VeriFactuXmlBuilder.class.php';
require_once DOL_DOCUMENT_ROOT.'/custom/verifactu/aeat/xml/VeriFactuXmlStorage.class.php';
require_once DOL_DOCUMENT_ROOT.'/custom/verifactu/aeat/xml/VeriFactuXAdES.class.php';
require_once DOL_DOCUMENT_ROOT.'/custom/verifactu/aeat/xml/VeriFactuXmlValidator.class.php';
require_once DOL_DOCUMENT_ROOT.'/custom/verifactu/aeat/xml/VeriFactuXsdValidator.class.php';

	
	

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
        // 1️⃣ Construir XML base
        $xmlUnsigned = VeriFactuXmlBuilder::build($registry);

        // 2️⃣ Firmar XAdES
        $xades = new VeriFactuXAdES($db);

        $xmlSigned = $xades->sign(
            $xmlUnsigned,
            [
                'pfx_file' => $conf->global->VERIFACTU_PFX_PATH,
                'pfx_pass' => $conf->global->VERIFACTU_PFX_PASSWORD
            ],
            true // XAdES-T si hay TSA
        );

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
            DOL_DOCUMENT_ROOT.'/custom/verifactu/aeat/xsd/VeriFactu_v1_0.xsd'
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

