<?php
/**
 * VeriFactu - XML Builder
 *
 * Genera el XML de registro VeriFactu conforme a los requisitos AEAT.
 *
 * @author InternetPyme
 * @license GPL
 */

if (!defined('DOL_DOCUMENT_ROOT')) {
    die('Direct access not allowed');
}

class VerifactuXmlBuilder
{
    /**
     * Genera el XML VeriFactu
     *
     * @param array $data Datos del registro VeriFactu
     *  - tipo            (ALTA|BAJA)
     *  - fecha           (Y-m-d H:i:s)
     *  - factura         (string)
     *  - fecha_factura   (Y-m-d)
     *  - total           (float)
     *  - hash_actual     (string)
     *  - hash_anterior   (string|null)
     *
     * @return string XML generado
     * @throws Exception
     */
    public static function build(array $data): string
    {
        self::validateData($data);

        $doc = new DOMDocument('1.0', 'UTF-8');
        $doc->preserveWhiteSpace = false;
        $doc->formatOutput = true;

        // Nodo raíz
        $root = $doc->createElement('RegistroVeriFactu');
        $doc->appendChild($root);

        // ======================
        // Cabecera
        // ======================
        $cabecera = $doc->createElement('Cabecera');
        $cabecera->appendChild($doc->createElement('IDVersion', '1.0'));
        $cabecera->appendChild(
            $doc->createElement(
                'FechaHoraRegistro',
                date('c', strtotime($data['fecha']))
            )
        );
        $cabecera->appendChild($doc->createElement('TipoRegistro', $data['tipo']));
        $root->appendChild($cabecera);

        // ======================
        // Sistema Informático
        // ======================
        global $conf;

        $sistema = $doc->createElement('SistemaInformatico');
        $sistema->appendChild($doc->createElement('Nombre', 'Dolibarr'));
        $sistema->appendChild($doc->createElement('Version', DOL_VERSION));
        $sistema->appendChild(
            $doc->createElement(
                'Proveedor',
                $conf->global->MAIN_INFO_SOCIETE_NOM ?? 'PROVEEDOR DESCONOCIDO'
            )
        );
        $sistema->appendChild(
            $doc->createElement(
                'NIFProveedor',
                $conf->global->MAIN_INFO_SIREN ?? 'DESCONOCIDO'
            )
        );
        $root->appendChild($sistema);

        // ======================
        // Factura (solo ALTA)
        // ======================
        if ($data['tipo'] === 'ALTA') {
            $factura = $doc->createElement('Factura');
            $factura->appendChild($doc->createElement('Numero', $data['factura']));
            $factura->appendChild(
                $doc->createElement('FechaExpedicion', $data['fecha_factura'])
            );
            $factura->appendChild(
                $doc->createElement(
                    'ImporteTotal',
                    number_format((float) $data['total'], 2, '.', '')
                )
            );
            $factura->appendChild($doc->createElement('Moneda', 'EUR'));
            $root->appendChild($factura);
        }

        // ======================
        // Encadenamiento
        // ======================
        $encadenamiento = $doc->createElement('Encadenamiento');
        $encadenamiento->appendChild(
            $doc->createElement('HashActual', $data['hash_actual'])
        );
        $encadenamiento->appendChild(
            $doc->createElement(
                'HashAnterior',
                $data['hash_anterior'] ?? ''
            )
        );
        $encadenamiento->appendChild(
            $doc->createElement('Algoritmo', 'SHA-256')
        );
        $root->appendChild($encadenamiento);

        return $doc->saveXML();
    }

    /**
     * Valida los datos mínimos necesarios
     *
     * @param array $data
     * @throws Exception
     */
    protected static function validateData(array $data): void
    {
        $required = [
            'tipo',
            'fecha',
            'factura',
            'hash_actual',
        ];

        foreach ($required as $key) {
            if (empty($data[$key])) {
                throw new Exception('Falta el campo obligatorio: ' . $key);
            }
        }

        if (!in_array($data['tipo'], ['ALTA', 'BAJA'], true)) {
            throw new Exception('Tipo de registro inválido');
        }

        if ($data['tipo'] === 'ALTA') {
            if (!isset($data['fecha_factura'], $data['total'])) {
                throw new Exception('Datos de factura incompletos para ALTA');
            }
        }
    }
}
