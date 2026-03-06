<?php
/**
 * Página de configuración del módulo VeriFactu
 * - Subida segura de certificado .pfx
 * - Test de certificado
 * - Test de TSA (XAdES-T)
 * - Configuración modo VeriFactu (SEND / NOSEND)
 * - Bloqueo por ejercicio fiscal (no cambiar durante el año)
 * - CSRF compatible Dolibarr 18–22
 * - Configuración Entorno AEAT (SANDBOX / REAL) y endpoints
 */

require_once __DIR__ . '/../../../main.inc.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/functions2.lib.php';

// --------------------------------------------------
// SEGURIDAD
// --------------------------------------------------
if (empty($user->admin)) {
    accessforbidden();
}

// --------------------------------------------------
// IDIOMAS
// --------------------------------------------------
$langs->loadLangs(array('admin', 'verifactu@verifactu'));

// --------------------------------------------------
// HELPERS
// --------------------------------------------------
/**
 * Bloqueo por ejercicio fiscal:
 * Devuelve true si ya existe al menos 1 registro en verifactu_registry
 * en el año natural actual, para la entidad actual.
 */
function verifactu_mode_locked_for_year($db, $entity, $year)
{
    $sql = "SELECT 1
            FROM ".MAIN_DB_PREFIX."verifactu_registry
            WHERE entity = ".((int) $entity)."
              AND date_creation >= '".$db->escape($year."-01-01 00:00:00")."'
              AND date_creation <= '".$db->escape($year."-12-31 23:59:59")."'
            LIMIT 1";

    $resql = $db->query($sql);
    if ($resql && $db->num_rows($resql) > 0) {
        return true;
    }
    return false;
}

// --------------------------------------------------
// ACCIÓN
// --------------------------------------------------
$action = GETPOST('action', 'alpha');

// --------------------------------------------------
// COMPROBAR BLOQUEO POR AÑO FISCAL
// --------------------------------------------------
$currentYear = date('Y');
$modeLocked  = verifactu_mode_locked_for_year($db, $conf->entity, $currentYear);

// Valores actuales (con fallback seguro)
$currentMode = $conf->global->VERIFACTU_MODE ?? 'NOSEND';

