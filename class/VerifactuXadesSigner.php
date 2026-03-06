<?php
/**
 * Firma XAdES-BES y XAdES-T (timestamp TSA) para VeriFactu
 */

class VerifactuXadesSigner
{
    /**
     * =====================================================
     * FIRMA XAdES-BES
     * =====================================================
     *
     * @param string $xmlInput  XML original
     * @param string $pfxPath   Ruta certificado PFX
     * @param string $pfxPass   Password PFX
     * @return string XML firmado
     * @throws Exception
     */
    public static function sign($xmlInput, $pfxPath, $pfxPass)
    {
        if (!file_exists($pfxPath)) {
            throw new Exception('Certificado PFX no encontrado');
        }

        if (!openssl_pkcs12_read(file_get_contents($pfxPath), $certs, $pfxPass)) {
            throw new Exception('No se pudo leer el certificado PFX');
        }

        $privateKey = $certs['pkey'];
        $publicCert = $certs['cert'];

        $doc = new DOMDocument();
        $doc->preserveWhiteSpace = false;
        $doc->formatOutput = false;
        $doc->loadXML($xmlInput);

        // ================================
        // Crear nodo Signature
        // ================================
        $sigNode = $doc->createElementNS(
            'http://www.w3.org/2000/09/xmldsig#',
            'ds:Signature'
        );
        $doc->documentElement->appendChild($sigNode);

        // SignedInfo
        $signedInfo = $doc->createElement('ds:SignedInfo');
        $sigNode->appendChild($signedInfo);

        // Canonicalization
        $signedInfo->appendChild(
            $doc->createElement('ds:CanonicalizationMethod')
        )->setAttribute(
            'Algorithm',
            'http://www.w3.org/TR/2001/REC-xml-c14n-20010315'
        );

        // SignatureMethod
        $signedInfo->appendChild(
            $doc->createElement('ds:SignatureMethod')
        )->setAttribute(
            'Algorithm',
            'http://www.w3.org/2001/04/xmldsig-more#rsa-sha256'
        );

        // Reference
        $reference = $doc->createElement('ds:Reference');
        $reference->setAttribute('URI', '');
        $signedInfo->appendChild($reference);

        // Transforms
        $transforms = $doc->createElement('ds:Transforms');
        $reference->appendChild($transforms);

        $transforms->appendChild(
            $doc->createElement('ds:Transform')
        )->setAttribute(
            'Algorithm',
            'http://www.w3.org/2000/09/xmldsig#enveloped-signature'
        );

        // DigestMethod
        $reference->appendChild(
            $doc->createElement('ds:DigestMethod')
        )->setAttribute(
            'Algorithm',
            'http://www.w3.org/2001/04/xmlenc#sha256'
        );

        // DigestValue
        $canonical = $doc->C14N();
        $digestValue = base64_encode(hash('sha256', $canonical, true));
        $reference->appendChild(
            $doc->createElement('ds:DigestValue', $digestValue)
        );

        // ================================
        // Firmar SignedInfo
        // ================================
        $signedInfoC14n = $signedInfo->C14N();
        openssl_sign($signedInfoC14n, $signatureValue, $privateKey, OPENSSL_ALGO_SHA256);

        $sigNode->appendChild(
            $doc->createElement(
                'ds:SignatureValue',
                base64_encode($signatureValue)
            )
        );

        // ================================
        // KeyInfo
        // ================================
        $keyInfo = $doc->createElement('ds:KeyInfo');
        $sigNode->appendChild($keyInfo);

        $x509Data = $doc->createElement('ds:X509Data');
        $keyInfo->appendChild($x509Data);

        $certClean = str_replace(
            ["-----BEGIN CERTIFICATE-----", "-----END CERTIFICATE-----", "\n", "\r"],
            '',
            $publicCert
        );

        $x509Data->appendChild(
            $doc->createElement('ds:X509Certificate', $certClean)
        );

        return $doc->saveXML();
    }

    /**
     * =====================================================
     * AÑADIR TIMESTAMP TSA -> XAdES-T
     * =====================================================
     *
     * @param string $signedXml XML firmado XAdES-BES
     * @param string $tsaUrl    URL TSA RFC3161
     * @param string $tsaUser   Usuario TSA (opcional)
     * @param string $tsaPass   Password TSA (opcional)
     * @return string XML firmado XAdES-T
     * @throws Exception
     */
    public static function addXadesTimestamp(
        string $signedXml,
        string $tsaUrl,
        string $tsaUser = '',
        string $tsaPass = ''
    ): string {

        if (empty($tsaUrl)) {
            return $signedXml;
        }

        // Parse XML
        $dom = new DOMDocument();
        $dom->preserveWhiteSpace = false;
        $dom->formatOutput = false;

        if (!$dom->loadXML($signedXml)) {
            throw new Exception('XML firmado inválido');
        }

        $xp = new DOMXPath($dom);
        $xp->registerNamespace('ds', 'http://www.w3.org/2000/09/xmldsig#');
        $xp->registerNamespace('xades', 'http://uri.etsi.org/01903/v1.3.2#');

        // Tomar SignatureValue
        $sigValueNode = $xp->query('//ds:Signature/ds:SignatureValue')->item(0);
        if (!$sigValueNode) {
            throw new Exception('No se encontró ds:SignatureValue');
        }

        $signatureValueRaw = base64_decode(
            preg_replace('/\s+/', '', $sigValueNode->nodeValue),
            true
        );

        if ($signatureValueRaw === false) {
            throw new Exception('SignatureValue inválido');
        }

        // Crear TSQ
        $tsq = self::buildTsq($signatureValueRaw);

        // Llamar TSA
        $tsr = self::callTsa($tsaUrl, $tsq, $tsaUser, $tsaPass);

        // Extraer TimeStampToken (DER)
        $tokenDer = self::extractTokenFromTsr($tsr);

        // Inyectar en XML
        self::injectTimestamp($dom, $xp, $tokenDer);

        return $dom->saveXML();
    }

