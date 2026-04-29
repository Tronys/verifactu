<?php

defined('DOL_DOCUMENT_ROOT') or die();

/**
 * Validador de la cadena de Huellas VeriFactu.
 *
 * Comprueba que:
 *  1. El campo hash_anterior de cada registro coincide con hash_actual del anterior.
 *  2. La Huella almacenada (hash_actual) puede recalcularse y coincide.
 */
class VeriFactuChainValidator
{
    private $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    /**
     * Validación completa de todos los registros en orden de inserción.
     *
     * @return array ['status'=>'OK'|'ERROR', 'errors'=>[], 'details'=>[]]
     */
    public function validate()
    {
        require_once DOL_DOCUMENT_ROOT.'/custom/verifactu/class/VeriFactuHash.class.php';

        $result = [
            'status'  => 'OK',
            'errors'  => [],
            'details' => [],
        ];

        $sql = "SELECT r.* FROM ".MAIN_DB_PREFIX."verifactu_registry r ORDER BY r.rowid ASC";
        $resql = $this->db->query($sql);

        if (!$resql) {
            return [
                'status'  => 'ERROR',
                'errors'  => ['No se pudieron cargar los registros VeriFactu'],
                'details' => [],
            ];
        }

        $previousHash = null;

        while ($record = $this->db->fetch_object($resql)) {

            $recordStatus = 'OK';
            $message      = '';

            // 1. Encadenamiento
            $storedPrev = ($record->hash_anterior !== null && $record->hash_anterior !== '')
                ? $record->hash_anterior
                : null;

            if ($storedPrev !== $previousHash) {
                $recordStatus = 'BROKEN_CHAIN';
                $message .= 'Hash anterior no coincide. ';
            }

            // 2. Recalcular Huella
            $recalculated = $this->recalculateHash($record, (string) ($previousHash ?? ''));

            if ($recalculated !== null && $recalculated !== $record->hash_actual) {
                $recordStatus = 'HASH_MISMATCH';
                $message .= 'Huella recalculada no coincide. ';
            }

            if ($recordStatus !== 'OK') {
                $result['status']   = 'ERROR';
                $result['errors'][] = "Registro ID {$record->rowid}: {$recordStatus}";
            }

            $result['details'][] = [
                'id'                => $record->rowid,
                'type'              => $record->record_type,
                'status'            => $recordStatus,
                'stored_hash'       => $record->hash_actual,
                'recalculated_hash' => $recalculated,
                'message'           => trim($message),
            ];

            $previousHash = $record->hash_actual;
        }

        return $result;
    }

    /**
     * Recalcula la Huella de un registro usando los datos guardados.
     *
     * Para que la recalculación sea posible sin depender de la factura en vivo,
     * utiliza los campos almacenados en el propio registro de la tabla:
     *   - tipo_factura  (columna añadida en migración)
     *   - cuota_total   (columna añadida en migración)
     *   - total_ttc
     *   - date_creation (FechaHoraHusoGenRegistro)
     * Y carga la factura sólo para obtener ref y fecha de expedición.
     *
     * @param object $record     Fila de llx_verifactu_registry
     * @param string $prevHash   Huella del registro anterior ('' si es el primero)
     * @return string|null       Huella recalculada o null si no es posible
     */
    private function recalculateHash($record, $prevHash)
    {
        global $conf;

        require_once DOL_DOCUMENT_ROOT.'/custom/verifactu/class/VeriFactuHash.class.php';
        require_once DOL_DOCUMENT_ROOT.'/compta/facture/class/facture.class.php';

        $facture = new Facture($this->db);
        if ($facture->fetch((int) $record->fk_facture) <= 0) {
            return null;
        }

        $nifEmisor   = (string) ($conf->global->MAIN_INFO_SIREN ?? '');
        $numSerie    = (string) $facture->ref;
        $fechaFact   = (int) $facture->date;
        $fechaGen    = (int) strtotime($record->date_creation);
        $tipoFactura = !empty($record->tipo_factura) ? (string) $record->tipo_factura : 'F1';
        $cuotaTotal  = isset($record->cuota_total)   ? (float) $record->cuota_total   : 0.0;
        $importeTotal= (float) $record->total_ttc;

        if ($record->record_type === 'ALTA') {
            return VeriFactuHash::calculate(
                $nifEmisor,
                $numSerie,
                $fechaFact,
                $tipoFactura,
                $cuotaTotal,
                $importeTotal,
                $prevHash,
                $fechaGen
            );
        }

        if ($record->record_type === 'BAJA') {
            return VeriFactuHash::calculateCancel(
                $nifEmisor,
                $numSerie,
                $fechaFact,
                $tipoFactura,
                $prevHash,
                $fechaGen
            );
        }

        return null;
    }
}