// ==================================================
// GUARDAR CONFIGURACIÓN + CERTIFICADO
// ==================================================
if ($action === 'save') {

    // CSRF
    if (!GETPOST('token', 'alpha')) {
        accessforbidden();
    }

    // --------------------------------------------------
    // MODO VERIFACTU + ENVÍO AUTO (bloqueo anual REAL)
    // --------------------------------------------------
    $postedMode = GETPOST('VERIFACTU_MODE', 'alpha');
    if (empty($postedMode)) {
        $postedMode = $currentMode;
    }

    // Normalizar
    $postedMode = strtoupper(trim($postedMode));
    if (!in_array($postedMode, array('SEND', 'NOSEND'), true)) {
        $postedMode = $currentMode;
    }

    // Si está bloqueado el ejercicio y pretenden cambiar el modo -> BLOQUEO
    if ($modeLocked && $postedMode !== $currentMode) {

        setEventMessages(
            '⚠️ No es posible cambiar el modo VeriFactu durante el ejercicio fiscal '.$currentYear.'. '
            .'El modo queda bloqueado hasta el inicio del próximo ejercicio.',
            null,
            'errors'
        );

        header('Location: '.$_SERVER['PHP_SELF']);
        exit;
    }

    // Guardar modo (solo cambia si no está bloqueado o si no hay cambio)
    dolibarr_set_const(
        $db,
        'VERIFACTU_MODE',
        $postedMode,
        'chaine',
        0,
        '',
        $conf->entity
    );

    /**
     * ✅ CAMBIO (OPCIÓN 1):
     * Eliminamos la ambigüedad del checkbox "enviar al validar".
     * - En modo SEND, el sistema SIEMPRE intenta enviar al validar (según trigger).
     * - En modo NOSEND, nunca envía.
     *
     * Por compatibilidad con instalaciones previas, dejamos la constante VERIFACTU_SEND_ON_VALIDATE
     * auto-coherente:
     *   - SEND   => 1
     *   - NOSEND => 0
     * (sin mostrar UI ni permitir elección).
     */
    $forcedSendOnValidate = ($postedMode === 'SEND') ? 1 : 0;
    dolibarr_set_const(
        $db,
        'VERIFACTU_SEND_ON_VALIDATE',
        $forcedSendOnValidate,
        'yesno',
        0,
        '',
        $conf->entity
    );

    // --------------------------------------------------
    // NUEVO: ENTORNO AEAT + ENDPOINTS
    // --------------------------------------------------
    $postedEnv = GETPOST('VERIFACTU_ENVIRONMENT', 'alpha');
    $postedEnv = strtoupper(trim((string) $postedEnv));
    if (!in_array($postedEnv, array('SANDBOX', 'REAL'), true)) {
        $postedEnv = ($conf->global->VERIFACTU_ENVIRONMENT ?? 'SANDBOX');
        $postedEnv = strtoupper(trim((string) $postedEnv));
        if (!in_array($postedEnv, array('SANDBOX', 'REAL'), true)) {
            $postedEnv = 'SANDBOX';
        }
    }

    $postedSandboxUrl = trim((string) GETPOST('VERIFACTU_AEAT_SANDBOX_URL', 'alphanohtml'));
    $postedProdUrl    = trim((string) GETPOST('VERIFACTU_AEAT_PROD_URL', 'alphanohtml'));

    // Si seleccionan REAL, exigir endpoint de producción
    if ($postedEnv === 'REAL' && empty($postedProdUrl)) {
        setEventMessages(
            '⚠️ En entorno REAL debe configurarse la URL del endpoint AEAT (Producción).',
            null,
            'errors'
        );
        header('Location: '.$_SERVER['PHP_SELF']);
        exit;
    }

    dolibarr_set_const($db, 'VERIFACTU_ENVIRONMENT', $postedEnv, 'chaine', 0, '', $conf->entity);
    dolibarr_set_const($db, 'VERIFACTU_AEAT_SANDBOX_URL', $postedSandboxUrl, 'chaine', 0, '', $conf->entity);
    dolibarr_set_const($db, 'VERIFACTU_AEAT_PROD_URL', $postedProdUrl, 'chaine', 0, '', $conf->entity);

    // --------------------------------------------------
    // TSA
    // --------------------------------------------------
    dolibarr_set_const($db, 'VERIFACTU_TSA_URL', GETPOST('VERIFACTU_TSA_URL', 'alphanohtml'), 'chaine', 0, '', $conf->entity);
    dolibarr_set_const($db, 'VERIFACTU_TSA_USER', GETPOST('VERIFACTU_TSA_USER', 'alphanohtml'), 'chaine', 0, '', $conf->entity);
    dolibarr_set_const($db, 'VERIFACTU_TSA_PASSWORD', GETPOST('VERIFACTU_TSA_PASSWORD', 'none'), 'chaine', 0, '', $conf->entity);

    // --------------------------------------------------
    // SUBIDA SEGURA CERTIFICADO
    // --------------------------------------------------
    if (!empty($_FILES['verifactu_pfx']['tmp_name'])) {

        $password = GETPOST('VERIFACTU_PFX_PASSWORD', 'none');

        if (empty($password)) {
            setEventMessage('Debe indicar la contraseña del certificado', 'errors');
            header('Location: '.$_SERVER['PHP_SELF']);
            exit;
        }

        $dir = DOL_DATA_ROOT . '/verifactu/certs';
        if (!is_dir($dir)) {
            dol_mkdir($dir);
        }

        $dest = $dir . '/verifactu_cert_entity_' . ((int) $conf->entity) . '.pfx';

        if (!move_uploaded_file($_FILES['verifactu_pfx']['tmp_name'], $dest)) {
            setEventMessage('Error al guardar el certificado', 'errors');
            header('Location: '.$_SERVER['PHP_SELF']);
            exit;
        }

        chmod($dest, 0600);

        $pfx   = file_get_contents($dest);
        $certs = array();

        if (!openssl_pkcs12_read($pfx, $certs, $password)) {
            unlink($dest);
            setEventMessage('Certificado inválido o contraseña incorrecta', 'errors');
            header('Location: '.$_SERVER['PHP_SELF']);
            exit;
        }

        dolibarr_set_const($db, 'VERIFACTU_PFX_PATH', $dest, 'chaine', 0, '', $conf->entity);
        dolibarr_set_const($db, 'VERIFACTU_PFX_PASSWORD', $password, 'chaine', 0, '', $conf->entity);

        setEventMessage('✅ Certificado validado y guardado correctamente');
    } else {
        setEventMessage('Configuración guardada');
    }

    header('Location: '.$_SERVER['PHP_SELF']);
    exit;
}

