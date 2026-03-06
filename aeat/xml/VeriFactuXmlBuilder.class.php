<?php
defined('DOL_DOCUMENT_ROOT') or die();

require_once DOL_DOCUMENT_ROOT . '/compta/facture/class/facture.class.php';

/**
 * Generador XML VeriFactu
 *
 * - Compatible con XSD VeriFactu (estructura mínima válida)
 * - SIN firma (la firma XAdES se añade después)
 * - SIN envío
 */
class VeriFactuXmlBuilder
{
    /**
     * Genera XML VeriFactu para un registro
     *
     * @param object $registry Registro llx_verifactu_registry
     * @return string XML
     * @throws Exception
     */
    public static function build($registry)
    {
        global $db, $conf;

        // --------------------------------------------------
        // Cargar factura
        // --------------------------------------------------
        $facture = new Facture($db);
        if ($facture->fetch($registry->fk_facture) <= 0) {
            throw new Exception('Factura no encontrada');
        }

        // --------------------------------------------------
        // Documento XML
        // --------------------------------------------------
        $xml = new DOMDocument('1.0', 'UTF-8');
        $xml->preserveWhiteSpace = false;
        $xml->formatOutput = true;

        // Namespace VeriFactu
        $ns = 'https://www.agenciatributaria.gob.es/static_files/common/internet/dep/aduanas/es/aeat/sii/reg_factu/VeriFactu.xsd';

        // --------------------------------------------------
        // RAÍZ
        // --------------------------------------------------
        $root = $xml->createElementNS($ns, 'RegistroVeriFactu');
        $xml->appendChild($root);

        // ==================================================
        // 1) SISTEMA INFORMÁTICO (OBLIGATORIO, PRIMERO)
        // ==================================================
        $sistema = $xml->createElementNS($ns, 'SistemaInformatico');

        $sistema->appendChild(
            $xml->createElementNS($ns, 'NombreRazon', 'Dolibarr VeriFactu')
        );

        $sistema->appendChild(
            $xml->createElementNS($ns, 'NIF', $conf->global->MAIN_INFO_SIREN)
        );

        $sistema->appendChild(
            $xml->createElementNS($ns, 'Version', DOL_VERSION)
        );

        $sistema->appendChild(
            $xml->createElementNS(
                $ns,
                'Proveedor',
                $conf->global->MAIN_INFO_SOCIETE_NOM
            )
        );

        $root->appendChild($sistema);

        // ==================================================
        // 2) CABECERA (OBLIGATORIO, SEGUNDO)
        // ==================================================
        $cabecera = $xml->createElementNS($ns, 'Cabecera');

        $cabecera->appendChild(
            $xml->createElementNS(
                $ns,
                'Ejercicio',
                date('Y', strtotime($registry->date_creation))
            )
        );

        $cabecera->appendChild(
            $xml->createElementNS(
                $ns,
                'FechaRegistro',
                dol_print_date(
                    strtotime($registry->date_creation),
                    'dayhourrfc'
                )
            )
        );

        $root->appendChild($cabecera);

        // ==================================================
        // 3) FACTURA (OBLIGATORIO)
        // ==================================================
        $facturaNode = $xml->createElementNS($ns, 'Factura');

        // ---------------------------
        // 3.1) EMISOR (OBLIGATORIO)
        // ---------------------------
        $emisor = $xml->createElementNS($ns, 'Emisor');

        $emisor->appendChild(
            $xml->createElementNS(
                $ns,
                'NombreRazon',
                $conf->global->MAIN_INFO_SOCIETE_NOM
            )
        );

        $emisor->appendChild(
            $xml->createElementNS(
                $ns,
                'NIF',
                $conf->global->MAIN_INFO_SIREN
            )
        );

        $facturaNode->appendChild($emisor);

        // ---------------------------
        // 3.2) DATOS FACTURA
        // ---------------------------
        $facturaNode->appendChild(
            $xml->createElementNS(
                $ns,
                'NumeroFactura',
                $facture->ref
            )
        );

        $facturaNode->appendChild(
            $xml->createElementNS(
                $ns,
                'FechaFactura',
                date('Y-m-d', (int) $facture->date)
            )
        );

        $facturaNode->appendChild(
            $xml->createElementNS(
                $ns,
                'ImporteTotal',
                number_format($facture->total_ttc, 2, '.', '')
            )
        );

        $root->appendChild($facturaNode);

        // --------------------------------------------------
        // FIN
        // --------------------------------------------------
        return $xml->saveXML();
    }
}


