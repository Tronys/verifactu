<?php
defined('DOL_DOCUMENT_ROOT') or die();

/**
 * Firma electrónica XAdES-BES para XML VeriFactu
 *
 * - Usa certificado software (.p12 / .pfx)
 * - Firma enveloped
 * - NO envía a AEAT
 */
class VeriFactuXAdES
{
    /**
     * Firma un XML con XAdES-BES
     *
     * @param string $xml          XML original
     * @param string $certPath     Ruta al certificado .p12/.pfx
     * @param string $certPassword Password del certificado
     * @return string XML firmado
     * @throws Exception
     */
    public static function sign($xml, $certPath, $certPassword)
    {
        if (!file_exists($certPath)) {
            throw new Exception('Certificado no encontrado');
        }

        // Cargar certificado
        $pkcs12 = file_get_contents($certPath);
        if (!openssl_pkcs12_read($pkcs12, $certs, $certPassword)) {
            throw new Exception('No se pudo leer el certificado');
        }

        // Cargar XML
        $doc = new DOMDocument();
        $doc->loadXML($xml, LIBXML_NOBLANKS);

        // Crear Signature
        $signature = new DOMElement('ds:Signature', null, 'http://www.w3.org/2000/09/xmldsig#');
        $signature = $doc->createElementNS(
            'http://www.w3.org/2000/09/xmldsig#',
            'ds:Signature'
        );

        // SignedInfo
        $signedInfo = $doc->createElement('ds:SignedInfo');

        $canonMethod = $doc->createElement(
            'ds:CanonicalizationMethod'
        );
        $canonMethod->setAttribute(
            'Algorithm',
            'http://www.w3.org/TR/2001/REC-xml-c14n-20010315'
        );

        $signMethod = $doc->createElement('ds:SignatureMethod');
        $signMethod->setAttribute(
            'Algorithm',
            'http://www.w3.org/2001/04/xmldsig-more#rsa-sha256'
        );

        $signedInfo->appendChild($canonMethod);
        $signedInfo->appendChild($signMethod);

        // Reference (todo el documento)
        $reference = $doc->createElement('ds:Reference');
        $reference->setAttribute('URI', '');

        $transforms = $doc->createElement('ds:Transforms');
        $transform = $doc->createElement('ds:Transform');
        $transform->setAttribute(
            'Algorithm',
            'http://www.w3.org/2000/09/xmldsig#enveloped-signature'
        );

        $transforms->appendChild($transform);
        $reference->appendChild($transforms);

        $digestMethod = $doc->createElement('ds:DigestMethod');
        $digestMethod->setAttribute(
            'Algorithm',
            'http://www.w3.org/2001/04/xmlenc#sha256'
        );

        $digestValue = $doc->createElement('ds:DigestValue');
        $digestValue->nodeValue = base64_encode(
            hash('sha256', $doc->C14N(), true)
        );

        $reference->appendChild($digestMethod);
        $reference->appendChild($digestValue);

        $signedInfo->appendChild($reference);
        $signature->appendChild($signedInfo);

        // Firmar SignedInfo
        openssl_sign(
            $signedInfo->C14N(),
            $signatureValue,
            $certs['pkey'],
            OPENSSL_ALGO_SHA256
        );

        $signatureValueNode = $doc->createElement(
            'ds:SignatureValue',
            base64_encode($signatureValue)
        );
        $signature->appendChild($signatureValueNode);

        // Certificado
        $keyInfo = $doc->createElement('ds:KeyInfo');
        $x509Data = $doc->createElement('ds:X509Data');
        $x509Cert = $doc->createElement(
            'ds:X509Certificate',
            str_replace(
                ["-----BEGIN CERTIFICATE-----", "-----END CERTIFICATE-----", "\n", "\r"],
                '',
                $certs['cert']
            )
        );

        $x509Data->appendChild($x509Cert);
        $keyInfo->appendChild($x509Data);
        $signature->appendChild($keyInfo);

        // Insertar firma en el XML
        $doc->documentElement->appendChild($signature);

        return $doc->saveXML();
    }
}
