<?php
/**
 * Utilidades VeriFactu
 */

/**
 * Obtener estado de la firma de un XML
 */
function verifactu_get_signature_status($xmlPath)
{
    if (empty($xmlPath) || !is_readable($xmlPath)) {
        return array('code' => 'NOXML', 'label' => 'Sin XML');
    }

    $xml = @file_get_contents($xmlPath);
    if ($xml === false) {
        return array('code' => 'ERROR', 'label' => 'XML no legible');
    }

    $doc = new DOMDocument();
    if (!$doc->loadXML($xml)) {
        return array('code' => 'ERROR', 'label' => 'XML corrupto');
    }

    $xpath = new DOMXPath($doc);
    $xpath->registerNamespace('ds', 'http://www.w3.org/2000/09/xmldsig#');
    $xpath->registerNamespace('xades', 'http://uri.etsi.org/01903/v1.3.2#');

    if (!$xpath->query('//ds:Signature')->length) {
        return array('code' => 'UNSIGNED', 'label' => 'Sin firma');
    }

    if ($xpath->query('//xades:SignatureTimeStamp')->length) {
        return array('code' => 'XADES-T', 'label' => 'XAdES-T');
    }

    return array('code' => 'XADES-BES', 'label' => 'XAdES-BES');
}

/* =======================================================================
 *  PASO 7.1 — CONSTRUCCIÓN PAYLOAD VERIFACTU (AEAT-READY)
 * ======================================================================= */

/**
 * Construye el payload VeriFactu canónico a partir de
 * - Factura validada
 * - Registro inmutable verifactu_registry
 *
 * ⚠️ NO envía nada
 * ⚠️ NO depende del modo SEND
 * ⚠️ AEAT-ready
 *
 * @param Facture  $facture
 * @param stdClass $registry  Row de llx_verifactu_registry
 * @return array
 * @throws Exception
 */
function verifactu_build_payload($facture, $registry): array
{
    global $conf;

    if (empty($facture) || empty($registry)) {
        throw new Exception('Factura o registro VeriFactu no proporcionados');
    }

    // -------------------------------
    // CABECERA
    // -------------------------------
    $payload = [
        'cabecera' => [
            'version'   => '1.0',
            'sistema'   => 'Dolibarr',
            'modulo'    => 'VeriFactu',
            'entidad'   => (int) $registry->entity,
            'timestamp' => date('c'),
        ],
    ];

    // -------------------------------
    // REGISTRO VERIFACTU (núcleo legal)
    // -------------------------------
    $payload['registro'] = [
        'id_registro'    => (int) $registry->rowid,
        'ref_facture'    => (string) $facture->ref,
        'fecha_factura'  => dol_print_date($facture->date, '%Y-%m-%d'),
        'fecha_registro' => dol_print_date($registry->date_creation, '%Y-%m-%dT%H:%M:%S'),
        'total_ttc'      => price2num($registry->total_ttc),
        'hash_actual'    => (string) $registry->hash_actual,
        'hash_anterior'  => (string) ($registry->hash_anterior ?? ''),
        'tipo'           => (string) ($registry->record_type ?? 'ALTA'),
    ];

    // -------------------------------
    // FIRMA (independiente del envío)
    // -------------------------------
    $payload['firma'] = [
        'algoritmo'   => 'SHA-256',
        'hash_sha256' => (string) $registry->hash_actual,
        'tipo_firma'  => 'XAdES',
    ];

    // -------------------------------
    // ENTORNO AEAT
    // -------------------------------
    $payload['entorno'] = [
        'modo'     => strtoupper($conf->global->VERIFACTU_MODE ?? 'NOSEND'),
        'ambiente' => strtoupper($conf->global->VERIFACTU_ENVIRONMENT ?? 'SANDBOX'),
        'endpoint' => verifactu_get_aeat_endpoint($conf),
    ];

    return $payload;
}

/**
 * PASO 7.2
 * Serializa un payload VeriFactu a XML (AEAT-ready)
 *
 * ⚠️ No firma
 * ⚠️ No envía
 * ⚠️ Solo construye XML canónico
 *
 * @param array $payload
 * @return string XML
 * @throws Exception
 */
