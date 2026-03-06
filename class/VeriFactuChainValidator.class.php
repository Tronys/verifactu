<?php

defined('DOL_DOCUMENT_ROOT') or die();

/**
 * Validador avanzado VeriFactu
 *
 * - Comprueba encadenamiento
 * - Recalcula hash
 * - Detecta manipulaciones
 */
class VeriFactuChainValidator
{
    private $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    /**
     * Validación completa
     */
    public function validate()
    {
        require_once DOL_DOCUMENT_ROOT.'/custom/verifactu/class/VeriFactuHash.class.php';

        $result = [
            'status' => 'OK',
            'errors' => [],
            'details' => []
        ];

        $sql = "SELECT r.*
                FROM ".MAIN_DB_PREFIX."verifactu_registry r
                ORDER BY r.rowid ASC";

        $resql = $this->db->query($sql);
        if (!$resql) {
            return [
                'status' => 'ERROR',
                'errors' => ['No se pudieron cargar los registros VeriFactu'],
                'details' => []
            ];
        }

        $previousHash = null;

        while ($record = $this->db->fetch_object($resql)) {

            $recordStatus = 'OK';
            $message = '';

            /* ==========================
             * 1. VALIDAR ENCADENADO
             * ========================== */
            if ($record->hash_anterior !== $previousHash) {
                $recordStatus = 'BROKEN_CHAIN';
                $message .= 'Hash anterior no coincide. ';
            }

            /* ==========================
             * 2. RECONSTRUIR HASH
             * ========================== */
            $recalculatedHash = $this->recalculateHash($record, $previousHash);

            if ($recalculatedHash && $recalculatedHash !== $record->hash_actual) {
                $recordStatus = 'HASH_MISMATCH';
                $message .= 'Hash recalculado no coincide. ';
            }

            /* ==========================
             * 3. RESULTADO GLOBAL
             * ========================== */
            if ($recordStatus !== 'OK') {
                $result['status'] = 'ERROR';
                $result['errors'][] =
                    "Registro ID {$record->rowid}: {$recordStatus}";
            }

            $result['details'][] = [
                'id' => $record->rowid,
                'type' => $record->record_type,
                'status' => $recordStatus,
                'stored_hash' => $record->hash_actual,
                'recalculated_hash' => $recalculatedHash,
                'message' => trim($message)
            ];

            $previousHash = $record->hash_actual;
        }

        return $result;
    }

    /**
     * Recalcular hash según tipo de registro
     */
    private function recalculateHash($record, $previousHash)
    {
        require_once DOL_DOCUMENT_ROOT.'/compta/facture/class/facture.class.php';

        // Cargar factura
        $facture = new Facture($this->db);
        if ($facture->fetch($record->fk_facture) <= 0) {
            return null;
        }

        if ($record->record_type === 'ALTA') {
            return VeriFactuHash::calculate($facture, $previousHash);
        }

        if ($record->record_type === 'BAJA') {

            // Hash original ALTA
            $sql = "SELECT hash_actual FROM ".MAIN_DB_PREFIX."verifactu_registry
                    WHERE fk_facture = ".((int)$record->fk_facture)."
                    AND record_type = 'ALTA'
                    ORDER BY rowid ASC LIMIT 1";

            $resql = $this->db->query($sql);
            if (!$resql || !$this->db->num_rows($resql)) {
                return null;
            }

            $obj = $this->db->fetch_object($resql);
            return VeriFactuHash::calculateCancel(
                $facture,
                $previousHash,
                $obj->hash_actual
            );
        }

        return null;
    }
}
