<?php
defined('DOL_DOCUMENT_ROOT') or die();

require_once DOL_DOCUMENT_ROOT.'/compta/facture/class/facture.class.php';

/**
 * Lógica de hash y validación VeriFactu
 */
class VeriFactuHash
{
    /* ==========================================================
     * HASH FACTURA (ALTA)
     * ========================================================== */
    public static function calculate(Facture $facture, $previousHash)
    {
        return hash('sha256', implode('|', array(
            'ALTA',
            $facture->ref,
            self::formatDate($facture->date),
            self::formatAmount($facture->total_ht),
            self::formatAmount($facture->total_tva),
            self::formatAmount($facture->total_ttc),
            self::getSystemName(),
            self::normalize($previousHash)
        )));
    }

    /* ==========================================================
     * HASH BAJA
     * ========================================================== */
    public static function calculateCancel(Facture $facture, $previousHash, $originalHash)
    {
        return hash('sha256', implode('|', array(
            'BAJA',
            $facture->ref,
            $originalHash,
            self::formatAmount($facture->total_ttc),
            self::getSystemName(),
            self::normalize($previousHash)
        )));
    }

    /* ==========================================================
     * CRON DIARIO
     * ========================================================== */
    public static function cronDailyValidation()
    {
        global $db, $conf;

        require_once DOL_DOCUMENT_ROOT.'/custom/verifactu/class/VeriFactuChainValidator.class.php';
        require_once DOL_DOCUMENT_ROOT.'/core/class/CMailFile.class.php';

        $validator = new VeriFactuChainValidator($db);
        $validation = $validator->validate();

        if ($validation['status'] === 'OK') {
            dol_syslog('VeriFactu OK: validación diaria correcta', LOG_INFO);
            return 0;
        }

        $subject = '[VeriFactu] ALERTA - Inconsistencias detectadas';
        $message = "Se han detectado incoherencias en la validación diaria VeriFactu.\n\n";

        foreach ($validation['details'] as $d) {
            if ($d['status'] !== 'OK') {
                $message .= "Registro {$d['id']} ({$d['type']}): {$d['message']}\n";
            }
        }

        $email_to = $conf->global->VERIFACTU_ALERT_EMAIL
            ?? $conf->global->MAIN_INFO_SOCIETE_MAIL;

        if (!empty($email_to)) {
            $mail = new CMailFile(
                $subject,
                $email_to,
                $conf->global->MAIN_INFO_SOCIETE_NOM,
                $message
            );
            $mail->sendfile();
        }

        dol_syslog('VeriFactu ERROR: aviso enviado', LOG_WARNING);
        return 0;
    }

    /* ==========================================================
     * HELPERS
     * ========================================================== */
    private static function normalize($val)
    {
        return $val ?: '';
    }

    private static function formatAmount($amount)
    {
        return number_format((float) $amount, 2, '.', '');
    }

    private static function formatDate($ts)
    {
        return ($ts ? dol_print_date($ts, '%Y-%m-%d') : '');
    }

    private static function getSystemName()
    {
        return 'Dolibarr-VeriFactu';
    }
}

