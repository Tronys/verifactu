<?php
/**
 * PDF Invoice model Verifactu Crabe
 *
 * Ubicación:
 * /custom/verifactu/core/modules/facture/doc/pdf_verifactu_crabe.modules.php
 */

defined('DOL_DOCUMENT_ROOT') || die();

require_once DOL_DOCUMENT_ROOT.'/core/modules/facture/modules_facture.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/company.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/pdf.lib.php';

// Modelo base Crabe
require_once DOL_DOCUMENT_ROOT.'/core/modules/facture/doc/pdf_crabe.modules.php';

// Helpers VeriFactu
require_once __DIR__.'/../../../../lib/verifactu.lib.php';

/**
 * Class pdf_verifactu_crabe
 */
class pdf_verifactu_crabe extends pdf_crabe
{
    /**
     * Constructor
     */
    public function __construct($db)
    {
        global $conf, $langs;

        $this->db = $db;

        // Idiomas
        $langs->loadLangs(array('main', 'bills', 'verifactu@verifactu'));

        // 1) Constructor padre
        parent::__construct($db);

        // 2) Identificación propia del modelo
        $this->name        = 'verifactu_crabe';
        $this->file        = 'verifactu_crabe';
        $this->description = $langs->trans('PDFCrabeDescription').' + VeriFactu (QR AEAT)';
        $this->type        = 'pdf';
        $this->module      = 'verifactu';
    }

    /**
     * Generación del PDF
     */
    public function write_file($object, $outputlangs, $srctemplatepath = '', $hidedetails = 0, $hidedesc = 0, $hideref = 0)
    {
        return parent::write_file($object, $outputlangs, $srctemplatepath, $hidedetails, $hidedesc, $hideref);
    }

    /**
     * Cabecera
     * (NO ponemos el QR aquí)
     */
    protected function _pagehead(&$pdf, $object, $showaddress, $outputlangs, $outputlangsbis = null)
    {
        parent::_pagehead($pdf, $object, $showaddress, $outputlangs, $outputlangsbis);
    }

    /**
     * Pie de página
     * Aquí colocamos el QR JUSTO debajo del total
     */
    protected function _pagefoot(&$pdf, $object, $outputlangs, $hidefreetext = 0, $heightforfreetext = 0)
    {
        global $conf;

        // ------------------------------------------------
        // QR VERIFACTU (debajo del total)
        // ------------------------------------------------
        $verifactuCode = $this->getVerifactuCode($object);

        if (!empty($verifactuCode) && method_exists($pdf, 'write2DBarcode')) {

            // Tamaño del QR
            $qrSize = 16;

            // Ancho aproximado del bloque de totales (Crabe)
            $totalsWidth = 67;

            // Ajuste fino hacia la izquierda (mm)
            $leftOffset = 14;

            // X inicio del bloque (debajo del total)
            $xTotals = $this->page_largeur - $this->marge_droite - $totalsWidth - $leftOffset;

            // Posición QR
            $xQr = $xTotals;
            $yQr = $pdf->GetY() + 4;

            // --------------------------------------------
            // NUEVO: URL REAL DEL QR (PASO 5A)
            // --------------------------------------------
            $registry = $this->getVerifactuRegistry($object);

            if ($registry) {
                $qrUrl = verifactu_get_qr_url(
                    $conf,
                    (int) $registry->rowid,
                    $registry->aeat_csv ?? null
                );
            } else {
                // Fallback ultra seguro
                $qrUrl = DOL_MAIN_URL_ROOT;
            }

            // Dibujar QR (URL verificable)
            $pdf->write2DBarcode(
                $qrUrl,
                'QRCODE,M',
                $xQr,
                $yQr,
                $qrSize,
                $qrSize,
                array('border' => 0),
                'N'
            );

            // --------------------------------------------
            // TEXTO A LA DERECHA DEL QR
            // --------------------------------------------
            $textGap   = 4;
            $textWidth = $totalsWidth - $qrSize - $textGap;

            $xText = $xQr + $qrSize + $textGap;
            $yText = $yQr + 1;

            $pdf->SetFont('', '', 7);
            $pdf->SetXY($xText, $yText);
            $pdf->MultiCell(
                $textWidth,
                3,
                "Sistema VeriFactu AEAT\n".
                "Código:\n".
                $verifactuCode,
                0,
                'L'
            );
        }

        // ------------------------------------------------
        // Pie original Crabe
        // ------------------------------------------------
        parent::_pagefoot($pdf, $object, $outputlangs, $hidefreetext, $heightforfreetext);

        // Texto legal adicional
        $pdf->SetFont('', '', 7);
        $pdf->SetY(-28);
        $pdf->MultiCell(
            0,
            4,
            "Factura generada conforme al Real Decreto 1007/2023.\n".
            "Sistema informático de facturación VeriFactu. Registro íntegro e inalterable.",
            0,
            'L'
        );
    }

    /**
     * Obtener código VeriFactu (hash recortado)
     */
    private function getVerifactuCode($object)
    {
        if (empty($object->id)) {
            return '';
        }

        $sql = "SELECT hash_actual
                FROM ".MAIN_DB_PREFIX."verifactu_registry
                WHERE fk_facture = ".((int) $object->id)."
                ORDER BY rowid DESC
                LIMIT 1";

        $resql = $this->db->query($sql);
        if ($resql && $this->db->num_rows($resql)) {
            $obj = $this->db->fetch_object($resql);
            return substr((string) $obj->hash_actual, 0, 32);
        }

        return '';
    }

    /**
     * Obtener registro VeriFactu completo de la factura
     */
    private function getVerifactuRegistry($object)
    {
        if (empty($object->id)) {
            return null;
        }

        $sql = "SELECT rowid, aeat_csv
                FROM ".MAIN_DB_PREFIX."verifactu_registry
                WHERE fk_facture = ".((int) $object->id)."
                ORDER BY rowid DESC
                LIMIT 1";

        $resql = $this->db->query($sql);
        if ($resql && $this->db->num_rows($resql)) {
            return $this->db->fetch_object($resql);
        }

        return null;
    }
}