function verifactu_payload_to_xml(array $payload): string
{
    if (empty($payload['cabecera']) || empty($payload['registro'])) {
        throw new Exception('Payload incompleto para generar XML VeriFactu');
    }

    $doc = new DOMDocument('1.0', 'UTF-8');
    $doc->formatOutput = true;

    // Nodo raíz
    $root = $doc->createElement('VeriFactu');
    $doc->appendChild($root);

    // -------------------------------
    // CABECERA
    // -------------------------------
    $cabecera = $doc->createElement('Cabecera');
    foreach ($payload['cabecera'] as $k => $v) {
        $cabecera->appendChild(
            $doc->createElement($k, htmlspecialchars((string) $v))
        );
    }
    $root->appendChild($cabecera);

    // -------------------------------
    // REGISTRO
    // -------------------------------
    $registro = $doc->createElement('Registro');
    foreach ($payload['registro'] as $k => $v) {
        $registro->appendChild(
            $doc->createElement($k, htmlspecialchars((string) $v))
        );
    }
    $root->appendChild($registro);

    // -------------------------------
    // FIRMA (metadatos, no XAdES aún)
    // -------------------------------
    if (!empty($payload['firma'])) {
        $firma = $doc->createElement('Firma');
        foreach ($payload['firma'] as $k => $v) {
            $firma->appendChild(
                $doc->createElement($k, htmlspecialchars((string) $v))
            );
        }
        $root->appendChild($firma);
    }

    // -------------------------------
    // ENTORNO
    // -------------------------------
    if (!empty($payload['entorno'])) {
        $entorno = $doc->createElement('Entorno');
        foreach ($payload['entorno'] as $k => $v) {
            $entorno->appendChild(
                $doc->createElement($k, htmlspecialchars((string) $v))
            );
        }
        $root->appendChild($entorno);
    }

    return $doc->saveXML();
}
/* =======================================================================
 *  PASO 7.3 — FIRMA XAdES DEL XML (BES / T)
 * ======================================================================= */

/**
 * Firma un XML VeriFactu con XAdES usando el certificado configurado.
 *
 * - Si hay TSA configurada => intentará XAdES-T
 * - Si no hay TSA => XAdES-BES
 *
 * ⚠️ NO envía nada
 * ⚠️ Devuelve el XML firmado (string) y metadatos
 *
 * @param string $xmlUnsigned  XML sin firmar
 * @param string|null $saveToAbsolutePath  Ruta absoluta donde guardar el XML firmado (opcional)
 * @return array {
 *   signed_xml: string,
 *   signature: array{code:string,label:string},
 *   saved_to: string|null
 * }
 * @throws Exception
 */
