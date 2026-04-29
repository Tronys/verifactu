<?php
/**
 * VeriFactuXAdES.class.php
 * Firma XAdES para VeriFactu
 */

require_once DOL_DOCUMENT_ROOT . '/core/lib/date.lib.php';

// ✅ RUTAS ABSOLUTAS CORRECTAS EN DOLIBARR
require_once __DIR__ . '/../../class/VeriFactuHash.class.php';
require_once __DIR__ . '/../../aeat/tsa/VeriFactuTSA.class.php';

class VeriFactuXAdES
{
    /** @var DoliDB */
    public $db;

    /** @var VeriFactuHash */
    protected $hasher;

    /** @var VeriFactuTSA */
    protected $tsa;

    public function __construct($db)
    {
        $this->db     = $db;
        $this->hasher = new VeriFactuHash($db);
        $this->tsa    = new VeriFactuTSA($db);
    }

    /**
     * Genera el XML firmado XAdES (BES + opcional T).
     *
     * @param string $xmlString XML base (sin firma).
     * @param array  $certData  ['pfx_file' => ruta, 'pfx_pass' => pass].
     * @param bool   $withTimestamp true para intentar XAdES-T (si TSA está disponible).
     * @return string XML firmado (cadena).
     * @throws Exception
     */
    public function sign($xmlString, array $certData, $withTimestamp = true)
    {
        $dom = new DOMDocument('1.0', 'UTF-8');
        $dom->preserveWhiteSpace = false;
        $dom->formatOutput = false;

        if (!$dom->loadXML($xmlString)) {
            throw new Exception('No se pudo cargar el XML base en DOMDocument');
        }

        // 1) Crear estructura ds:Signature + SignedInfo + Reference, etc.
        $signatureNode = $this->buildSignatureStructure($dom);

        // 2) Calcular digest del documento para la Reference principal (URI="")
        $this->updateDocumentDigest($dom, $signatureNode);

        // 3) Calcular SignatureValue inicial
        $this->applySignatureValue($dom, $signatureNode, $certData);

        // 4) Añadir XAdES-BES (SignedProperties, SigningTime, SigningCertificate, etc.)
        $this->addXAdESBES($dom, $signatureNode, $certData);

        // 5) Volver a calcular digest del documento (no estrictamente necesario,
        //    pero lo dejamos por claridad)
        $this->updateDocumentDigest($dom, $signatureNode);

        // 6) Recalcular SignatureValue tras XAdES-BES
        $this->applySignatureValue($dom, $signatureNode, $certData);

        // 7) XAdES-T: sello de tiempo de la firma (no se recalcula SignatureValue después)
        if ($withTimestamp && $this->tsa->isEnabled()) {
            $this->addXAdEST($dom, $signatureNode);
        } else {
            dol_syslog(__METHOD__ . ' XAdES-T omitido (TSA no configurada o parámetro withTimestamp=false)', LOG_DEBUG);
        }

        return $dom->saveXML();
    }

