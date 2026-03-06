<?php
defined('DOL_DOCUMENT_ROOT') or die();

/**
 * Cliente SOAP para envío VeriFactu a la AEAT
 */
class VeriFactuAeatClient
{
    private $client;

    public function __construct()
    {
        global $conf;

        $wsdl = $conf->global->VERIFACTU_AEAT_WSDL;
        $cert = $conf->global->VERIFACTU_AEAT_PFX_PATH;
        $pass = $conf->global->VERIFACTU_AEAT_PFX_PASSWORD;

        if (!$wsdl || !$cert) {
            throw new Exception('Configuración AEAT incompleta');
        }

        $this->client = new SoapClient($wsdl, [
            'trace' => true,
            'exceptions' => true,
            'local_cert' => $cert,
            'passphrase' => $pass,
            'cache_wsdl' => WSDL_CACHE_NONE,
            'stream_context' => stream_context_create([
                'ssl' => [
                    'verify_peer' => true,
                    'verify_peer_name' => true,
                ]
            ])
        ]);
    }

    /**
     * Envía un XML VeriFactu ya firmado
     */
    public function sendXml(string $xml): array
    {
        try {
            // ⚠️ El nodo depende del WSDL real (ejemplo genérico)
            $params = [
                'XML' => $xml
            ];

            $response = $this->client->__soapCall('EnvioVeriFactu', [$params]);

            return [
                'ok'       => true,
                'response' => $response,
                'raw'      => $this->client->__getLastResponse()
            ];

        } catch (SoapFault $e) {
            return [
                'ok'    => false,
                'error' => $e->getMessage(),
                'raw'   => $this->client->__getLastResponse()
            ];
        }
    }
}
