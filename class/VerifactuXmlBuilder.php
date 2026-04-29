<?php
/**
 * VeriFactu — Generador XML canónico.
 *
 * Genera el XML de registro VeriFactu que se almacena, firma (XAdES-BES/T)
 * y se enviará a la AEAT. Soporta ALTA F1/F2 y BAJA.
 *
 * Estructura validable con VeriFactu_v1_0.xsd (schema local).
 */

if (!defined('DOL_DOCUMENT_ROOT')) {
    die('Direct access not allowed');
}

class VerifactuXmlBuilder
{
    const NS = 'https://www.agenciatributaria.gob.es/static_files/common/internet/dep/aduanas/es/aeat/sii/reg_factu/VeriFactu.xsd';

    /**
     * Construye el XML VeriFactu.
     *
     * Campos esperados en $data:
     *   tipo            string  ALTA | BAJA
     *   fecha           string  Timestamp de generación (Y-m-d H:i:s)
     *   factura         string  Número/referencia de factura
     *   fecha_factura   string  Fecha de expedición (Y-m-d)
     *   tipo_factura    string  F1 | F2 (sólo ALTA)
     *   nif_emisor      string  NIF/CIF del emisor
     *   nombre_emisor   string  Razón social del emisor
     *   total           float   Importe total con IVA
     *   cuota_total     float   Suma de cuotas IVA
     *   huella          string  Huella SHA-256 de este registro
     *   huella_anterior string|null  Huella del registro anterior (null = primero)
     *   desglose_iva    array   [['tasa'=>21.0,'base'=>1000.0,'cuota'=>210.0], …]
     *   destinatario    array|null  ['nombre'=>'…','nif'=>'…'] — sólo F1
     *
     * @param array $data
     * @return string XML bien formado
     * @throws Exception
     */
    public static function build(array $data): string
    {
        self::validateData($data);

        $doc = new DOMDocument('1.0', 'UTF-8');
        $doc->preserveWhiteSpace = false;
        $doc->formatOutput       = true;

        $ns = self::NS;

        // ── Raíz ─────────────────────────────────────────────────────────────
        $root = $doc->createElementNS($ns, 'RegistroVeriFactu');
        $doc->appendChild($root);

        // ── 1. SistemaInformatico ─────────────────────────────────────────────
        global $conf;
        $sistema = $doc->createElementNS($ns, 'SistemaInformatico');
        $sistema->appendChild($doc->createElementNS($ns, 'NombreRazon', 'Dolibarr VeriFactu'));
        $sistema->appendChild($doc->createElementNS($ns, 'NIF',         (string) $data['nif_emisor']));
        $sistema->appendChild($doc->createElementNS($ns, 'Version',     DOL_VERSION));
        $sistema->appendChild($doc->createElementNS($ns, 'Proveedor',   (string) $data['nombre_emisor']));
        $root->appendChild($sistema);

        // ── 2. Cabecera ───────────────────────────────────────────────────────
        $cabecera = $doc->createElementNS($ns, 'Cabecera');
        $cabecera->appendChild(
            $doc->createElementNS($ns, 'Ejercicio', date('Y', strtotime($data['fecha'])))
        );
        $cabecera->appendChild(
            $doc->createElementNS($ns, 'FechaRegistro', self::isoDateTime($data['fecha']))
        );
        $root->appendChild($cabecera);

        // ── 3. Factura ────────────────────────────────────────────────────────
        $facturaNode = $doc->createElementNS($ns, 'Factura');

        // 3.1 Emisor
        $emisor = $doc->createElementNS($ns, 'Emisor');
        $emisor->appendChild($doc->createElementNS($ns, 'NombreRazon', (string) $data['nombre_emisor']));
        $emisor->appendChild($doc->createElementNS($ns, 'NIF',         (string) $data['nif_emisor']));
        $facturaNode->appendChild($emisor);

        // 3.2 Número y fecha
        $facturaNode->appendChild($doc->createElementNS($ns, 'NumeroFactura', (string) $data['factura']));
        $facturaNode->appendChild($doc->createElementNS($ns, 'FechaFactura',  (string) $data['fecha_factura']));

        if ($data['tipo'] === 'ALTA') {
            // 3.3 Tipo
            $facturaNode->appendChild(
                $doc->createElementNS($ns, 'TipoFactura', (string) $data['tipo_factura'])
            );

            // 3.4 Destinatario — sólo F1 con datos de comprador identificado
            if (
                $data['tipo_factura'] === 'F1'
                && !empty($data['destinatario']['nif'])
            ) {
                $dest = $doc->createElementNS($ns, 'Destinatario');
                $dest->appendChild(
                    $doc->createElementNS($ns, 'NombreRazon', (string) $data['destinatario']['nombre'])
                );
                $dest->appendChild(
                    $doc->createElementNS($ns, 'NIF', (string) $data['destinatario']['nif'])
                );
                $facturaNode->appendChild($dest);
            }

            // 3.5 Desglose IVA
            if (!empty($data['desglose_iva'])) {
                $desglose = $doc->createElementNS($ns, 'DetalleIVA');
                foreach ($data['desglose_iva'] as $tramo) {
                    $det = $doc->createElementNS($ns, 'DetalleImpuesto');
                    $det->appendChild(
                        $doc->createElementNS($ns, 'TipoImpositivo',
                            number_format((float) $tramo['tasa'], 2, '.', ''))
                    );
                    $det->appendChild(
                        $doc->createElementNS($ns, 'BaseImponible',
                            number_format((float) $tramo['base'], 2, '.', ''))
                    );
                    $det->appendChild(
                        $doc->createElementNS($ns, 'CuotaIVA',
                            number_format((float) $tramo['cuota'], 2, '.', ''))
                    );
                    $desglose->appendChild($det);
                }
                $facturaNode->appendChild($desglose);
            }

            // 3.6 CuotaTotal e ImporteTotal
            $facturaNode->appendChild(
                $doc->createElementNS($ns, 'CuotaTotal',
                    number_format((float) $data['cuota_total'], 2, '.', ''))
            );
        }

        $facturaNode->appendChild(
            $doc->createElementNS($ns, 'ImporteTotal',
                number_format((float) $data['total'], 2, '.', ''))
        );

        // 3.7 Encadenamiento — Huella de este registro y del anterior
        $facturaNode->appendChild($doc->createElementNS($ns, 'Huella', (string) $data['huella']));
        $facturaNode->appendChild(
            $doc->createElementNS($ns, 'HuellaAnterior',
                $data['huella_anterior'] ?? '')
        );

        $root->appendChild($facturaNode);

        return $doc->saveXML();
    }

