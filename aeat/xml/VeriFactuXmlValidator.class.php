<?php
defined('DOL_DOCUMENT_ROOT') or die();

/**
 * Validación local de XML VeriFactu firmado (NO AEAT)
 *
 * Comprueba:
 * - XML bien formado
 * - ds:Signature presente
 * - SignedInfo presente
 * - SignatureValue presente
 * - QualifyingProperties (XAdES)
 * - SignedProperties referenciado
 */
class VeriFactuXmlValidator
{
    const NS_DS    = 'http://www.w3.org/2000/09/xmldsig#';
    const NS_XADES = 'http://uri.etsi.org/01903/v1.3.2#';

    /**
     * Valida un XML firmado
     *
     * @param string $xml
     * @return array ['ok'=>bool, 'errors'=>string[]]
     */
    public static function validate(string $xml): array
    {
        $errors = [];

        // --------------------------------------------------
        // 1) XML bien formado
        // --------------------------------------------------
        $doc = new DOMDocument();
        libxml_use_internal_errors(true);

        if (!$doc->loadXML($xml)) {
            foreach (libxml_get_errors() as $err) {
                $errors[] = 'XML mal formado: '.$err->message;
            }
            libxml_clear_errors();
            return ['ok' => false, 'errors' => $errors];
        }

        // --------------------------------------------------
        // 2) Buscar ds:Signature
        // --------------------------------------------------
        $xpath = new DOMXPath($doc);
        $xpath->registerNamespace('ds', self::NS_DS);
        $xpath->registerNamespace('xades', self::NS_XADES);

        $signature = $xpath->query('//ds:Signature')->item(0);
        if (!$signature) {
            $errors[] = 'No se encontró ds:Signature';
            return ['ok' => false, 'errors' => $errors];
        }

        // --------------------------------------------------
        // 3) SignedInfo
        // --------------------------------------------------
        if (!$xpath->query('./ds:SignedInfo', $signature)->length) {
            $errors[] = 'No se encontró ds:SignedInfo';
        }

        // --------------------------------------------------
        // 4) SignatureValue
        // --------------------------------------------------
        if (!$xpath->query('./ds:SignatureValue', $signature)->length) {
            $errors[] = 'No se encontró ds:SignatureValue';
        }

        // --------------------------------------------------
        // 5) KeyInfo
        // --------------------------------------------------
        if (!$xpath->query('./ds:KeyInfo', $signature)->length) {
            $errors[] = 'No se encontró ds:KeyInfo';
        }

        // --------------------------------------------------
        // 6) QualifyingProperties
        // --------------------------------------------------
        $qp = $xpath->query('.//xades:QualifyingProperties')->item(0);
        if (!$qp) {
            $errors[] = 'No se encontró xades:QualifyingProperties';
        }

        // --------------------------------------------------
        // 7) SignedProperties con Id
        // --------------------------------------------------
        $sp = $xpath->query('.//xades:SignedProperties')->item(0);
        if (!$sp) {
            $errors[] = 'No se encontró xades:SignedProperties';
        } else {
            if (!$sp->hasAttribute('Id')) {
                $errors[] = 'xades:SignedProperties no tiene atributo Id';
            }
        }

        // --------------------------------------------------
        // 8) Reference a SignedProperties
        // --------------------------------------------------
        if ($sp && $sp->hasAttribute('Id')) {
            $spId = '#'.$sp->getAttribute('Id');
            $ref = $xpath->query(
                './/ds:Reference[@URI="'.$spId.'"]',
                $signature
            );
            if (!$ref->length) {
                $errors[] = 'No existe ds:Reference a SignedProperties ('.$spId.')';
            }
        }

        // --------------------------------------------------
        // Resultado final
        // --------------------------------------------------
        return [
            'ok'     => empty($errors),
            'errors' => $errors
        ];
    }
}
