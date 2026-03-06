<?php
/**
 * VeriFactuXsdValidator.class.php
 *
 * Validación XSD interna de XML VeriFactu
 * (Control de calidad del módulo, NO validación AEAT)
 */

class VeriFactuXsdValidator
{
    /**
     * Valida un XML contra el XSD interno del módulo
     *
     * @param string $xmlString
     * @param string $xsdFile Ruta absoluta al XSD
     * @return array ['ok' => bool, 'errors' => array]
     */
    public static function validate($xmlString, $xsdFile)
    {
        $result = [
            'ok'     => true,
            'errors' => [],
        ];

        if (!file_exists($xsdFile)) {
            $result['ok'] = false;
            $result['errors'][] = 'No se encuentra el fichero XSD: '.$xsdFile;
            return $result;
        }

        libxml_use_internal_errors(true);

        $dom = new DOMDocument('1.0', 'UTF-8');
        if (!$dom->loadXML($xmlString)) {
            $result['ok'] = false;
            $result['errors'][] = 'XML inválido (error de parseo)';
            return $result;
        }

        if (!$dom->schemaValidate($xsdFile)) {
            $result['ok'] = false;

            foreach (libxml_get_errors() as $error) {
                $result['errors'][] = trim($error->message);
            }

            libxml_clear_errors();
        }

        libxml_use_internal_errors(false);

        return $result;
    }
}