// ==================================================
// TEST CERTIFICADO
// ==================================================
if ($action === 'test_cert') {

    if (!GETPOST('token', 'alpha')) {
        accessforbidden();
    }

    $path = $conf->global->VERIFACTU_PFX_PATH ?? '';
    $pass = $conf->global->VERIFACTU_PFX_PASSWORD ?? '';

    if (!$path || !file_exists($path)) {
        setEventMessage('No existe certificado configurado', 'errors');
    } else {
        $pfx = file_get_contents($path);
        $certs = array();

        if (openssl_pkcs12_read($pfx, $certs, $pass)) {
            setEventMessage('✅ Certificado correcto y accesible');
        } else {
            setEventMessage('❌ Error en certificado o contraseña', 'errors');
        }
    }

    header('Location: '.$_SERVER['PHP_SELF']);
    exit;
}

// ==================================================
// TEST TSA
// ==================================================
if ($action === 'test_tsa') {

    if (!GETPOST('token', 'alpha')) {
        accessforbidden();
    }

    $tsa = trim($conf->global->VERIFACTU_TSA_URL ?? '');

    if (empty($tsa)) {
        setEventMessage('Debe configurar la URL del servidor TSA', 'errors');
    } else {
        $ctx = stream_context_create(array(
            'http' => array('timeout' => 5)
        ));

        $fp = @fopen($tsa, 'rb', false, $ctx);

        if ($fp) {
            fclose($fp);
            setEventMessage('✅ Servidor TSA accesible');
        } else {
            setEventMessage('❌ No se puede conectar con el servidor TSA', 'errors');
        }
    }

    header('Location: '.$_SERVER['PHP_SELF']);
    exit;
}

// ==================================================
// VISTA
// ==================================================
llxHeader('', $langs->trans('VeriFactuSetup'));
print load_fiche_titre($langs->trans('VeriFactuSetup'));

print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'" enctype="multipart/form-data">';
print '<input type="hidden" name="token" value="'.newToken().'">';

// Releer por si la vista necesita el valor actual tras guardar
$currentMode = $conf->global->VERIFACTU_MODE ?? 'NOSEND';

// Releer valores entorno
$currentEnv = $conf->global->VERIFACTU_ENVIRONMENT ?? 'SANDBOX';
$currentEnv = strtoupper(trim((string) $currentEnv));
if (!in_array($currentEnv, array('SANDBOX', 'REAL'), true)) {
    $currentEnv = 'SANDBOX';
}

// Default sandbox si aún no hay valor (para evitar campo vacío tras activar)
$currentSandboxUrl = $conf->global->VERIFACTU_AEAT_SANDBOX_URL ?? '';
if (empty($currentSandboxUrl)) {
    $currentSandboxUrl = 'https://prewww1.aeat.es/verifactu/sandbox';
}
$currentProdUrl = $conf->global->VERIFACTU_AEAT_PROD_URL ?? '';

// ------------- MODO VERIFACTU -------------
print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><td colspan="2">Modo de funcionamiento VeriFactu</td></tr>';

/**
 * ✅ CAMBIO: mensaje mucho más claro
 * - El bloqueo afecta SOLO a la decisión fiscal SEND/NOSEND.
 * - En modo SEND, el envío a AEAT se intenta automáticamente al validar factura.
 */
if ($modeLocked) {
    print '<tr><td colspan="2">';
    print '<div class="warning">';
    print '⚠️ <strong>Decisión fiscal bloqueada (ejercicio '.$currentYear.').</strong> ';
    print 'El régimen (<strong>Envío a AEAT</strong> / <strong>Conservación interna</strong>) no puede modificarse durante el ejercicio.';
    print '</div>';
    print '</td></tr>';
}

$disabled = $modeLocked ? ' disabled' : '';

print '<tr><td width="30%">Modo (régimen fiscal)</td><td>';

print '<label><input type="radio" name="VERIFACTU_MODE" value="SEND"'
    .($currentMode === 'SEND' ? ' checked' : '').$disabled.'> ';
print '<strong>Envío automático a la AEAT</strong></label><br>';

print '<label><input type="radio" name="VERIFACTU_MODE" value="NOSEND"'
    .($currentMode === 'NOSEND' ? ' checked' : '').$disabled.'> ';
print '<strong>Conservación interna (sin envío)</strong></label>';

print '<br><span class="opacitymedium">';
print 'Este modo define el régimen del sistema y queda bloqueado en cuanto existan registros del ejercicio.';
print '</span>';

print '</td></tr>';

// Si está bloqueado, al estar disabled el radio NO se enviaría por POST.
// Metemos hidden para garantizar que el backend recibe el valor actual.
if ($modeLocked) {
    print '<input type="hidden" name="VERIFACTU_MODE" value="'.dol_escape_htmltag($currentMode).'">';
}

