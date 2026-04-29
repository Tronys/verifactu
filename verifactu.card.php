<?php
/**
 * Página pública de verificación VeriFactu
 *
 * Accesible desde QR (SANDBOX / fallback REAL)
 * SOLO LECTURA
 */

define('NOLOGIN', 1);
define('NOCSRFCHECK', 1);

require_once __DIR__ . '/../../main.inc.php';
require_once __DIR__.'/lib/verifactu.lib.php';

// --------------------------------------------------
// Entrada
// --------------------------------------------------
$id   = (int) GETPOST('id', 'int');
$hash = GETPOST('hash', 'alpha');

// --------------------------------------------------
// Cargar registro (ID interno o HASH público)
// --------------------------------------------------
$registry = null;

if ($id > 0) {

    // --- Acceso interno por ID (legacy / administración) ---
    $sql = "SELECT r.*,
                   f.ref AS facture_ref
            FROM ".MAIN_DB_PREFIX."verifactu_registry r
            LEFT JOIN ".MAIN_DB_PREFIX."facture f ON f.rowid = r.fk_facture
            WHERE r.rowid = ".((int) $id)."
            LIMIT 1";

    $resql = $db->query($sql);
    if ($resql && $db->num_rows($resql)) {
        $registry = $db->fetch_object($resql);
    }

} elseif (!empty($hash)) {

    // --- Acceso público por HASH (QR VeriFactu) ---
    $sql = "SELECT r.*,
                   f.ref AS facture_ref
            FROM ".MAIN_DB_PREFIX."verifactu_registry r
            LEFT JOIN ".MAIN_DB_PREFIX."facture f ON f.rowid = r.fk_facture
            WHERE r.hash_actual = '".$db->escape($hash)."'
            LIMIT 1";

    $resql = $db->query($sql);
    if ($resql && $db->num_rows($resql)) {
        $registry = $db->fetch_object($resql);
    }
}

// --------------------------------------------------
// RESOLUCIÓN REAL DEL ENTORNO (LEGAL)
// --------------------------------------------------
$envConfig = strtoupper($conf->global->VERIFACTU_ENVIRONMENT ?? 'SANDBOX');

$isRealAccepted = (
    $envConfig === 'REAL'
    && $registry
    && $registry->aeat_status === 'ACCEPTED'
    && !empty($registry->aeat_csv)
);

$env = $isRealAccepted ? 'REAL' : 'SANDBOX';