    /* =====================================================
     * HELPERS INTERNOS TSA
     * ===================================================== */

    private static function buildTsq(string $binary): string
    {
        $tmpData = tempnam(sys_get_temp_dir(), 'vf_ts_data_');
        $tmpTsq  = tempnam(sys_get_temp_dir(), 'vf_ts_q_');

        file_put_contents($tmpData, $binary);

        $cmd = 'openssl ts -query -data '
            .escapeshellarg($tmpData)
            .' -sha256 -cert -out '
            .escapeshellarg($tmpTsq).' 2>&1';

        exec($cmd, $out, $code);

        @unlink($tmpData);

        if ($code !== 0 || !file_exists($tmpTsq)) {
            @unlink($tmpTsq);
            throw new Exception('Error generando TSQ: '.implode("\n", $out));
        }

        $tsq = file_get_contents($tmpTsq);
        @unlink($tmpTsq);

        if (!$tsq) {
            throw new Exception('TSQ vacío');
        }

        return $tsq;
    }

    private static function callTsa(
        string $url,
        string $tsq,
        string $user = '',
        string $pass = ''
    ): string {

        $ch = curl_init($url);
        if (!$ch) {
            throw new Exception('No se pudo inicializar CURL');
        }

        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $tsq);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/timestamp-query',
            'Accept: application/timestamp-reply'
        ]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 25);

        if ($user !== '') {
            curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
            curl_setopt($ch, CURLOPT_USERPWD, $user.':'.$pass);
        }

        $resp = curl_exec($ch);
        $err  = curl_error($ch);
        $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        curl_close($ch);

        if ($resp === false) {
            throw new Exception('Error TSA: '.$err);
        }
        if ($http < 200 || $http >= 300) {
            throw new Exception('Respuesta TSA HTTP '.$http);
        }

        return $resp;
    }

    private static function extractTokenFromTsr(string $tsr): string
    {
        $tmpTsr   = tempnam(sys_get_temp_dir(), 'vf_tsr_');
        $tmpToken = tempnam(sys_get_temp_dir(), 'vf_tst_');

        file_put_contents($tmpTsr, $tsr);

        $cmd = 'openssl ts -reply -in '
            .escapeshellarg($tmpTsr)
            .' -token_out '
            .escapeshellarg($tmpToken).' 2>&1';

        exec($cmd, $out, $code);

        @unlink($tmpTsr);

        if ($code !== 0 || !file_exists($tmpToken)) {
            @unlink($tmpToken);
            throw new Exception('Error extrayendo TimeStampToken: '.implode("\n", $out));
        }

        $token = file_get_contents($tmpToken);
        @unlink($tmpToken);

        if (!$token) {
            throw new Exception('TimeStampToken vacío');
        }

        return $token;
    }

    private static function injectTimestamp(DOMDocument $dom, DOMXPath $xp, string $tokenDer): void
    {
        $qProps = $xp->query('//xades:QualifyingProperties')->item(0);
        if (!$qProps) {
            throw new Exception('No se encontró xades:QualifyingProperties');
        }

        $unsignedProps = $xp->query('.//xades:UnsignedProperties', $qProps)->item(0);
        if (!$unsignedProps) {
            $unsignedProps = $dom->createElementNS(
                'http://uri.etsi.org/01903/v1.3.2#',
                'xades:UnsignedProperties'
            );
            $qProps->appendChild($unsignedProps);
        }

        $unsignedSigProps = $xp->query('.//xades:UnsignedSignatureProperties', $unsignedProps)->item(0);
        if (!$unsignedSigProps) {
            $unsignedSigProps = $dom->createElementNS(
                'http://uri.etsi.org/01903/v1.3.2#',
                'xades:UnsignedSignatureProperties'
            );
            $unsignedProps->appendChild($unsignedSigProps);
        }

        // Evitar duplicados
        if ($xp->query('.//xades:SignatureTimeStamp', $unsignedSigProps)->length > 0) {
            return;
        }

        $sigTs = $dom->createElementNS(
            'http://uri.etsi.org/01903/v1.3.2#',
            'xades:SignatureTimeStamp'
        );

        $encTs = $dom->createElementNS(
            'http://uri.etsi.org/01903/v1.3.2#',
            'xades:EncapsulatedTimeStamp',
            base64_encode($tokenDer)
        );

        $sigTs->appendChild($encTs);
        $unsignedSigProps->appendChild($sigTs);
    }
}