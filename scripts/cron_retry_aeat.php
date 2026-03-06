#!/usr/bin/env php
<?php
/**
 * Cron: Reintento automático de envío AEAT (VeriFactu)
 *
 * BLINDAJE FINAL:
 * - Nunca reenviar registros con aeat_status = ACCEPTED
 */

define('NOLOGIN', 1);
define('NOCSRFCHECK', 1);
define('NOTOKENRENEWAL', 1);
define('EVEN_IF_ONLY_LOGIN_ALLOWED', 1);

// ======================================================
// CARGA ENTORNO DOLIBARR
// ======================================================
$res = 0;
if (!$res && file_exists(__DIR__.'/../../../main.inc.php')) {
    $res = require __DIR__.'/../../../main.inc.php';
}
if (!$res) {
    fwrite(STDERR, "Dolibarr environment not found\n");
    exit(1);
}

require_once DOL_DOCUMENT_ROOT.'/custom/verifactu/class/VeriFactuRegistry.class.php';
require_once DOL_DOCUMENT_ROOT.'/custom/verifactu/class/VerifactuAeatClient.php';

// ======================================================
// COMPROBACIONES BÁSICAS
// ======================================================
if (empty($conf->verifactu->enabled)) {
    dol_syslog('VeriFactu cron: módulo no habilitado', LOG_WARNING);
    exit(0);
}

// ⚠️ ACCESO CORRECTO A CONFIGURACIÓN GLOBAL
$mode = $conf->global->VERIFACTU_MODE ?? 'NOSEND';

if ($mode !== 'SEND') {
    dol_syslog(
        'VeriFactu cron: modo NOSEND, no se reintenta envío AEAT',
        LOG_INFO
    );
    exit(0);
}

dol_syslog('VeriFactu cron: inicio reintentos AEAT', LOG_INFO);

// ======================================================
// BUSCAR REGISTROS PENDING
// ======================================================
$sql = "SELECT rowid
        FROM ".MAIN_DB_PREFIX."verifactu_registry
        WHERE aeat_status = 'PENDING'
        ORDER BY rowid ASC
        LIMIT 20"; // 🔒 límite por ejecución

$resql = $db->query($sql);

if (!$resql) {
    dol_syslog('VeriFactu cron: error SQL '.$db->lasterror(), LOG_ERR);
    exit(1);
}

$registry = new VeriFactuRegistry($db);
$client   = new VerifactuAeatClient($db);

$processed = 0;

// ======================================================
// PROCESAR REGISTROS
// ======================================================
while ($obj = $db->fetch_object($resql)) {

    $processed++;

    try {
        $record = $registry->fetchById($obj->rowid);
        if (!$record) {
            continue;
        }

        // ==================================================
        // 🔒 BLINDAJE FINAL: NO REENVIAR ACCEPTED JAMÁS
        // ==================================================
        if (!empty($record->aeat_status) && $record->aeat_status === 'ACCEPTED') {
            dol_syslog(
                'VeriFactu cron: registro '.$record->rowid.' ya ACCEPTED, se omite reenvío',
                LOG_INFO
            );
            continue;
        }

        // Construir payload AEAT
        $payload = $registry->buildAeatPayload($record);

        // Enviar a AEAT
        $response = $client->send($payload);

        if (!empty($response['status']) && $response['status'] === 'ACCEPTED') {

            $db->query("
                UPDATE ".MAIN_DB_PREFIX."verifactu_registry
                SET aeat_status = 'ACCEPTED',
                    aeat_csv = '".$db->escape($response['csv'] ?? '')."',
                    aeat_sent_at = '".$db->idate(dol_now())."'
                WHERE rowid = ".(int)$record->rowid
            );

            dol_syslog(
                'VeriFactu cron: registro '.$record->rowid.' ACCEPTED',
                LOG_INFO
            );

        } else {

            dol_syslog(
                'VeriFactu cron: registro '.$record->rowid.' sigue PENDING',
                LOG_WARNING
            );
        }

    } catch (Exception $e) {

        dol_syslog(
            'VeriFactu cron: error registro '.$obj->rowid.' - '.$e->getMessage(),
            LOG_ERR
        );
    }
}

// ======================================================
// FIN
// ======================================================
dol_syslog(
    'VeriFactu cron: fin ejecución. Procesados='.$processed,
    LOG_INFO
);

exit(0);