    /**
     * Construye el nodo <ds:Signature> con su estructura básica.
     *
     * @param DOMDocument $dom
     * @return DOMElement
     */
   protected function buildSignatureStructure(DOMDocument $dom)
{
    $dsNS = 'http://www.w3.org/2000/09/xmldsig#';
    $root = $dom->documentElement;

    // ds:Signature
    $sig = $dom->createElementNS($dsNS, 'ds:Signature');
    $sig->setAttribute('Id', 'Signature-VeriFactu-' . uniqid());
    $root->appendChild($sig);

    // ds:SignedInfo
    $signedInfo = $dom->createElementNS($dsNS, 'ds:SignedInfo');
    $sig->appendChild($signedInfo);

    // ds:CanonicalizationMethod
    $canonMethod = $dom->createElementNS($dsNS, 'ds:CanonicalizationMethod');
    $canonMethod->setAttribute(
        'Algorithm',
        'http://www.w3.org/TR/2001/REC-xml-c14n-20010315'
    );
    $signedInfo->appendChild($canonMethod);

    // ds:SignatureMethod
    $sigMethod = $dom->createElementNS($dsNS, 'ds:SignatureMethod');
    $sigMethod->setAttribute(
        'Algorithm',
        'http://www.w3.org/2001/04/xmldsig-more#rsa-sha256'
    );
    $signedInfo->appendChild($sigMethod);

    // ✅ ds:Reference al documento
    $ref = $dom->createElementNS($dsNS, 'ds:Reference');
    $ref->setAttribute('URI', '');
    $signedInfo->appendChild($ref);

    // ds:Transforms
    $transforms = $dom->createElementNS($dsNS, 'ds:Transforms');
    $ref->appendChild($transforms);

    // enveloped-signature
    $tEnv = $dom->createElementNS($dsNS, 'ds:Transform');
    $tEnv->setAttribute(
        'Algorithm',
        'http://www.w3.org/2000/09/xmldsig#enveloped-signature'
    );
    $transforms->appendChild($tEnv);

    // c14n
    $tC14n = $dom->createElementNS($dsNS, 'ds:Transform');
    $tC14n->setAttribute(
        'Algorithm',
        'http://www.w3.org/TR/2001/REC-xml-c14n-20010315'
    );
    $transforms->appendChild($tC14n);

    // ds:DigestMethod
    $digestMethod = $dom->createElementNS($dsNS, 'ds:DigestMethod');
    $digestMethod->setAttribute(
        'Algorithm',
        'http://www.w3.org/2001/04/xmlenc#sha256'
    );
    $ref->appendChild($digestMethod);

    // ds:DigestValue (vacío, se rellena luego)
    $digestValue = $dom->createElementNS($dsNS, 'ds:DigestValue');
    $ref->appendChild($digestValue);

    // ds:SignatureValue
    $sigValue = $dom->createElementNS($dsNS, 'ds:SignatureValue');
    $sig->appendChild($sigValue);

    // ds:KeyInfo
    $keyInfo = $dom->createElementNS($dsNS, 'ds:KeyInfo');
    $sig->appendChild($keyInfo);

    return $sig;
}


    /**
     * Calcula el DigestValue de la referencia al documento.
     * Se aplica el transform "enveloped-signature" eliminando ds:Signature.
     *
     * @param DOMDocument $dom
     * @param DOMElement  $signatureNode
     * @return void
     */
protected function updateDocumentDigest(DOMDocument $dom, DOMElement $signatureNode)
{
    $xp = new DOMXPath($dom);
    $xp->registerNamespace('ds', 'http://www.w3.org/2000/09/xmldsig#');

    // Buscar SOLO la Reference con URI=""
    $refList = $xp->query(
        './ds:SignedInfo/ds:Reference[@URI=""]',
        $signatureNode
    );

    if ($refList->length === 0) {
        throw new Exception('No se encontró ds:Reference sobre el documento (URI="")');
    }

    /** @var DOMElement $ref */
    $ref = $refList->item(0);

    // --- Eliminamos ds:Signature para aplicar enveloped-signature ---
    $clone = new DOMDocument('1.0', 'UTF-8');
    $clone->loadXML($dom->saveXML());

    $xpClone = new DOMXPath($clone);
    $xpClone->registerNamespace('ds', 'http://www.w3.org/2000/09/xmldsig#');

    foreach ($xpClone->query('//ds:Signature') as $sigNode) {
        $sigNode->parentNode->removeChild($sigNode);
    }

    // Canonicalización
    $canonical = $clone->documentElement->C14N(false, false);
    $digestBin = hash('sha256', $canonical, true);
    $digestB64 = base64_encode($digestBin);

    // Escribir DigestValue
    $digestValue = $ref->getElementsByTagName('DigestValue')->item(0);

    while ($digestValue->firstChild) {
        $digestValue->removeChild($digestValue->firstChild);
    }

    $digestValue->appendChild($dom->createTextNode($digestB64));
}


