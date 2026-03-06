<?php

defined('DOL_DOCUMENT_ROOT') or die();

/**
 * Hooks VeriFactu para Dolibarr
 */
class ActionsVeriFactu
{
    /**
     * Hook principal de acciones
     */
    public function doActions($parameters, &$object, &$action, $hookmanager)
    {
        global $conf, $db;

        // Módulo activo
        if (empty($conf->verifactu->enabled)) {
            return 0;
        }

        // Solo facturas
        if (!is_object($object) || empty($object->element) || $object->element !== 'facture') {
            return 0;
        }

        /*
         * =========================
         * ALTA — FACTURA VALIDADA
         * =========================
         */
        if ($action === 'confirm_validate' || $action === 'validate') {

            // Solo si ya está validada
            if ((int)$object->statut !== 1) {
                return 0;
            }

            // Evitar duplicados
            if ($this->existRegistry($db, $object->id, 'ALTA')) {
                return 0;
            }

            $this->createAltaRegistry($db, $object);
            return 1;
        }

        /*
         * =========================
         * BAJA — FACTURA ANULADA
         * =========================
         */
        if ($action === 'confirm_cancel' || $action === 'cancel') {

            // Solo si la factura estaba validada
            if ((int)$object->statut !== 1) {
                return 0;
            }

            // Evitar doble baja
            if ($this->existRegistry($db, $object->id, 'BAJA')) {
                return 0;
            }

            $this->createBajaRegistry($db, $object);
            return 1;
        }

        return 0;
    }

    /**
     * Bloqueo visual y funcional total
     */
    public function formObjectOptions($parameters, &$object, &$action, $hookmanager)
    {
        global $conf, $db;

        if (empty($conf->verifactu->enabled)) {
            return 0;
        }

        if ($object->element !== 'facture') {
            return 0;
        }

        // Solo facturas validadas
        if ((int)$object->statut !== 1) {
            return 0;
        }

        // Si tiene registro VeriFactu
        if (!$this->existAnyRegistry($db, $object->id)) {
            return 0;
        }

        // Aviso
        print '<div class="warning">';
        print '<strong>⚠ Factura protegida por VeriFactu</strong><br>';
        print 'Factura registrada conforme al RD 1007/2023. No puede ser modificada.';
        print '</div>';

        // Acciones bloqueadas
        $blocked = ['edit', 'delete', 'modif', 'reopen', 'confirm_delete'];

        if (in_array($action, $blocked)) {
            accessforbidden();
        }

        return 0;
    }

    /**
     * Oculta botones de acción
     */
    public function addMoreActionsButtons($parameters, &$object, &$action, $hookmanager)
    {
        global $conf, $db;

        if (empty($conf->verifactu->enabled)) {
            return 0;
        }

        if ($object->element !== 'facture' || (int)$object->statut !== 1) {
            return 0;
        }

        if (!$this->existAnyRegistry($db, $object->id)) {
            return 0;
        }

        print '<style>
            .butAction, .butActionDelete {
                display:none !important;
            }
        </style>';

        return 0;
    }

    /* ============================================================
     * MÉTODOS PRIVADOS
     * ============================================================
     */

    /**
     * ¿Existe registro de un tipo concreto?
     */
    private function existRegistry($db, $factureId, $type)
    {
        $sql = "SELECT rowid FROM ".MAIN_DB_PREFIX."verifactu_registry
                WHERE fk_facture = ".((int)$factureId)."
                AND record_type = '".$db->escape($type)."'
                LIMIT 1";

        $resql = $db->query($sql);
        return ($resql && $db->num_rows($resql) > 0);
    }

    /**
     * ¿Existe cualquier registro para la factura?
     */
    private function existAnyRegistry($db, $factureId)
    {
        $sql = "SELECT rowid FROM ".MAIN_DB_PREFIX."verifactu_registry
                WHERE fk_facture = ".((int)$factureId)."
                LIMIT 1";

        $resql = $db->query($sql);
        return ($resql && $db->num_rows($resql) > 0);
    }

    /**
     * Crear registro ALTA
     */
    private function createAltaRegistry($db, $facture)
    {
        require_once DOL_DOCUMENT_ROOT.'/custom/verifactu/class/VeriFactuHash.class.php';

        $previousHash = $this->getLastHash($db);
        $hash = VeriFactuHash::calculate($facture, $previousHash);

        $sql = "INSERT INTO ".MAIN_DB_PREFIX."verifactu_registry (
                    fk_facture,
                    record_type,
                    hash_actual,
                    hash_anterior,
                    date_creation,
                    total_ttc,
                    aeat_status
                ) VALUES (
                    ".((int)$facture->id).",
                    'ALTA',
                    '".$db->escape($hash)."',
                    ".($previousHash ? "'".$db->escape($previousHash)."'" : "NULL").",
                    '".$db->idate(dol_now())."',
                    ".((float)$facture->total_ttc).",
                    'PENDING'
                )";

        $db->query($sql);
    }

    /**
     * Crear registro BAJA
     */
    private function createBajaRegistry($db, $facture)
    {
        require_once DOL_DOCUMENT_ROOT.'/custom/verifactu/class/VeriFactuHash.class.php';

        // Hash original ALTA
        $sql = "SELECT hash_actual FROM ".MAIN_DB_PREFIX."verifactu_registry
                WHERE fk_facture = ".((int)$facture->id)."
                AND record_type = 'ALTA'
                ORDER BY rowid ASC LIMIT 1";

        $resql = $db->query($sql);
        if (!$resql || !$db->num_rows($resql)) {
            return;
        }

        $obj = $db->fetch_object($resql);
        $originalHash = $obj->hash_actual;

        $previousHash = $this->getLastHash($db);
        $hash = VeriFactuHash::calculateCancel($facture, $previousHash, $originalHash);

        $sql = "INSERT INTO ".MAIN_DB_PREFIX."verifactu_registry (
                    fk_facture,
                    fk_facture_origin,
                    record_type,
                    hash_actual,
                    hash_anterior,
                    date_creation,
                    total_ttc,
                    aeat_status
                ) VALUES (
                    ".((int)$facture->id).",
                    ".((int)$facture->id).",
                    'BAJA',
                    '".$db->escape($hash)."',
                    '".$db->escape($previousHash)."',
                    '".$db->idate(dol_now())."',
                    ".((float)$facture->total_ttc).",
                    'PENDING'
                )";

        $db->query($sql);
    }

    /**
     * Obtener el último hash de la cadena
     */
    private function getLastHash($db)
    {
        $sql = "SELECT hash_actual FROM ".MAIN_DB_PREFIX."verifactu_registry
                ORDER BY rowid DESC LIMIT 1";

        $resql = $db->query($sql);
        if ($resql && $obj = $db->fetch_object($resql)) {
            return $obj->hash_actual;
        }
        return null;
    }
}