    // ── Validación de entrada ─────────────────────────────────────────────────

    protected static function validateData(array $data): void
    {
        foreach (['tipo', 'fecha', 'factura', 'fecha_factura', 'nif_emisor', 'nombre_emisor', 'total', 'huella'] as $key) {
            if (!isset($data[$key]) || $data[$key] === '') {
                throw new Exception('VeriFactuXmlBuilder: falta campo obligatorio: ' . $key);
            }
        }

        if (!in_array($data['tipo'], ['ALTA', 'BAJA'], true)) {
            throw new Exception('VeriFactuXmlBuilder: tipo inválido: ' . $data['tipo']);
        }

        if ($data['tipo'] === 'ALTA') {
            foreach (['tipo_factura', 'cuota_total'] as $key) {
                if (!isset($data[$key]) && $data[$key] !== 0) {
                    throw new Exception('VeriFactuXmlBuilder: falta campo ALTA: ' . $key);
                }
            }
            if (!in_array($data['tipo_factura'], ['F1', 'F2'], true)) {
                throw new Exception('VeriFactuXmlBuilder: tipo_factura inválido: ' . $data['tipo_factura']);
            }
        }
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private static function isoDateTime(string $datetime): string
    {
        $tz = date_default_timezone_get() ?: 'UTC';
        return (new DateTime($datetime, new DateTimeZone($tz)))->format('Y-m-d\TH:i:sP');
    }
}
