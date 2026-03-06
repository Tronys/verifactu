<?php

defined('DOL_DOCUMENT_ROOT') || die();

class VeriFactuRegistry
{
    /** @var DoliDB */
    private $db;

    /** @var string */
    private $baseXmlDir;

    public function __construct($db)
    {
        $this->db = $db;

        // Directorio base REAL donde Dolibarr guarda los XML
        $this->baseXmlDir = DOL_DATA_ROOT . '/verifactu/XMLverifactu/';
    }

    /* =====================================================
     * HELPERS DE ESTADO (XML / FIRMA)
     * ===================================================== */

    /**
     * Devuelve true si existe XML VeriFactu generado
     */
    public function hasXml($obj = null): bool
    {
        if (!$obj || empty($obj->xml_vf_path)) {
            return false;
        }

        $path = $this->baseXmlDir . $obj->xml_vf_path;
        return is_readable($path);
    }

    /**
     * Devuelve true si existe XML firmado
     */
    public function hasSignedXml($obj = null): bool
    {
        if (!$obj || empty($obj->xml_signed_path)) {
            return false;
        }

        $path = $this->baseXmlDir . $obj->xml_signed_path;
        return is_readable($path);
    }

    /**
     * Devuelve true si el registro está firmado
     */
    public function isSigned($obj = null): bool
    {
        return $this->hasSignedXml($obj);
    }

    /**
     * Devuelve el contenido del XML original
     */
    public function getXmlContent($obj): ?string
    {
        if (!$obj || empty($obj->xml_vf_path)) {
            return null;
        }

        $path = $this->baseXmlDir . $obj->xml_vf_path;
        return is_readable($path) ? file_get_contents($path) : null;
    }

    /**
     * Devuelve el contenido del XML firmado
     */
    public function getSignedXmlContent($obj): ?string
    {
        if (!$obj || empty($obj->xml_signed_path)) {
            return null;
        }

        $path = $this->baseXmlDir . $obj->xml_signed_path;
        return is_readable($path) ? file_get_contents($path) : null;
    }

    /* =====================================================
     * PAYLOAD AEAT (PASO 2)
     * ===================================================== */

    /**
     * Construye el payload AEAT a partir del XML firmado
     */
    public function buildAeatPayload($record): array
    {
        if (empty($record->xml_signed_path)) {
            throw new Exception('No existe XML firmado para preparar el payload AEAT');
        }

        $signedPath = $this->baseXmlDir . $record->xml_signed_path;

        if (!is_readable($signedPath)) {
            throw new Exception('El fichero XML firmado no existe o no es accesible');
        }

        // 1) Leer XML firmado
        $signedXml = file_get_contents($signedPath);
        if ($signedXml === false) {
            throw new Exception('No se pudo leer el XML firmado');
        }

        // 2) Hash SHA256 del XML firmado
        $hashSha256 = hash('sha256', $signedXml);

        // 3) Base64 del XML firmado
        $xmlB64 = base64_encode($signedXml);

        return [
            'schema'  => 'verifactu',
            'version' => '1.0',

            'registro' => [
                'id'             => (int) $record->rowid,
                'fk_facture'     => (int) $record->fk_facture,
                'ref_facture'    => (string) $record->ref,
                'record_type'    => (string) $record->record_type,
                'fecha_registro' => dol_print_date($record->date_creation, '%Y-%m-%dT%H:%M:%S'),
                'total_ttc'      => (float) $record->total_ttc,
            ],

            'firma' => [
                'tipo'        => $record->signature_status ?? '',
                'hash_sha256' => $hashSha256,
            ],

            'contenido' => [
                'xml_firmado_b64' => $xmlB64,
            ],

            'estado' => [
                'aeat_status' => 'PENDING',
                'prepared_at' => dol_now(),
            ],
        ];
    }

    /* =====================================================
     * CONSULTAS
     * ===================================================== */

    /**
     * Número total de registros (para paginación)
     */
    public function countFiltered($ref = null, $dateFrom = null, $dateTo = null)
    {
        $sql = "SELECT COUNT(*) as nb
                FROM ".MAIN_DB_PREFIX."verifactu_registry r
                LEFT JOIN ".MAIN_DB_PREFIX."facture f ON f.rowid = r.fk_facture
                WHERE 1=1";

        if (!empty($ref)) {
            $sql .= " AND f.ref LIKE '%".$this->db->escape($ref)."%'";
        }

        if (!empty($dateFrom)) {
            $sql .= " AND r.date_creation >= '".$this->db->escape($dateFrom)." 00:00:00'";
        }

        if (!empty($dateTo)) {
            $sql .= " AND r.date_creation <= '".$this->db->escape($dateTo)." 23:59:59'";
        }

        $resql = $this->db->query($sql);
        if ($resql) {
            $obj = $this->db->fetch_object($resql);
            return (int) $obj->nb;
        }

        return 0;
    }

    /**
     * Obtiene registros con filtros + paginación
     */
    public function fetchFiltered($ref = null, $dateFrom = null, $dateTo = null, $limit = 25, $offset = 0)
    {
        $results = [];

        $sql = "SELECT
                    r.rowid,
                    r.entity,
                    r.fk_facture,
                    r.record_type,
                    r.date_creation,
                    r.total_ttc,
                    r.hash_actual,
                    r.hash_anterior,

                    /* XML */
                    r.xml_vf_path,
                    r.xml_signed_path,

                    /* AEAT */
                    r.aeat_status,
                    r.aeat_csv,
                    r.aeat_sent_at,
                    r.aeat_message,

                    /* Factura */
                    f.ref
                FROM ".MAIN_DB_PREFIX."verifactu_registry r
                LEFT JOIN ".MAIN_DB_PREFIX."facture f ON f.rowid = r.fk_facture
                WHERE 1=1";

        if (!empty($ref)) {
            $sql .= " AND f.ref LIKE '%".$this->db->escape($ref)."%'";
        }

        if (!empty($dateFrom)) {
            $sql .= " AND r.date_creation >= '".$this->db->escape($dateFrom)." 00:00:00'";
        }

        if (!empty($dateTo)) {
            $sql .= " AND r.date_creation <= '".$this->db->escape($dateTo)." 23:59:59'";
        }

        $sql .= " ORDER BY r.rowid DESC";
        $sql .= $this->db->plimit($limit, $offset);

        $resql = $this->db->query($sql);
        if ($resql) {
            while ($obj = $this->db->fetch_object($resql)) {
                $results[] = $obj;
            }
        }

        return $results;
    }

    /**
     * Obtener un registro por ID
     */
    public function fetchById($id)
    {
        $sql = "SELECT
                    r.rowid,
                    r.entity,
                    r.fk_facture,
                    r.record_type,
                    r.date_creation,
                    r.total_ttc,
                    r.hash_actual,
                    r.hash_anterior,

                    /* XML */
                    r.xml_vf_path,
                    r.xml_signed_path,

                    /* AEAT */
                    r.aeat_status,
                    r.aeat_csv,
                    r.aeat_sent_at,
                    r.aeat_message,

                    /* Factura */
                    f.ref
                FROM ".MAIN_DB_PREFIX."verifactu_registry r
                LEFT JOIN ".MAIN_DB_PREFIX."facture f ON f.rowid = r.fk_facture
                WHERE r.rowid = ".((int) $id);

        $resql = $this->db->query($sql);
        if ($resql && $this->db->num_rows($resql)) {
            return $this->db->fetch_object($resql);
        }

        return null;
    }
}