// --------------------------------------------------
// Cabecera HTML mínima (sin menú)
// --------------------------------------------------
print '<!DOCTYPE html>';
print '<html lang="es">';
print '<head>';
print '<meta charset="utf-8">';
print '<title>Verificación VeriFactu</title>';
print '<meta name="viewport" content="width=device-width, initial-scale=1">';
print '<style>
body{font-family:Arial,Helvetica,sans-serif;background:#f5f6f7;margin:0;padding:0}
.container{max-width:900px;margin:30px auto;background:#fff;padding:24px;border-radius:6px;box-shadow:0 2px 6px rgba(0,0,0,.1)}
h1{margin-top:0;font-size:22px}
table{width:100%;border-collapse:collapse;margin-top:15px}
td{padding:8px;border-bottom:1px solid #e0e0e0;vertical-align:top}
td.label{width:35%;font-weight:bold;color:#555}
.badge{display:inline-block;padding:3px 8px;border-radius:4px;font-size:12px}
.ok{background:#d4edda;color:#155724}
.warn{background:#fff3cd;color:#856404}
.err{background:#f8d7da;color:#721c24}
.mono{font-family:monospace;font-size:12px;word-break:break-all}
.opacitymedium{opacity:.7}
.footer{margin-top:25px;font-size:12px;color:#777;text-align:center}
</style>';
print '</head>';
print '<body>';

print '<div class="container">';
print '<h1>Verificación factura (VeriFactu)</h1>';

// --------------------------------------------------
// AVISO DE ENTORNO (LEGAL / AUDITORÍA)
// --------------------------------------------------
if ($env === 'SANDBOX') {
    print '<div style="margin:12px 0;padding:12px;background:#fff3cd;border:1px solid #ffeeba;border-radius:4px">';
    print '<strong>⚠️ ENTORNO DE PRUEBAS (SANDBOX)</strong><br>';
    print 'Este registro se ha generado en un entorno de pruebas o está pendiente de validación real ante la AEAT.';
    print '</div>';
} else {
    print '<div style="margin:12px 0;padding:12px;background:#d4edda;border:1px solid #c3e6cb;border-radius:4px">';
    print '<strong>✅ ENTORNO REAL (AEAT)</strong><br>';
    print 'Este registro ha sido generado en entorno real y validado por la Agencia Tributaria.';
    print '</div>';
}

// --------------------------------------------------
// Sin registro
// --------------------------------------------------
if (!$registry) {

    print '<p>No existe información de verificación para este código.</p>';

} else {

    // --------------------------------------------------
    // FIRMA
    // --------------------------------------------------
    $signedPath = '';

    if (!empty($registry->xml_signed_path)) {
        $candidate = DOL_DATA_ROOT.'/verifactu/XMLverifactu/'.$registry->xml_signed_path;
        if (is_readable($candidate)) {
            $signedPath = $candidate;
        }
    }

    $sig = verifactu_get_signature_status($signedPath);

    // --------------------------------------------------
    // Cadena
    // --------------------------------------------------
    $chain = verifactu_check_chain_for_row($db, (int)$registry->entity, (int)$registry->rowid);

    // Badges
    $envBadge  = $env === 'REAL' ? 'ok' : 'warn';

    $aeatBadge = 'warn';
    if ($registry->aeat_status === 'ACCEPTED') $aeatBadge = 'ok';
    if (in_array($registry->aeat_status, array('ERROR','REJECTED'), true)) $aeatBadge = 'err';

    $chainBadge = 'warn';
    if ($chain['status'] === 'OK') $chainBadge = 'ok';
    if ($chain['status'] === 'BROKEN') $chainBadge = 'err';

    print '<table>';

    print '<tr><td class="label">Factura</td><td>'.dol_escape_htmltag($registry->facture_ref ?: '—').'</td></tr>';
    print '<tr><td class="label">Fecha registro</td><td>'.dol_print_date($registry->date_creation,'dayhour').'</td></tr>';

    print '<tr><td class="label">Entorno</td><td>';
    print '<span class="badge '.$envBadge.'">'.$env.'</span><br>';
    if ($env === 'SANDBOX') {
        print '<span class="opacitymedium">Registro de prueba o pendiente de validación fiscal.</span>';
    } else {
        print '<span class="opacitymedium">Registro fiscal válido ante la AEAT.</span>';
    }
    print '</td></tr>';

    print '<tr><td class="label">Cadena (integridad)</td><td>';
    print '<span class="badge '.$chainBadge.'">'.dol_escape_htmltag($chain['status']).'</span> ';
    print dol_escape_htmltag($chain['message']);
    print '</td></tr>';

    print '<tr><td class="label">Hash actual</td><td class="mono">'.dol_escape_htmltag($registry->hash_actual).'</td></tr>';

    print '<tr><td class="label">Hash anterior</td><td class="mono">'
        .(!empty($registry->hash_anterior) ? dol_escape_htmltag($registry->hash_anterior) : 'Inicio de cadena')
        .'</td></tr>';

    print '<tr><td class="label">Firma</td><td>'.dol_escape_htmltag($sig['label']).'</td></tr>';

    print '<tr><td class="label">Estado AEAT</td><td><span class="badge '.$aeatBadge.'">'
        .dol_escape_htmltag($registry->aeat_status ?: '—').'</span></td></tr>';

    print '<tr><td class="label">CSV AEAT</td><td class="mono">'
        .(!empty($registry->aeat_csv) ? dol_escape_htmltag($registry->aeat_csv) : '—')
        .'</td></tr>';

    print '</table>';
}

// --------------------------------------------------
print '<div class="footer">';
print 'Sistema informático de facturación conforme al Real Decreto 1007/2023 (VeriFactu).';
print '</div>';

print '</div></body></html>';
