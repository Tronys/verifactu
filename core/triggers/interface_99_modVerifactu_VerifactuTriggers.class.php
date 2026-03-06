<?php
/**
 * Triggers del módulo VeriFactu
 * Implementación FINAL y DEFINITIVA
 *
 * - Bloquea borrar/modificar facturas registradas
 * - Al validar factura:
 *   1) Registro ALTA + XML
 *   2) Firma automática SIEMPRE (XAdES-BES / XAdES-T)
 *   3) Si modo SEND => envío automático AEAT
 *   4) Fallback a PENDING si falla (cron reintenta)
 */

require_once DOL_DOCUMENT_ROOT.'/core/triggers/dolibarrtriggers.class.php';
require_once DOL_DOCUMENT_ROOT.'/compta/facture/class/facture.class.php';

require_once DOL_DOCUMENT_ROOT.'/custom/verifactu/class/VerifactuXmlBuilder.php';
require_once DOL_DOCUMENT_ROOT.'/custom/verifactu/class/VeriFactuRegistry.class.php';
require_once DOL_DOCUMENT_ROOT.'/custom/verifactu/class/VerifactuAeatClient.php';
require_once DOL_DOCUMENT_ROOT.'/custom/verifactu/class/VerifactuXadesSigner.php';

class InterfaceVerifactuTriggers extends DolibarrTriggers
{
    /** @var DoliDB */
    public $db;

    public function __construct($db)
    {
        $this->db = $db;
        $this->name        = 'VerifactuTriggers';
        $this->family      = 'verifactu';
        $this->description = 'Triggers oficiales VeriFactu';
        $this->version     = '1.3.1';
    }

