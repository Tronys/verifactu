<?php
/**
 * Firma XAdES-BES para XML VeriFactu
 */

if (!defined('DOL_DOCUMENT_ROOT')) {
    die('Direct access not allowed');
}

class VerifactuSigner
{
    protected string $certPath;
    protected string $certPassword;

    public function __construct(string $certPath, string $certPassword)
    {
        $this->certPath = $certPath;
        $this->certPassword = $certPassword;
    }

    /**
     * Firma un XML VeriFactu (XAdES-BES)
     */
    public function sign(string $xmlPath): string
    {
        if (!file_exists($xmlPath)) {
            throw new Exception('XML no encontrado');
        }

        $xml = file_get_contents($xmlPath);

        // Cargar certificado
        $pkcs12 = file_get_contents($this->certPath);
        if (!openssl_pkcs12_read($pkcs12, $certs, $this->certPassword)) {
            throw new Exception('No se pudo leer el certificado');
        }

        $privateKey = $certs['pkey'];
        $publicCert = $certs['cert'];

        // Cargar XML
        $dom = new DOMDocument();
        $dom->loadXML($xml);

        // Crear firma XML (simplificada BES)
        $signature = $dom->createElement('ds:Signature');
        $signature->setAttribute('xmlns:ds', 'http://www.w3.org/2000/09/xmldsig#');

        // Digest
        $canonical = $dom->C14N();
        $digestValue = base64_encode(hash('sha256', $canonical, true));

        $signedInfo = $dom->createElement('ds:SignedInfo');
        $signedInfo->appendChild(
            $dom->createElement('ds:CanonicalizationMethod')
        );
        $signedInfo->appendChild(
            $dom->createElement('ds:SignatureMethod', 'http://www.w3.org/2001/04/xmldsig-more#rsa-sha256')
        );

        $reference = $dom->createElement('ds:Reference');
        $reference->appendChild(
            $dom->createElement('ds:DigestMethod', 'http://www.w3.org/2001/04/xmlenc#sha256')
        );
        $reference->appendChild(
            $dom->createElement('ds:DigestValue', $digestValue)
        );

        $signedInfo->appendChild($reference);
        $signature->appendChild($signedInfo);

        // Firmar SignedInfo
        openssl_sign($signedInfo->C14N(), $rawSignature, $privateKey, OPENSSL_ALGO_SHA256);
        $signatureValue = base64_encode($rawSignature);

        $signature->appendChild(
            $dom->createElement('ds:SignatureValue', $signatureValue)
        );

        // Certificado
        $keyInfo = $dom->createElement('ds:KeyInfo');
        $x509Data = $dom->createElement('ds:X509Data');
        $x509Data->appendChild(
            $dom->createElement('ds:X509Certificate', base64_encode(str_replace(
                ["-----BEGIN CERTIFICATE-----", "-----END CERTIFICATE-----", "\n"],
                '',
                $publicCert
            )))
        );
        $keyInfo->appendChild($x509Data);
        $signature->appendChild($keyInfo);

        $dom->documentElement->appendChild($signature);

        // Guardar XML firmado
        $signedPath = str_replace('.xml', '_signed.xml', $xmlPath);
        $dom->save($signedPath);

        return $signedPath;
    }
}