/**
 * ✅ CAMBIO (OPCIÓN 1):
 * Eliminamos el checkbox "Intentar envío inmediato".
 * - SEND => se intenta envío automático al validar.
 * - Si falla, quedará en PENDING para cron y/o envío manual desde auditoría.
 */
print '<tr><td>Comportamiento del envío</td><td>';
if ($currentMode === 'SEND') {
    print '<div class="info" style="margin:6px 0;padding:10px;">';
    print '📡 <strong>Modo SEND activo:</strong> al validar una factura se genera el registro, se firma el XML y ';
    print '<strong>se intenta el envío automáticamente a la AEAT</strong>. ';
    print 'Si hubiera una incidencia técnica, el registro quedará en <strong>PENDING</strong> para reintento por cron o envío manual.';
    print '</div>';
} else {
    print '<div class="info" style="margin:6px 0;padding:10px;">';
    print '🗂️ <strong>Modo NOSEND activo:</strong> los registros se conservan de forma íntegra e inalterable ';
    print 'y <strong>no se envían</strong> a la AEAT.';
    print '</div>';
}
print '</td></tr>';

print '</table><br>';

// ------------- NUEVO: ENTORNO AEAT -------------
print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><td colspan="2">Entorno AEAT VeriFactu</td></tr>';

print '<tr><td width="30%">Entorno</td><td>';
print '<select class="flat" name="VERIFACTU_ENVIRONMENT">';
print '<option value="SANDBOX"'.($currentEnv==='SANDBOX'?' selected':'').'>🧪 Sandbox (Pruebas)</option>';
print '<option value="REAL"'.($currentEnv==='REAL'?' selected':'').'>🚨 Producción (Real)</option>';
print '</select>';
print '</td></tr>';

print '<tr><td>Endpoint Sandbox</td>';
print '<td><input class="flat minwidth400" type="text" name="VERIFACTU_AEAT_SANDBOX_URL" value="'.dol_escape_htmltag($currentSandboxUrl).'"></td></tr>';

print '<tr><td>Endpoint Producción</td>';
print '<td><input class="flat minwidth400" type="text" name="VERIFACTU_AEAT_PROD_URL" value="'.dol_escape_htmltag($currentProdUrl).'">';
if ($currentEnv === 'REAL') {
    print '<br><span class="opacitymedium">⚠️ Obligatorio en entorno REAL.</span>';
} else {
    print '<br><span class="opacitymedium">Se utilizará el sandbox mientras el entorno sea SANDBOX.</span>';
}
print '</td></tr>';

print '</table><br>';

// ------------- CERTIFICADO -------------
print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><td colspan="2">Certificado XAdES</td></tr>';

print '<tr><td width="30%">Subir certificado (.pfx)</td>';
print '<td><input type="file" name="verifactu_pfx" accept=".pfx,.p12"></td></tr>';

print '<tr><td>Contraseña</td>';
print '<td><input type="password" class="flat minwidth300" name="VERIFACTU_PFX_PASSWORD"></td></tr>';

if (!empty($conf->global->VERIFACTU_PFX_PATH)) {
    print '<tr><td>Certificado actual</td>';
    print '<td><span style="color:#2b7a0b;">✔ '.dol_escape_htmltag($conf->global->VERIFACTU_PFX_PATH).'</span></td></tr>';
}

print '</table><br>';

// ------------- TSA -------------
print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><td colspan="2">TSA (XAdES-T)</td></tr>';

print '<tr><td width="30%">URL TSA</td>';
print '<td><input class="flat minwidth400" type="text" name="VERIFACTU_TSA_URL" value="'.dol_escape_htmltag($conf->global->VERIFACTU_TSA_URL ?? '').'"></td></tr>';

print '<tr><td>Usuario TSA</td>';
print '<td><input class="flat minwidth300" type="text" name="VERIFACTU_TSA_USER" value="'.dol_escape_htmltag($conf->global->VERIFACTU_TSA_USER ?? '').'"></td></tr>';

print '<tr><td>Password TSA</td>';
print '<td><input class="flat minwidth300" type="password" name="VERIFACTU_TSA_PASSWORD" value="'.dol_escape_htmltag($conf->global->VERIFACTU_TSA_PASSWORD ?? '').'"></td></tr>';

print '</table><br>';

// ------------- BOTONES -------------
print '<div class="center">';
print '<button class="button button-save" name="action" value="save">Guardar</button> ';
print '<button class="butAction" name="action" value="test_cert">🔐 Probar certificado</button> ';
print '<button class="butAction" name="action" value="test_tsa">⏱ Probar TSA</button>';
print '</div>';

print '</form>';

llxFooter();