    /**
     * Aplica SignatureValue usando el certificado (RSA-SHA256).
     *
     * @param DOMDocument $dom
     * @param DOMElement  $signatureNode
     * @param array       $certData
     * @return void
     * @throws Exception
     */
    protected function applySignatureValue(DOMDocument $dom, DOMElement $signatureNode, array $certData)
    {
        // Localizar SignedInfo
        $signedInfoNodeList = $signatureNode->getElementsByTagName('SignedInfo');
        if ($signedInfoNodeList->length === 0) {
            throw new Exception('No se encontró SignedInfo en la firma');
        }

        /** @var DOMElement $signedInfo */
        $signedInfo = $signedInfoNodeList->item(0);

        // Canonicalizar SignedInfo (inclusive c14n, sin comentarios)
        $signedInfoC14n = $signedInfo->C14N(false, false);

        // Cargar certificado y clave privada desde PFX
        if (empty($certData['pfx_file']) || empty($certData['pfx_pass'])) {
            throw new Exception('Datos de certificado incompletos');
        }

        $pfxContent = @file_get_contents($certData['pfx_file']);
        if ($pfxContent === false) {
            throw new Exception('No se pudo leer el fichero PFX: ' . $certData['pfx_file']);
        }

        $certs = [];
        if (!openssl_pkcs12_read($pfxContent, $certs, $certData['pfx_pass'])) {
            throw new Exception('No se pudo leer el contenido del PFX (password incorrecta o fichero corrupto)');
        }

        $privKey = openssl_pkey_get_private($certs['pkey']);
        if (!$privKey) {
            throw new Exception('No se pudo obtener la clave privada del PFX');
        }

        $signature = '';
        if (!openssl_sign($signedInfoC14n, $signature, $privKey, OPENSSL_ALGO_SHA256)) {
            openssl_pkey_free($privKey);
            throw new Exception('Error al firmar SignedInfo con OpenSSL');
        }

        openssl_pkey_free($privKey);

        // Colocar SignatureValue (base64) en el DOM
        $sigValueNodeList = $signatureNode->getElementsByTagName('SignatureValue');
        if ($sigValueNodeList->length === 0) {
            throw new Exception('No se encontró SignatureValue en la firma');
        }

        /** @var DOMElement $sigValueNode */
        $sigValueNode = $sigValueNodeList->item(0);
        while ($sigValueNode->firstChild) {
            $sigValueNode->removeChild($sigValueNode->firstChild);
        }
        $sigValueNode->appendChild($dom->createTextNode(base64_encode($signature)));

        // KeyInfo: embebemos el certificado X509
        $this->fillKeyInfo($dom, $signatureNode, $certs['cert']);
    }

    /**
     * Rellena <ds:KeyInfo> con el certificado X509.
     *
     * @param DOMDocument $dom
     * @param DOMElement  $signatureNode
     * @param string      $certPem
     * @return void
     */
    protected function fillKeyInfo(DOMDocument $dom, DOMElement $signatureNode, $certPem)
    {
        $keyInfoList = $signatureNode->getElementsByTagName('KeyInfo');
        if ($keyInfoList->length === 0) {
            $keyInfo = $dom->createElement('ds:KeyInfo');
            $signatureNode->appendChild($keyInfo);
        } else {
            $keyInfo = $keyInfoList->item(0);
        }

        // Limpiar KeyInfo
        while ($keyInfo->firstChild) {
            $keyInfo->removeChild($keyInfo->firstChild);
        }

        $x509Data = $dom->createElement('ds:X509Data');
        $x509Cert = $dom->createElement('ds:X509Certificate');

        // Quitar cabeceras/footers PEM y espacios
        $cleanCert = preg_replace('/-----BEGIN CERTIFICATE-----/', '', $certPem);
        $cleanCert = preg_replace('/-----END CERTIFICATE-----/', '', $cleanCert);
        $cleanCert = preg_replace('/\s+/', '', $cleanCert);

        $x509Cert->appendChild($dom->createTextNode($cleanCert));
        $x509Data->appendChild($x509Cert);
        $keyInfo->appendChild($x509Data);
    }

