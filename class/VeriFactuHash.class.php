<?php
defined('DOL_DOCUMENT_ROOT') or die();

require_once DOL_DOCUMENT_ROOT.'/compta/facture/class/facture.class.php';

/**
 * Cálculo de Huella (hash de encadenamiento) VeriFactu.
 *
 * Algoritmo oficial AEAT: SHA-256 sobre cadena de campos clave=valor separados
 * por '&', en el orden exacto definido en las Especificaciones Técnicas AEAT.
 *
 * Cadena ALTA:
 *   IDEmisorFactura=X&NumSerieFactura=X&FechaExpedicionFactura=DD-MM-YYYY
 *   &TipoFactura=X&CuotaTotal=X.XX&ImporteTotal=X.XX&Huella=X
 *   &FechaHoraHusoGenRegistro=YYYY-MM-DDTHH:MM:SS±HH:MM
 */
class VeriFactuHash
{
    /**
     * Calcula la Huella para un registro ALTA.
     *
     * @param string $nifEmisor       NIF/CIF del emisor de la factura
     * @param string $numSerie        Número/serie de la factura (ref Dolibarr)
     * @param int    $fechaFactura    Fecha de expedición (timestamp Unix)
     * @param string $tipoFactura     F1 (completa) o F2 (simplificada)
     * @param float  $cuotaTotal      Suma de cuotas IVA
     * @param float  $importeTotal    Importe total con IVA
     * @param string $prevHash        Huella del registro anterior ('' si es el primero)
     * @param int    $fechaHoraGen    Timestamp de generación del registro
     * @return string Huella SHA-256 en hexadecimal
     */
    public static function calculate(
        $nifEmisor,
        $numSerie,
        $fechaFactura,
        $tipoFactura,
        $cuotaTotal,
        $importeTotal,
        $prevHash,
        $fechaHoraGen
    ) {
        $cadena = implode('&', [
            'IDEmisorFactura='          . (string) $nifEmisor,
            'NumSerieFactura='          . (string) $numSerie,
            'FechaExpedicionFactura='   . date('d-m-Y', (int) $fechaFactura),
            'TipoFactura='              . (string) $tipoFactura,
            'CuotaTotal='               . number_format((float) $cuotaTotal, 2, '.', ''),
            'ImporteTotal='             . number_format((float) $importeTotal, 2, '.', ''),
            'Huella='                   . (string) $prevHash,
            'FechaHoraHusoGenRegistro=' . self::isoWithOffset((int) $fechaHoraGen),
        ]);

        return hash('sha256', $cadena);
    }

    /**
     * Calcula la Huella para un registro BAJA (anulación).
     *
     * Para BAJA el TipoFactura corresponde al tipo del registro ALTA original.
     *
     * @param string $nifEmisor
     * @param string $numSerie
     * @param int    $fechaFactura    Fecha de expedición de la factura original
     * @param string $tipoFactura     Tipo del ALTA original (F1, F2, …)
     * @param string $prevHash        Huella del registro anterior en la cadena
     * @param int    $fechaHoraGen    Timestamp de generación de este registro BAJA
     * @return string Huella SHA-256
     */
    public static function calculateCancel(
        $nifEmisor,
        $numSerie,
        $fechaFactura,
        $tipoFactura,
        $prevHash,
        $fechaHoraGen
    ) {
        $cadena = implode('&', [
            'IDEmisorFactura='          . (string) $nifEmisor,
            'NumSerieFactura='          . (string) $numSerie,
            'FechaExpedicionFactura='   . date('d-m-Y', (int) $fechaFactura),
            'TipoFactura='              . (string) $tipoFactura,
            'Huella='                   . (string) $prevHash,
            'FechaHoraHusoGenRegistro=' . self::isoWithOffset((int) $fechaHoraGen),
        ]);

        return hash('sha256', $cadena);
    }

    /**
     * Detecta si la factura es F1 (completa) o F2 (simplificada).
     *
     * F1: el destinatario está identificado con NIF/CIF.
     * F2: venta al contado, ticket, sin identificación del comprador.
     *
     * @param Facture $facture
     * @return string 'F1' o 'F2'
     */
    public static function detectTipoFactura(Facture $facture)
    {
        if (empty($facture->thirdparty) || empty($facture->thirdparty->id)) {
            $facture->fetch_thirdparty();
        }

        $tp = $facture->thirdparty;
        if (empty($tp)) {
            return 'F2';
        }

        // idprof1 = NIF/CIF en instalaciones españolas
        $nif = trim((string) ($tp->idprof1 ?? ''));
        if ($nif === '') {
            // tva_intra puede contener el NIF intracomunitario
            $nif = trim((string) ($tp->tva_intra ?? ''));
        }

        return ($nif !== '') ? 'F1' : 'F2';
    }

    /**
     * Obtiene el desglose de IVA de las líneas de la factura.
     *
     * @param Facture $facture  Con las líneas ya cargadas (fetch_lines)
     * @return array[] Lista de ['tasa'=>float, 'base'=>float, 'cuota'=>float]
     */
    public static function desgloseIva(Facture $facture)
    {
        if (empty($facture->lines)) {
            $facture->fetch_lines();
        }

        $grupos = [];
        foreach ($facture->lines as $line) {
            $tasa = (float) $line->tva_tx;
            $key  = number_format($tasa, 2, '.', '');

            if (!isset($grupos[$key])) {
                $grupos[$key] = ['tasa' => $tasa, 'base' => 0.0, 'cuota' => 0.0];
            }
            $grupos[$key]['base']  += (float) $line->total_ht;
            $grupos[$key]['cuota'] += (float) $line->total_tva;
        }

        return array_values($grupos);
    }

    /**
     * Validación diaria de la cadena de Huellas (cron).
     */
    public static function cronDailyValidation()
    {
        global $db, $conf;

        require_once __DIR__.'/../class/VeriFactuChainValidator.class.php';
        require_once DOL_DOCUMENT_ROOT.'/core/class/CMailFile.class.php';

        $validator  = new VeriFactuChainValidator($db);
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

        $emailTo = $conf->global->VERIFACTU_ALERT_EMAIL
            ?? $conf->global->MAIN_INFO_SOCIETE_MAIL;

        if (!empty($emailTo)) {
            $mail = new CMailFile(
                $subject,
                $emailTo,
                $conf->global->MAIN_INFO_SOCIETE_NOM,
                $message
            );
            $mail->sendfile();
        }

        dol_syslog('VeriFactu ERROR: aviso enviado', LOG_WARNING);
        return 0;
    }

    // -------------------------------------------------------------------------

    /**
     * Formatea un timestamp Unix a ISO 8601 con desplazamiento de zona horaria.
     * Ejemplo: 2024-04-29T16:32:45+02:00
     */
    private static function isoWithOffset($ts)
    {
        $tz = date_default_timezone_get() ?: 'UTC';
        return (new DateTime('@' . $ts))
            ->setTimezone(new DateTimeZone($tz))
            ->format('Y-m-d\TH:i:sP');
    }
}
