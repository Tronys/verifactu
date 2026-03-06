<?php
/**
 * VeriFactuTSA.class.php
 *
 * Stub para gestión de sellado de tiempo (TSA) para XAdES-T.
 * De momento prepara la estructura y centraliza la futura llamada a la TSA.
 */

class VeriFactuTSA
{
    /** @var DoliDB */
    public $db;

    /** @var string|null URL de la TSA RFC3161 (cuando la tengas) */
    public $tsaUrl;

    /** @var string|null usuario TSA (opcional) */
    public $tsaUser;

    /** @var string|null password TSA (opcional) */
    public $tsaPass;

    /**
     * Constructor
     *
     * @param DoliDB $db
     */
    public function __construct($db)
    {
        $this->db = $db;

        // Más adelante se pueden cargar de conf global:
        // $this->tsaUrl  = !empty($conf->global->VERIFACTU_TSA_URL)  ? $conf->global->VERIFACTU_TSA_URL  : null;
        // $this->tsaUser = !empty($conf->global->VERIFACTU_TSA_USER) ? $conf->global->VERIFACTU_TSA_USER : null;
        // $this->tsaPass = !empty($conf->global->VERIFACTU_TSA_PASS) ? $conf->global->VERIFACTU_TSA_PASS : null;
    }

    /**
     * Indica si la TSA está mínimamente configurada.
     *
     * @return bool
     */
    public function isEnabled()
    {
        return !empty($this->tsaUrl);
    }

    /**
     * Obtener un token de sello de tiempo para un hash dado.
     *
     * IMPORTANTE:
     *  - Esto es un stub. Aquí irá en el futuro la llamada real RFC3161 a la TSA.
     *  - Por ahora, devolvemos null para no bloquear el flujo de desarrollo.
     *
     * @param string $hashBase64 Hash base64 (por ejemplo de la firma) que se enviará a la TSA.
     * @return string|null Token de sello de tiempo en base64 (o null si no se pudo obtener).
     */
    public function getTimestampToken($hashBase64)
    {
        // TODO: Implementar llamada real a la TSA (RFC3161) cuando se decida proveedor.
        // Aquí irá la construcción de la Time-Stamp Request (TSQ), envío por HTTP
        // y la lectura de la Time-Stamp Response (TSR), devolviendo el token en base64.

        dol_syslog(__METHOD__ . ' TSA aún no implementada. Hash recibido: ' . $hashBase64, LOG_DEBUG);

        // De momento, devolvemos null para que la lógica superior sepa que no hay token.
        return null;

        /*
        // Ejemplo de estructura futura (NO FUNCIONA, solo ilustrativo):

        if (empty($this->tsaUrl)) return null;

        $binaryTsq = $this->buildTsqFromHash($hashBase64); // construir TSQ binaria
        $ch = curl_init($this->tsaUrl);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/timestamp-query'));
        curl_setopt($ch, CURLOPT_POSTFIELDS, $binaryTsq);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        if (!empty($this->tsaUser)) {
            curl_setopt($ch, CURLOPT_USERPWD, $this->tsaUser . ':' . $this->tsaPass);
        }

        $binaryTsr = curl_exec($ch);
        $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($binaryTsr === false || $httpCode < 200 || $httpCode >= 300) {
            dol_syslog(__METHOD__ . ' Error obteniendo TSA. HTTP ' . $httpCode, LOG_ERR);
            return null;
        }

        return base64_encode($binaryTsr);
        */
    }
}