    public function runTrigger($action, $object, $user, $langs, $conf)
    {
        /* =====================================================
         * BLOQUEO BORRAR
         * ===================================================== */
        if ($action === 'BILL_PREDELETE' && !empty($object->element) && $object->element === 'facture') {

            $sql = "SELECT rowid
                    FROM ".MAIN_DB_PREFIX."verifactu_registry
                    WHERE fk_facture = ".(int) $object->id."
                    LIMIT 1";

            $resql = $this->db->query($sql);

            if ($resql && $this->db->num_rows($resql) > 0) {
                setEventMessages(
                    'No se puede eliminar esta factura porque está registrada en VeriFactu. Debe emitir una factura rectificativa.',
                    null,
                    'errors'
                );
                return -1;
            }
        }

        /* =====================================================
         * BLOQUEO MODIFICAR
         * ===================================================== */
        if ($action === 'BILL_PREUPDATE' && !empty($object->element) && $object->element === 'facture') {

            $sql = "SELECT rowid
                    FROM ".MAIN_DB_PREFIX."verifactu_registry
                    WHERE fk_facture = ".(int) $object->id."
                    LIMIT 1";

            $resql = $this->db->query($sql);

            if ($resql && $this->db->num_rows($resql) > 0) {
                setEventMessages(
                    'No se puede modificar esta factura porque está registrada en VeriFactu. Debe emitir una factura rectificativa.',
                    null,
                    'errors'
                );
                return -1;
            }
        }

        /* =====================================================
         * SOLO VALIDACIÓN
         * ===================================================== */
        if ($action !== 'BILL_VALIDATE' || empty($object->element) || $object->element !== 'facture') {
            return 0;
        }

        // =====================================================
        // IMPLEMENTACIÓN: RECARGAR FACTURA DESDE BD (para evitar ref PROV)
        // =====================================================
        // En algunos flujos Dolibarr, el objeto recibido en BILL_VALIDATE puede
        // contener ref provisional (PROVxxx). Para VeriFactu necesitamos SIEMPRE
        // la numeración definitiva, así que recargamos desde BD.
        $facture = new Facture($this->db);
        if ($facture->fetch((int) $object->id) > 0) {
            $object = $facture; // sustituimos el objeto por el recargado con ref definitiva
        }

        // Directorio XML
        $dirVF = DOL_DATA_ROOT.'/verifactu/XMLverifactu';
        if (!is_dir($dirVF)) {
            dol_mkdir($dirVF);
        }

        /* =====================================================
         * EVITAR DUPLICADO
         * ===================================================== */
        $sql = "SELECT rowid
                FROM ".MAIN_DB_PREFIX."verifactu_registry
                WHERE fk_facture = ".(int) $object->id."
                AND record_type = 'ALTA'
                LIMIT 1";

        $resql = $this->db->query($sql);

        if ($resql && $this->db->num_rows($resql) > 0) {
            return 0;
        }

        // Modo
        $vfMode = !empty($conf->global->VERIFACTU_MODE) ? (string) $conf->global->VERIFACTU_MODE : 'NOSEND';

        $this->db->begin();

        try {
            /* =====================================================
             * HASH ANTERIOR
             * ===================================================== */
            $prevHash = null;

            $sql = "SELECT hash_actual
                    FROM ".MAIN_DB_PREFIX."verifactu_registry
                    WHERE entity = ".(int) $conf->entity."
                    ORDER BY rowid DESC
                    LIMIT 1";

            $resPrev = $this->db->query($sql);
            if ($resPrev && ($objPrev = $this->db->fetch_object($resPrev))) {
                $prevHash = $objPrev->hash_actual;
            }

            /* =====================================================
             * REGISTRO ALTA
             * ===================================================== */
            $fechaAlta = date('Y-m-d H:i:s');

            $hashAlta = hash('sha256', implode('|', [
                'ALTA',
                (int) $object->id,
                (string) $object->ref,
                number_format((float) $object->total_ttc, 2, '.', ''),
                $fechaAlta,
                $prevHash ?: ''
            ]));

            $sqlIns = "INSERT INTO ".MAIN_DB_PREFIX."verifactu_registry
                       (entity, fk_facture, record_type, date_creation, total_ttc, hash_actual, hash_anterior)
                       VALUES (
                           ".(int) $conf->entity.",
                           ".(int) $object->id.",
                           'ALTA',
                           '".$this->db->escape($fechaAlta)."',
                           ".((float) $object->total_ttc).",
                           '".$this->db->escape($hashAlta)."',
                           ".($prevHash ? "'".$this->db->escape($prevHash)."'" : "NULL")."
                       )";

            if (!$this->db->query($sqlIns)) {
                throw new Exception('Error insert verifactu_registry: '.$this->db->lasterror());
            }

            $altaId = (int) $this->db->last_insert_id(MAIN_DB_PREFIX.'verifactu_registry');

            /* =====================================================
             * XML ORIGINAL
             * ===================================================== */
            $fechaFactura = !empty($object->date) ? date('Y-m-d', $object->date) : date('Y-m-d');

            $xml = VerifactuXmlBuilder::build([
                'tipo'          => 'ALTA',
                'fecha'         => $fechaAlta,
                'factura'       => (string) $object->ref,
                'fecha_factura' => $fechaFactura,
                'total'         => (float) $object->total_ttc,
                'hash_actual'   => $hashAlta,
                'hash_anterior' => $prevHash,
            ]);

            $fileAlta = 'vf_'.$altaId.'_'.date('Ymd_His').'.xml';
            $fullAlta = $dirVF.'/'.$fileAlta;

            if (file_put_contents($fullAlta, $xml) === false) {
                throw new Exception('No se pudo escribir XML: '.$fullAlta);
            }

            $sqlUpdXml = "UPDATE ".MAIN_DB_PREFIX."verifactu_registry
                          SET xml_vf_path = '".$this->db->escape($fileAlta)."'
                          WHERE rowid = ".(int) $altaId;

            if (!$this->db->query($sqlUpdXml)) {
                throw new Exception('No se pudo actualizar xml_vf_path: '.$this->db->lasterror());
            }

            /* =====================================================
             * FIRMA AUTOMÁTICA (SIEMPRE)
             * ===================================================== */
            $xmlSigned = null;
            $signatureStatus = null;

            $pfxPath = !empty($conf->global->VERIFACTU_PFX_PATH) ? (string) $conf->global->VERIFACTU_PFX_PATH : '';
            $pfxPass = isset($conf->global->VERIFACTU_PFX_PASSWORD) ? (string) $conf->global->VERIFACTU_PFX_PASSWORD : '';

            if (!empty($pfxPath) && file_exists($pfxPath)) {

                $xmlOriginal = file_get_contents($fullAlta);
                if ($xmlOriginal === false) {
                    throw new Exception('No se pudo leer XML para firmar: '.$fullAlta);
                }

                $xmlSigned = VerifactuXadesSigner::sign($xmlOriginal, $pfxPath, $pfxPass);
                $signatureStatus = 'XADES-BES';

                $tsaUrl  = !empty($conf->global->VERIFACTU_TSA_URL) ? trim((string) $conf->global->VERIFACTU_TSA_URL) : '';
                $tsaUser = !empty($conf->global->VERIFACTU_TSA_USER) ? trim((string) $conf->global->VERIFACTU_TSA_USER) : '';
                $tsaPass = isset($conf->global->VERIFACTU_TSA_PASSWORD) ? (string) $conf->global->VERIFACTU_TSA_PASSWORD : '';

                if (!empty($tsaUrl)) {
                    $xmlSigned = VerifactuXadesSigner::addXadesTimestamp($xmlSigned, $tsaUrl, $tsaUser, $tsaPass);
                    $signatureStatus = 'XADES-T';
                }

                $signedFile = 'vf_signed_'.$altaId.'_'.date('Ymd_His').'.xml';
                $fullSigned = $dirVF.'/'.$signedFile;

                if (file_put_contents($fullSigned, $xmlSigned) === false) {
                    throw new Exception('No se pudo escribir XML firmado: '.$fullSigned);
                }

                $sqlUpdSign = "UPDATE ".MAIN_DB_PREFIX."verifactu_registry
                               SET xml_signed_path = '".$this->db->escape($signedFile)."',
                                   signature_status = '".$this->db->escape($signatureStatus)."'
                               WHERE rowid = ".(int) $altaId;

                if (!$this->db->query($sqlUpdSign)) {
                    throw new Exception('No se pudo actualizar xml_signed_path/signature_status: '.$this->db->lasterror());
                }
            } else {
                // Sin PFX: no rompemos la validación, pero dejamos constancia en log
                dol_syslog('VeriFactu: no hay certificado PFX configurado. No se firma automáticamente.', LOG_WARNING);
            }

            /* =====================================================
             * ENVÍO AUTOMÁTICO AEAT (solo SEND y si hay XML firmado)
             * ===================================================== */
            if ($vfMode === 'SEND' && !empty($xmlSigned)) {

                // Siempre dejamos estado por defecto PENDING si estamos en SEND
                $this->db->query("UPDATE ".MAIN_DB_PREFIX."verifactu_registry
                                  SET aeat_status = 'PENDING'
                                  WHERE rowid = ".(int) $altaId);

                try {
                    $registry = new VeriFactuRegistry($this->db);
                    $record   = $registry->fetchById($altaId);

                    if ($record) {
                        $payload = $registry->buildAeatPayload($record);

                        $client = new VerifactuAeatClient($this->db);
                        $result = $client->send($payload);

                        if (!empty($result['status']) && $result['status'] === 'ACCEPTED') {

                            $csv = !empty($result['csv']) ? (string) $result['csv'] : '';

                            $this->db->query(
                                "UPDATE ".MAIN_DB_PREFIX."verifactu_registry
                                 SET aeat_status  = 'ACCEPTED',
                                     aeat_csv     = '".$this->db->escape($csv)."',
                                     aeat_sent_at = '".$this->db->idate(dol_now())."'
                                 WHERE rowid = ".(int) $altaId
                            );

                        } else {
                            // Se queda en PENDING (cron/manual)
                            dol_syslog('VeriFactu: envío AEAT no ACCEPTED, se mantiene PENDING (rowid='.$altaId.')', LOG_WARNING);
                        }
                    } else {
                        dol_syslog('VeriFactu: no se pudo cargar el registro para envío AEAT (rowid='.$altaId.')', LOG_ERR);
                    }

                } catch (Exception $e) {
                    // Se queda en PENDING
                    dol_syslog('VeriFactu: excepción enviando AEAT, queda PENDING (rowid='.$altaId.'): '.$e->getMessage(), LOG_WARNING);
                }
            }

            $this->db->commit();
            return 0;

        } catch (Exception $e) {

            $this->db->rollback();
            dol_syslog('VeriFactu ERROR BILL_VALIDATE: '.$e->getMessage(), LOG_ERR);

            // No bloqueamos la validación de la factura por fallo interno del módulo
            return 0;
        }
    }
}
