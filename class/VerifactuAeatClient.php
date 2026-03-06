<?php
/**
 * Cliente AEAT para envío VeriFactu
 */

class VerifactuAeatClient
{
    private $db;
    private $endpoint;

    public function __construct($db)
    {
        global $conf;

        $this->db = $db;

        // Endpoint real o futuro (placeholder)
        $this->endpoint = $conf->global->VERIFACTU_AEAT_ENDPOINT
            ?? 'https://prewww2.aeat.es/verifactu'; // ⚠️ placeholder
    }

    /**
     * Enviar payload a AEAT
     *
     * @param array $payload
     * @return array
     * @throws Exception
     */
    public function send(array $payload): array
    {
        // ---------------------------------------------
        // SIMULACIÓN CONTROLADA (por ahora)
        // ---------------------------------------------
        // Cuando AEAT publique el endpoint real,
        // aquí se implementa el curl real.

        dol_syslog(
            'VeriFactu AEAT SEND (simulado): '.json_encode($payload),
            LOG_DEBUG
        );

        // Simular éxito
        return [
            'status' => 'ACCEPTED',
            'csv'    => 'CSV'.time().rand(1000,9999),
        ];
    }
}