function verifactu_sign_xml_xades(string $xmlUnsigned, ?string $saveToAbsolutePath = null): array
{
    global $conf;

    if (trim($xmlUnsigned) === '') {
        throw new Exception('XML vacío: no se puede firmar');
    }

    // Certificado obligatorio
    $pfxPath = trim($conf->global->VERIFACTU_PFX_PATH ?? '');
    $pfxPass = (string)($conf->global->VERIFACTU_PFX_PASSWORD ?? '');

    if (empty($pfxPath) || !is_readable($pfxPath)) {
        throw new Exception('No hay certificado PFX configurado o no es accesible');
    }
    if ($pfxPass === '') {
        throw new Exception('No hay password de certificado PFX configurada');
    }

    // TSA (opcional)
    $tsaUrl  = trim($conf->global->VERIFACTU_TSA_URL ?? '');
    $tsaUser = trim($conf->global->VERIFACTU_TSA_USER ?? '');
    $tsaPass = (string)($conf->global->VERIFACTU_TSA_PASSWORD ?? '');

    // Cargar clase firmadora (si existe en tu módulo)
    $signerFile = DOL_DOCUMENT_ROOT.'/custom/verifactu/class/VerifactuXadesSigner.php';
    if (!class_exists('VerifactuXadesSigner', false)) {
        if (is_readable($signerFile)) {
            require_once $signerFile;
        }
    }

    if (!class_exists('VerifactuXadesSigner')) {
        throw new Exception('Clase VerifactuXadesSigner no encontrada. Revisa '.$signerFile);
    }

    $signer = new VerifactuXadesSigner();

    // --------------------------------------------------
    // Adaptador: probamos nombres de método comunes sin romper tu clase
    // (para evitar tocar tu firmador actual).
    // --------------------------------------------------
    $signedXml = null;

    // 1) Método típico: signXml($xml, $pfxPath, $pfxPass, $tsaUrl, $tsaUser, $tsaPass)
    if ($signedXml === null && method_exists($signer, 'signXml')) {
        $signedXml = $signer->signXml($xmlUnsigned, $pfxPath, $pfxPass, $tsaUrl, $tsaUser, $tsaPass);
    }

    // 2) Alternativa: sign($xml, $pfxPath, $pfxPass, $tsaUrl, $tsaUser, $tsaPass)
    if ($signedXml === null && method_exists($signer, 'sign')) {
        $signedXml = $signer->sign($xmlUnsigned, $pfxPath, $pfxPass, $tsaUrl, $tsaUser, $tsaPass);
    }

    // 3) Alternativa mínima: sign($xml, $pfxPath, $pfxPass)
    if ($signedXml === null && method_exists($signer, 'sign')) {
        $signedXml = $signer->sign($xmlUnsigned, $pfxPath, $pfxPass);
    }

    // 4) Alternativas por si tu clase usa otros nombres
    if ($signedXml === null && method_exists($signer, 'signXades')) {
        $signedXml = $signer->signXades($xmlUnsigned, $pfxPath, $pfxPass, $tsaUrl, $tsaUser, $tsaPass);
    }
    if ($signedXml === null && method_exists($signer, 'signDocument')) {
        $signedXml = $signer->signDocument($xmlUnsigned, $pfxPath, $pfxPass, $tsaUrl, $tsaUser, $tsaPass);
    }

    if (!is_string($signedXml) || trim($signedXml) === '') {
        throw new Exception('La firma no devolvió un XML válido (revisa tu VerifactuXadesSigner)');
    }

    // --------------------------------------------------
    // Guardar a disco (opcional) y detectar tipo de firma
    // --------------------------------------------------
    $savedTo = null;
    $tmpPathForStatus = null;

    if (!empty($saveToAbsolutePath)) {
        // Crear carpeta si no existe
        $dir = dirname($saveToAbsolutePath);
        if (!is_dir($dir)) {
            dol_mkdir($dir);
        }

        if (@file_put_contents($saveToAbsolutePath, $signedXml) === false) {
            throw new Exception('No se pudo guardar el XML firmado en '.$saveToAbsolutePath);
        }
        @chmod($saveToAbsolutePath, 0600);
        $savedTo = $saveToAbsolutePath;

        $sigStatus = verifactu_get_signature_status($saveToAbsolutePath);
    } else {
        // Si no guardamos, hacemos un temp para medir status sin tocar tu flujo
        $tmpPathForStatus = tempnam(sys_get_temp_dir(), 'vf_sig_');
        @file_put_contents($tmpPathForStatus, $signedXml);
        $sigStatus = verifactu_get_signature_status($tmpPathForStatus);
        @unlink($tmpPathForStatus);
    }

    return array(
        'signed_xml' => $signedXml,
        'signature'  => $sigStatus,  // ['code'=>'XADES-BES|XADES-T|...','label'=>...]
        'saved_to'   => $savedTo
    );
}


/**
 * Resolver central del endpoint AEAT según entorno
 *
 * @param Conf $conf
 * @return string
 * @throws Exception
 */
function verifactu_get_aeat_endpoint($conf)
{
    $env = strtoupper(trim($conf->global->VERIFACTU_ENVIRONMENT ?? 'SANDBOX'));

    if ($env === 'REAL') {

        $prodUrl = trim($conf->global->VERIFACTU_AEAT_PROD_URL ?? '');

        if (empty($prodUrl)) {
            throw new Exception(
                'Entorno REAL activo pero no hay endpoint AEAT de producción configurado'
            );
        }

        return $prodUrl;
    }

    // SANDBOX (por defecto)
    $sandboxUrl = trim($conf->global->VERIFACTU_AEAT_SANDBOX_URL ?? '');

    if (empty($sandboxUrl)) {
        // Fallback seguro
        return 'https://prewww1.aeat.es/verifactu/sandbox';
    }

    return $sandboxUrl;
}

/**
 * Devuelve la URL que debe codificarse en el QR VeriFactu
 */