    /**
     * Añade las propiedades XAdES-BES (SignedProperties, SigningTime, SigningCertificate, etc.)
     * y la referencia ds:Reference hacia SignedProperties en SignedInfo.
     *
     * @param DOMDocument $dom
     * @param DOMElement  $signatureNode
     * @param array       $certData
     * @return void
     * @throws Exception
     */
    protected function addXAdESBES(DOMDocument $dom, DOMElement $signatureNode, array $certData)
    {
        $xadesNS = 'http://uri.etsi.org/01903/v1.3.2#';
        $dsNS    = 'http://www.w3.org/2000/09/xmldsig#';

        // Nodo QualifyingProperties
        $qualifyingProps = $dom->createElementNS($xadesNS, 'xades:QualifyingProperties');
        $qualifyingProps->setAttribute('Target', '#' . $signatureNode->getAttribute('Id'));
        $signatureNode->appendChild($qualifyingProps);

        // SignedProperties
        $signedPropsId = 'SignedProperties-' . $signatureNode->getAttribute('Id');
        $signedProps   = $dom->createElement('xades:SignedProperties');
        $signedProps->setAttribute('Id', $signedPropsId);
        $qualifyingProps->appendChild($signedProps);

        // SignedSignatureProperties
        $signedSignatureProps = $dom->createElement('xades:SignedSignatureProperties');
        $signedProps->appendChild($signedSignatureProps);

        // SigningTime
        $signingTime = $dom->createElement('xades:SigningTime', dol_print_date(dol_now(), 'dayhourrfc'));
        $signedSignatureProps->appendChild($signingTime);

        // Volvemos a leer el PFX para obtener el cert (si no quieres, puedes pasarlo desde applySignatureValue)
        if (empty($certData['pfx_file']) || empty($certData['pfx_pass'])) {
            throw new Exception('Datos de certificado incompletos en addXAdESBES');
        }

        $pfxContent = @file_get_contents($certData['pfx_file']);
        if ($pfxContent === false) {
            throw new Exception('No se pudo leer el fichero PFX en addXAdESBES: ' . $certData['pfx_file']);
        }

        $certs = [];
        if (!openssl_pkcs12_read($pfxContent, $certs, $certData['pfx_pass'])) {
            throw new Exception('No se pudo leer el contenido del PFX en addXAdESBES');
        }

        $certPem = $certs['cert'];

        // SigningCertificate
        $signingCertificate = $dom->createElement('xades:SigningCertificate');
        $signedSignatureProps->appendChild($signingCertificate);

        $cert = $dom->createElement('xades:Cert');
        $signingCertificate->appendChild($cert);

        // CertDigest
        $certDigest = $dom->createElement('xades:CertDigest');
        $cert->appendChild($certDigest);

        $digestMethod = $dom->createElement('ds:DigestMethod');
        $digestMethod->setAttribute('Algorithm', 'http://www.w3.org/2001/04/xmlenc#sha256');
        $certDigest->appendChild($digestMethod);

        $digestValue = $dom->createElement('ds:DigestValue', $this->computeCertDigestBase64($certPem));
        $certDigest->appendChild($digestValue);

        // IssuerSerial
        $issuerSerial = $dom->createElement('xades:IssuerSerial');
        $cert->appendChild($issuerSerial);

        $parsed = @openssl_x509_parse($certPem);
        $issuerNameStr = '';
        $serialNumber  = '';

        if ($parsed && !empty($parsed['issuer'])) {
            if (!empty($parsed['name'])) {
                // Cadena tipo /C=ES/O=.../CN=...
                $issuerNameStr = $parsed['name'];
            } else {
                // Construimos algo sencillo a partir del array issuer
                $parts = [];
                foreach ($parsed['issuer'] as $k => $v) {
                    $parts[] = $k . '=' . $v;
                }
                $issuerNameStr = implode(',', $parts);
            }
        }
        if ($parsed && !empty($parsed['serialNumber'])) {
            $serialNumber = $parsed['serialNumber'];
        }

        $x509IssuerName   = $dom->createElement('ds:X509IssuerName', $issuerNameStr);
        $x509SerialNumber = $dom->createElement('ds:X509SerialNumber', $serialNumber);
        $issuerSerial->appendChild($x509IssuerName);
        $issuerSerial->appendChild($x509SerialNumber);

        // --------------------------------------------------
        // SignaturePolicyIdentifier (opcional)
        // --------------------------------------------------
        // Puedes controlarlo desde la configuración global de Dolibarr:
        // VERIFACTU_POLICY_OID, VERIFACTU_POLICY_HASH, VERIFACTU_POLICY_DESC
        global $conf;

        $policyOid  = !empty($conf->global->VERIFACTU_POLICY_OID)  ? $conf->global->VERIFACTU_POLICY_OID  : '';
        $policyHash = !empty($conf->global->VERIFACTU_POLICY_HASH) ? $conf->global->VERIFACTU_POLICY_HASH : '';
        $policyDesc = !empty($conf->global->VERIFACTU_POLICY_DESC) ? $conf->global->VERIFACTU_POLICY_DESC : '';

        if ($policyOid !== '' && $policyHash !== '') {
            $sigPolicyIdentifier = $dom->createElement('xades:SignaturePolicyIdentifier');
            $signedSignatureProps->appendChild($sigPolicyIdentifier);

            $sigPolicyId = $dom->createElement('xades:SignaturePolicyId');
            $sigPolicyIdentifier->appendChild($sigPolicyId);

            $sigPolicyIdId = $dom->createElement('xades:SigPolicyId');
            $sigPolicyId->appendChild($sigPolicyIdId);

            $identifier = $dom->createElement('xades:Identifier', $policyOid);
            $sigPolicyIdId->appendChild($identifier);

            if ($policyDesc !== '') {
                $description = $dom->createElement('xades:Description', $policyDesc);
                $sigPolicyIdId->appendChild($description);
            }

            $sigPolicyHash = $dom->createElement('xades:SigPolicyHash');
            $sigPolicyId->appendChild($sigPolicyHash);

            $spDigestMethod = $dom->createElement('ds:DigestMethod');
            $spDigestMethod->setAttribute('Algorithm', 'http://www.w3.org/2001/04/xmlenc#sha256');
            $sigPolicyHash->appendChild($spDigestMethod);

            $spDigestValue = $dom->createElement('ds:DigestValue', $policyHash);
            $sigPolicyHash->appendChild($spDigestValue);
        }

        // --------------------------------------------------
        // 🔗 Referencia ds:Reference a SignedProperties
        // --------------------------------------------------
        // Muchos validadores XAdES exigen una referencia explícita a
        // #SignedProperties-<SignatureId> con Type="...#SignedProperties"

        $signedInfoList = $signatureNode->getElementsByTagName('SignedInfo');
        if ($signedInfoList->length === 0) {
            throw new Exception('No se encontró SignedInfo al añadir referencia a SignedProperties');
        }

        /** @var DOMElement $signedInfo */
        $signedInfo = $signedInfoList->item(0);

        // Crear Reference a SignedProperties
        $refSp = $dom->createElement('ds:Reference');
        $refSp->setAttribute('URI', '#' . $signedPropsId);
        $refSp->setAttribute('Type', 'http://uri.etsi.org/01903#SignedProperties');
        $signedInfo->appendChild($refSp);

        // Transforms -> solo canonicalización
        $transformsSp = $dom->createElement('ds:Transforms');
        $refSp->appendChild($transformsSp);

        $tC14nSp = $dom->createElement('ds:Transform');
        $tC14nSp->setAttribute('Algorithm', 'http://www.w3.org/TR/2001/REC-xml-c14n-20010315');
        $transformsSp->appendChild($tC14nSp);

        // DigestMethod + DigestValue para SignedProperties
        $digestMethodSp = $dom->createElement('ds:DigestMethod');
        $digestMethodSp->setAttribute('Algorithm', 'http://www.w3.org/2001/04/xmlenc#sha256');
        $refSp->appendChild($digestMethodSp);

        $digestValueSp = $dom->createElement('ds:DigestValue', '');
        $refSp->appendChild($digestValueSp);

        // Calcular digest de SignedProperties (c14n inclusive, sin comentarios)
        $canonicalSp = $signedProps->C14N(false, false);
        $digestBinSp = hash('sha256', $canonicalSp, true);
        $digestB64Sp = base64_encode($digestBinSp);

        // Escribir DigestValue en la Reference recién creada
        while ($digestValueSp->firstChild) {
            $digestValueSp->removeChild($digestValueSp->firstChild);
        }
        $digestValueSp->appendChild($dom->createTextNode($digestB64Sp));
    }

