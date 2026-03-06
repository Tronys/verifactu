<?php
/**
 * VeriFactuTSA.class.php
 *
 * Gestión de sellado de tiempo (XAdES-T) para VeriFactu.
 * Compatible con RFC 3161.
 */

class VeriFactuTSA
{
    /** @var bool */
    protected $enabled = false;

    /** @var string */
    protected $url;

    /** @var string */
    protected $user;

    /** @var string */
    protected $password;

    public function __construct()
    {
        global $conf;

        $this->url      = $conf->global->VERIFACTU_TSA_URL ?? '';
        $this->user     = $conf->global->VERIFACTU_TSA_USER ?? '';
        $this->password = $conf->global->VERIFACTU_TSA_PASSWORD ?? '';

        $this->enabled = !empty($this->url);
    }

    /**
     * Indica si el TSA está configurado
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Solicita un sello de tiempo RFC3161.
     *
     * @param string $hashBase64 Hash SHA256 en base64
     * @return string|null Token TSA en base64 o null si falla
     */
    public function getTimestampToken(string $hashBase64): ?string
    {
        if (!$this->enabled) {
            return null;
        }

        // ⚠️ IMPLEMENTACIÓN REAL PENDIENTE (fase siguiente)
        // Por ahora:
        // - No rompe la firma
        // - Permite continuar con XAdES-BES
        // - Queda preparada para TSA real

        dol_syslog(
            __METHOD__ . ' TSA configurado pero token no generado (stub)',
            LOG_WARNING
        );

        return null;
    }
}