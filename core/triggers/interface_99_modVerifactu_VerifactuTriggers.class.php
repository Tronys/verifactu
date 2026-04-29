<?php
/**
 * Triggers del módulo VeriFactu
 *
 * - Bloquea borrar/modificar facturas registradas en VeriFactu
 * - Al validar factura (BILL_VALIDATE):
 *   1) Detecta tipo F1/F2 y calcula desglose IVA
 *   2) Calcula Huella según algoritmo AEAT oficial
 *   3) Genera XML VeriFactu y lo almacena
 *   4) Firma automática XAdES-BES / XAdES-T si hay certificado
 *   5) Si modo SEND y XML firmado → envío AEAT (fallback PENDING)
 */

require_once DOL_DOCUMENT_ROOT.'/core/triggers/dolibarrtriggers.class.php';
require_once DOL_DOCUMENT_ROOT.'/compta/facture/class/facture.class.php';

require_once DOL_DOCUMENT_ROOT.'/custom/verifactu/class/VeriFactuHash.class.php';
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
        $this->db          = $db;
        $this->name        = 'VerifactuTriggers';
        $this->family      = 'verifactu';
        $this->description = 'Triggers oficiales VeriFactu';
        $this->version     = '1.4.0';
    }

    public function runTrigger($action, $object, $user, $langs, $conf)
    {
        /* =====================================================
         * BLOQUEO BORRAR
         * ===================================================== */
        if ($action === 'BILL_PREDELETE' && !empty($object->element) && $object->element === 'facture') {

            $sql = "SELECT rowid FROM ".MAIN_DB_PREFIX."verifactu_registry
                    WHERE fk_facture = ".(int) $object->id." LIMIT 1";

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

            $sql = "SELECT rowid FROM ".MAIN_DB_PREFIX."verifactu_registry
                    WHERE fk_facture = ".(int) $object->id." LIMIT 1";

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
         * SÓLO BILL_VALIDATE
         * ===================================================== */
        if ($action !== 'BILL_VALIDATE' || empty($object->element) || $object->element !== 'facture') {
            return 0;
        }

        // Recargar factura para garantizar ref definitiva (evitar PROV…)
        $facture = new Facture($this->db);
        if ($facture->fetch((int) $object->id) <= 0) {
            dol_syslog('VeriFactu: no se pudo cargar la factura id=' . (int) $object->id, LOG_ERR);
            return 0;
        }

        // Preparar directorio de almacenamiento XML
        $dirVF = DOL_DATA_ROOT.'/verifactu/XMLverifactu';
        if (!is_dir($dirVF)) {
            dol_mkdir($dirVF);
        }

        /* =====================================================
         * EVITAR DUPLICADO
         * ===================================================== */
        $sqlDup = "SELECT rowid FROM ".MAIN_DB_PREFIX."verifactu_registry
                   WHERE fk_facture = ".(int) $facture->id." AND record_type = 'ALTA' LIMIT 1";
        $resDup = $this->db->query($sqlDup);
        if ($resDup && $this->db->num_rows($resDup) > 0) {
            return 0;
        }

        $vfMode = !empty($conf->global->VERIFACTU_MODE) ? (string) $conf->global->VERIFACTU_MODE : 'NOSEND';

        $this->db->begin();

        try {
            /* =====================================================
             * DATOS DEL EMISOR (empresa configurada en Dolibarr)
             * ===================================================== */
            $nifEmisor    = (string) ($conf->global->MAIN_INFO_SIREN ?? '');
            $nombreEmisor = (string) ($conf->global->MAIN_INFO_SOCIETE_NOM ?? '');

            /* =====================================================
             * TIPO FACTURA F1 / F2 + DESGLOSE IVA
             * ===================================================== */
            $facture->fetch_lines();
            $tipoFactura = VeriFactuHash::detectTipoFactura($facture);
            $desgloseIva = VeriFactuHash::desgloseIva($facture);

            $cuotaTotal = 0.0;
            foreach ($desgloseIva as $tramo) {
                $cuotaTotal += $tramo['cuota'];
            }

            /* =====================================================
             * HUELLA ANTERIOR (último registro de esta entidad)
             * ===================================================== */
            $prevHash = null;

            $sqlPrev = "SELECT hash_actual FROM ".MAIN_DB_PREFIX."verifactu_registry
                        WHERE entity = ".(int) $conf->entity."
                        ORDER BY rowid DESC LIMIT 1";
            $resPrev = $this->db->query($sqlPrev);
            if ($resPrev && ($objPrev = $this->db->fetch_object($resPrev))) {
                $prevHash = $objPrev->hash_actual;
            }

            /* =====================================================
             * HUELLA (algoritmo oficial AEAT)
             * ===================================================== */
            $fechaAlta   = time();
            $hashActual  = VeriFactuHash::calculate(
                $nifEmisor,
                $facture->ref,
                (int) $facture->date,
                $tipoFactura,
                $cuotaTotal,
                (float) $facture->total_ttc,
                (string) ($prevHash ?? ''),
                $fechaAlta
            );

            $fechaAltaStr = date('Y-m-d H:i:s', $fechaAlta);

            /* =====================================================
             * INSERT REGISTRO
             * ===================================================== */
            $sqlIns = "INSERT INTO ".MAIN_DB_PREFIX."verifactu_registry
                       (entity, fk_facture, record_type, tipo_factura, date_creation,
                        total_ttc, cuota_total, hash_actual, hash_anterior)
                       VALUES (
                           ".(int) $conf->entity.",
                           ".(int) $facture->id.",
                           'ALTA',
                           '".$this->db->escape($tipoFactura)."',
                           '".$this->db->escape($fechaAltaStr)."',
                           ".((float) $facture->total_ttc).",
                           ".((float) $cuotaTotal).",
                           '".$this->db->escape($hashActual)."',
                           ".($prevHash !== null ? "'".$this->db->escape($prevHash)."'" : "NULL")."
                       )";

            if (!$this->db->query($sqlIns)) {
                throw new Exception('Error insert verifactu_registry: '.$this->db->lasterror());
            }

            $altaId = (int) $this->db->last_insert_id(MAIN_DB_PREFIX.'verifactu_registry');

            /* =====================================================
             * DATOS DESTINATARIO (sólo F1)
             * ===================================================== */
            $destinatario = null;
            if ($tipoFactura === 'F1') {
                if (empty($facture->thirdparty) || empty($facture->thirdparty->id)) {
                    $facture->fetch_thirdparty();
                }
                $tp  = $facture->thirdparty;
                $nif = trim((string) ($tp->idprof1 ?? ''));
                if ($nif === '') {
                    $nif = trim((string) ($tp->tva_intra ?? ''));
                }
                if (!empty($nif)) {
                    $destinatario = [
                        'nombre' => (string) ($tp->name ?? ''),
                        'nif'    => $nif,
                    ];
                }
            }

            /* =====================================================
             * XML ORIGINAL
             * ===================================================== */
            $fechaFacturaStr = !empty($facture->date) ? date('Y-m-d', (int) $facture->date) : date('Y-m-d');

            $xml = VerifactuXmlBuilder::build([
                'tipo'           => 'ALTA',
                'fecha'          => $fechaAltaStr,
                'factura'        => (string) $facture->ref,
                'fecha_factura'  => $fechaFacturaStr,
                'tipo_factura'   => $tipoFactura,
                'nif_emisor'     => $nifEmisor,
                'nombre_emisor'  => $nombreEmisor,
                'total'          => (float) $facture->total_ttc,
                'cuota_total'    => $cuotaTotal,
                'huella'         => $hashActual,
                'huella_anterior'=> $prevHash,
                'desglose_iva'   => $desgloseIva,
                'destinatario'   => $destinatario,
            ]);

            $fileAlta = 'vf_'.$altaId.'_'.date('Ymd_His', $fechaAlta).'.xml';
            $fullAlta = $dirVF.'/'.$fileAlta;

            if (file_put_contents($fullAlta, $xml) === false) {
                throw new Exception('No se pudo escribir XML: '.$fullAlta);
            }

            if (!$this->db->query("UPDATE ".MAIN_DB_PREFIX."verifactu_registry
                                   SET xml_vf_path = '".$this->db->escape($fileAlta)."'
                                   WHERE rowid = ".(int) $altaId)) {
                throw new Exception('No se pudo actualizar xml_vf_path: '.$this->db->lasterror());
            }

            /* =====================================================
             * FIRMA AUTOMÁTICA (XAdES-BES / XAdES-T)
             * ===================================================== */
            $xmlSigned       = null;
            $signatureStatus = null;

            $pfxPath = !empty($conf->global->VERIFACTU_PFX_PATH) ? (string) $conf->global->VERIFACTU_PFX_PATH : '';
            $pfxPass = isset($conf->global->VERIFACTU_PFX_PASSWORD) ? (string) $conf->global->VERIFACTU_PFX_PASSWORD : '';

            if (!empty($pfxPath) && file_exists($pfxPath)) {

                $xmlOriginal = file_get_contents($fullAlta);
                if ($xmlOriginal === false) {
                    throw new Exception('No se pudo leer XML para firmar: '.$fullAlta);
                }

                $xmlSigned       = VerifactuXadesSigner::sign($xmlOriginal, $pfxPath, $pfxPass);
                $signatureStatus = 'XADES-BES';

                $tsaUrl  = !empty($conf->global->VERIFACTU_TSA_URL)      ? trim((string) $conf->global->VERIFACTU_TSA_URL)      : '';
                $tsaUser = !empty($conf->global->VERIFACTU_TSA_USER)     ? trim((string) $conf->global->VERIFACTU_TSA_USER)     : '';
                $tsaPass = isset($conf->global->VERIFACTU_TSA_PASSWORD)  ? (string) $conf->global->VERIFACTU_TSA_PASSWORD       : '';

                if (!empty($tsaUrl)) {
                    $xmlSigned       = VerifactuXadesSigner::addXadesTimestamp($xmlSigned, $tsaUrl, $tsaUser, $tsaPass);
                    $signatureStatus = 'XADES-T';
                }

                $signedFile = 'vf_signed_'.$altaId.'_'.date('Ymd_His', $fechaAlta).'.xml';
                $fullSigned = $dirVF.'/'.$signedFile;

                if (file_put_contents($fullSigned, $xmlSigned) === false) {
                    throw new Exception('No se pudo escribir XML firmado: '.$fullSigned);
                }

                if (!$this->db->query("UPDATE ".MAIN_DB_PREFIX."verifactu_registry
                                       SET xml_signed_path   = '".$this->db->escape($signedFile)."',
                                           signature_status  = '".$this->db->escape($signatureStatus)."'
                                       WHERE rowid = ".(int) $altaId)) {
                    throw new Exception('Error actualizar xml_signed_path: '.$this->db->lasterror());
                }

            } else {
                dol_syslog('VeriFactu: sin certificado PFX — XML no firmado automáticamente (rowid='.$altaId.').', LOG_WARNING);
            }

            /* =====================================================
             * ENVÍO AUTOMÁTICO AEAT (sólo SEND + XML firmado)
             * ===================================================== */
            if ($vfMode === 'SEND' && !empty($xmlSigned)) {

                $this->db->query("UPDATE ".MAIN_DB_PREFIX."verifactu_registry
                                  SET aeat_status = 'PENDING' WHERE rowid = ".(int) $altaId);

                try {
                    $registry = new VeriFactuRegistry($this->db);
                    $record   = $registry->fetchById($altaId);

                    if ($record) {
                        $payload = $registry->buildAeatPayload($record);
                        $client  = new VerifactuAeatClient($this->db);
                        $result  = $client->send($payload);

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
                            dol_syslog('VeriFactu: envío no ACCEPTED, queda PENDING (rowid='.$altaId.')', LOG_WARNING);
                        }
                    }

                } catch (Exception $e) {
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