    /**
     * Devuelve el hash SHA-256 (base64) del certificado (sobre el DER).
     *
     * @param string $certPem
     * @return string
     */
    protected function computeCertDigestBase64($certPem)
    {
        $cleanCert = preg_replace('/-----BEGIN CERTIFICATE-----/', '', $certPem);
        $cleanCert = preg_replace('/-----END CERTIFICATE-----/', '', $certPem);
        $cleanCert = preg_replace('/\s+/', '', $certPem);

        $der = base64_decode($cleanCert);
        $digestBin = hash('sha256', $der, true);

        return base64_encode($digestBin);
    }

    /**
     * Añade XAdES-T: SignatureTimeStamp (UnsignedSignatureProperties).
     *
     * @param DOMDocument $dom
     * @param DOMElement  $signatureNode
     * @return void
     */
    protected function addXAdEST(DOMDocument $dom, DOMElement $signatureNode)
    {
        $xadesNS = 'http://uri.etsi.org/01903/v1.3.2#';

        // 1) Localizar o crear QualifyingProperties
        $qualifyingProps = null;
        foreach ($signatureNode->childNodes as $child) {
            if ($child instanceof DOMElement && $child->localName === 'QualifyingProperties') {
                $qualifyingProps = $child;
                break;
            }
        }
        if (!$qualifyingProps) {
            $qualifyingProps = $dom->createElementNS($xadesNS, 'xades:QualifyingProperties');
            $qualifyingProps->setAttribute('Target', '#' . $signatureNode->getAttribute('Id'));
            $signatureNode->appendChild($qualifyingProps);
        }

        // 2) UnsignedProperties
        $unsignedProps = null;
        foreach ($qualifyingProps->childNodes as $child) {
            if ($child instanceof DOMElement && $child->localName === 'UnsignedProperties') {
                $unsignedProps = $child;
                break;
            }
        }
        if (!$unsignedProps) {
            $unsignedProps = $dom->createElement('xades:UnsignedProperties');
            $qualifyingProps->appendChild($unsignedProps);
        }

        // 3) UnsignedSignatureProperties
        $unsignedSigProps = null;
        foreach ($unsignedProps->childNodes as $child) {
            if ($child instanceof DOMElement && $child->localName === 'UnsignedSignatureProperties') {
                $unsignedSigProps = $child;
                break;
            }
        }
        if (!$unsignedSigProps) {
            $unsignedSigProps = $dom->createElement('xades:UnsignedSignatureProperties');
            $unsignedProps->appendChild($unsignedSigProps);
        }

        // 4) Calcular hash de la firma (nodo ds:Signature) para la TSA
        $hashBase64 = $this->computeSignatureHashBase64($dom, $signatureNode);

        // 5) Pedir token de sello de tiempo a la TSA
        $tokenBase64 = $this->tsa->getTimestampToken($hashBase64);

        if (empty($tokenBase64)) {
            dol_syslog(__METHOD__ . ' No se pudo obtener token TSA. Se mantiene XAdES-BES sin T.', LOG_WARNING);
            return;
        }

        // 6) Montar SignatureTimeStamp
        $sigTimeStamp = $dom->createElement('xades:SignatureTimeStamp');
        $unsignedSigProps->appendChild($sigTimeStamp);

        $encapsulatedTS = $dom->createElement('xades:EncapsulatedTimeStamp', $tokenBase64);
        $sigTimeStamp->appendChild($encapsulatedTS);
    }

    /**
     * Calcula el hash base64 del nodo <ds:Signature> (para TSA).
     *
     * @param DOMDocument $dom
     * @param DOMElement  $signatureNode
     * @return string
     */
    protected function computeSignatureHashBase64(DOMDocument $dom, DOMElement $signatureNode)
    {
        // Canonicalizar solo el nodo Signature (inclusive c14n, sin comentarios)
        $c14n = $signatureNode->C14N(false, false);
        $digestBin = hash('sha256', $c14n, true);

        return base64_encode($digestBin);
    }
}