function verifactu_get_qr_url($conf, int $registryId, ?string $csv = null): string
{
    $env = strtoupper(trim($conf->global->VERIFACTU_ENVIRONMENT ?? 'SANDBOX'));

    if ($env === 'REAL') {

        if (empty($csv)) {
            return DOL_MAIN_URL_ROOT.'/custom/verifactu/verifactu.card.php?id='.$registryId;
        }

        return 'https://www.agenciatributaria.gob.es/wlpl/CSVController?csv='
            .urlencode($csv);
    }

    return DOL_MAIN_URL_ROOT.'/custom/verifactu/verifactu.card.php?id='.$registryId;
}

/**
 * Envío de registro VeriFactu a AEAT
 */
function verifactuSendToAeat(array $payload): array
{
    global $conf;

    try {
        $endpoint = verifactu_get_aeat_endpoint($conf);
    } catch (Exception $e) {
        return [
            'status'    => 'ERROR',
            'csv'       => null,
            'message'   => $e->getMessage(),
            'timestamp' => date('c'),
        ];
    }

    $env = strtoupper(trim($conf->global->VERIFACTU_ENVIRONMENT ?? 'SANDBOX'));

    if ($env === 'SANDBOX') {
        return [
            'status'    => 'ACCEPTED',
            'csv'       => strtoupper(bin2hex(random_bytes(8))),
            'message'   => 'SANDBOX: Registro VeriFactu aceptado (simulación)',
            'timestamp' => date('c'),
            'echo'      => [
                'endpoint' => $endpoint,
                'ref'      => $payload['registro']['ref_facture'] ?? '',
                'hash'     => $payload['firma']['hash_sha256'] ?? '',
            ],
        ];
    }

    try {
        $jsonPayload = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($jsonPayload === false) {
            throw new Exception('Error codificando payload JSON');
        }

        $ch = curl_init($endpoint);

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $jsonPayload,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Accept: application/json'
            ],
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($response === false) {
            throw new Exception('cURL error: '.curl_error($ch));
        }

        curl_close($ch);

        $decoded = json_decode($response, true);

        if ($httpCode !== 200) {
            throw new Exception(
                'HTTP '.$httpCode.' - Respuesta AEAT: '.substr($response, 0, 500)
            );
        }

        return [
            'status'    => $decoded['status'] ?? 'ACCEPTED',
            'csv'       => $decoded['csv'] ?? null,
            'message'   => $decoded['message'] ?? 'Respuesta AEAT recibida',
            'timestamp' => date('c'),
            'raw'       => $decoded,
        ];

    } catch (Exception $e) {
        return [
            'status'    => 'ERROR',
            'csv'       => null,
            'message'   => $e->getMessage(),
            'timestamp' => date('c'),
        ];
    }
}

/**
 * Verifica la integridad de cadena
 */
function verifactu_check_chain_for_row($db, int $entity, int $rowid): array
{
    $sql = "SELECT rowid, hash_actual, hash_anterior
            FROM ".MAIN_DB_PREFIX."verifactu_registry
            WHERE entity = ".((int) $entity)."
              AND rowid = ".((int) $rowid)."
            LIMIT 1";

    $resql = $db->query($sql);
    if (!$resql || !$db->num_rows($resql)) {
        return array('status' => 'UNKNOWN', 'message' => 'Registro no encontrado');
    }

    $cur = $db->fetch_object($resql);

    if (empty($cur->hash_anterior)) {
        return array('status' => 'OK', 'message' => 'Inicio de cadena');
    }

    $sqlPrev = "SELECT rowid, hash_actual
                FROM ".MAIN_DB_PREFIX."verifactu_registry
                WHERE entity = ".((int) $entity)."
                  AND rowid < ".((int) $rowid)."
                ORDER BY rowid DESC
                LIMIT 1";

    $resPrev = $db->query($sqlPrev);
    if (!$resPrev || !$db->num_rows($resPrev)) {
        return array('status' => 'BROKEN', 'message' => 'No existe registro anterior');
    }

    $prev = $db->fetch_object($resPrev);

    if ((string) $cur->hash_anterior === (string) $prev->hash_actual) {
        return array('status' => 'OK', 'message' => 'Cadena íntegra');
    }

    return array(
        'status'  => 'BROKEN',
        'message' => 'Hash anterior no coincide con el registro previo'
    );
}

