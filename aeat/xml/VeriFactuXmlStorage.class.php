<?php
/**
 * Gestión de almacenamiento XML VeriFactu
 */

class VeriFactuXmlStorage
{
    /**
     * Guarda el XML VeriFactu en documents/verifactu/xml
     *
     * @param object $registry
     * @param string $xml
     * @return string Ruta relativa para document.php
     * @throws Exception
     */
    public static function save($registry, $xml)
    {
        // --------------------------------------------------
        // RUTA BASE OFICIAL DE DOLIBARR (NO FALLA)
        // --------------------------------------------------
        if (!defined('DOL_DATA_ROOT') || empty(DOL_DATA_ROOT)) {
            throw new Exception('Ruta base de documentos Dolibarr no disponible');
        }

        $baseDir = DOL_DATA_ROOT . '/verifactu/xml';

        // --------------------------------------------------
        // CREAR DIRECTORIO SI NO EXISTE
        // --------------------------------------------------
        if (!is_dir($baseDir)) {
            if (!dol_mkdir($baseDir)) {
                throw new Exception('No se pudo crear el directorio de almacenamiento VeriFactu');
            }
        }

        // --------------------------------------------------
        // NOMBRE DE ARCHIVO (LEGAL Y ÚNICO)
        // --------------------------------------------------
        $filename = sprintf(
            'VF_%d_%s.xml',
            (int) $registry->rowid,
            date('Ymd_His')
        );

        $fullPath = $baseDir . '/' . $filename;

        // --------------------------------------------------
        // GUARDAR XML
        // --------------------------------------------------
        if (file_put_contents($fullPath, $xml) === false) {
            throw new Exception('No se pudo guardar el XML VeriFactu en disco');
        }

        // Permisos seguros
        @chmod($fullPath, 0640);

        // --------------------------------------------------
        // RUTA RELATIVA PARA document.php
        // --------------------------------------------------
        return 'xml/' . $filename;
    }
}
