<?php
/**
 * Module Verifactu
 */

defined('DOL_DOCUMENT_ROOT') || die();

require_once DOL_DOCUMENT_ROOT . '/core/modules/DolibarrModules.class.php';

class modVerifactu extends DolibarrModules
{
    /**
     * Constructor
     */
    public function __construct($db)
    {
        global $conf;

        $this->db = $db;

        // --------------------------------------------------
        // IDENTIFICACIÓN
        // --------------------------------------------------
        $this->numero       = 190050;
        $this->rights_class = 'verifactu';
        $this->family       = 'base';
        $this->name         = 'Verifactu';
        $this->description  = 'Módulo VeriFactu AEAT';
        $this->version      = '1.0.1';
        $this->editor_name  = 'Utopia.es';
        $this->editor_url   = 'https://utopia.es';

        $this->const_name = 'MAIN_MODULE_VERIFACTU';

        // --------------------------------------------------
        // PARTES DEL MÓDULO (CLAVE PARA LOS PDF)
        // --------------------------------------------------
        $this->module_parts = array(
            'triggers' => 1,
              'hooks' => array(
              'invoicecard',
              'takeposfrontend'  ),
              'models'   => 1
        );

		
		
        // Directorios propios del módulo
        $this->dirs = array(
            '/verifactu/temp'
        );

        // --------------------------------------------------
        // CONFIGURACIÓN / I18N
        // --------------------------------------------------
        $this->config_page_url = array('verifactu_setup.php@verifactu');
        $this->langfiles       = array('verifactu@verifactu');

        // --------------------------------------------------
        // REQUISITOS
        // --------------------------------------------------
        $this->phpmin                = array(8, 0);
        $this->need_dolibarr_version = array(15, 0);

        // --------------------------------------------------
        // CONSTANTES
        // --------------------------------------------------
        $this->const = array();

        // --- EXISTENTES ---
        $this->const[] = array(
            0,
            'VERIFACTU_PFX_PATH',
            'chaine',
            '',
            'Ruta certificado PFX VeriFactu',
            1
        );

        $this->const[] = array(
            0,
            'VERIFACTU_PFX_PASSWORD',
            'chaine',
            '',
            'Password certificado PFX VeriFactu',
            1
        );

        $this->const[] = array(
            0,
            'VERIFACTU_TSA_URL',
            'chaine',
            '',
            'URL servidor TSA (XAdES-T)',
            0
        );

        $this->const[] = array(
            0,
            'VERIFACTU_TSA_USER',
            'chaine',
            '',
            'Usuario TSA',
            0
        );

        $this->const[] = array(
            0,
            'VERIFACTU_TSA_PASSWORD',
            'chaine',
            '',
            'Password TSA',
            0
        );

        // --------------------------------------------------
        // 🔥 NUEVAS CONSTANTES – ENTORNOS AEAT VERIFACTU
        // --------------------------------------------------

        // Entorno de funcionamiento: SANDBOX / REAL
        $this->const[] = array(
            0,
            'VERIFACTU_ENVIRONMENT',
            'chaine',
            'SANDBOX',
            'Entorno VeriFactu (SANDBOX / REAL)',
            1
        );

        // Endpoint AEAT Sandbox (valor por defecto)
        $this->const[] = array(
            0,
            'VERIFACTU_AEAT_SANDBOX_URL',
            'chaine',
            'https://prewww1.aeat.es/verifactu/sandbox',
            'URL endpoint AEAT VeriFactu (Sandbox)',
            0
        );

        // Endpoint AEAT Producción (OBLIGATORIO en REAL)
        $this->const[] = array(
            0,
            'VERIFACTU_AEAT_PROD_URL',
            'chaine',
            '',
            'URL endpoint AEAT VeriFactu (Producción)',
            0
        );

        // --------------------------------------------------
        // DERECHOS
        // --------------------------------------------------
        $this->rights = array();
        $r = 0;

        $this->rights[$r++] = array(
            190001,
            'Leer registros VeriFactu',
            'r',
            1,
            'read'
        );

        $this->rights[$r++] = array(
            190002,
            'Administrar VeriFactu',
            'w',
            0,
            'admin'
        );

        // --------------------------------------------------
        // MENÚ
        // --------------------------------------------------
        $this->menu = array();

        $this->menu[] = array(
            'fk_menu'  => 'fk_mainmenu=home',
            'type'     => 'left',
            'titre'    => 'VeriFactu',
            'mainmenu' => 'home',
            'leftmenu' => 'verifactu',
            'url'      => '/custom/verifactu/verifactu.index.php',
            'langs'    => 'verifactu@verifactu',
            'position' => 100,
            'enabled'  => '$conf->verifactu->enabled',
            'perms'    => '$user->rights->verifactu->read',
            'picto'    => 'fa-file-signature'
        );
    }

    /**
     * Activación del módulo
     */
    public function init($options = '')
    {
        global $conf;

        // INIT estándar
        $result = $this->_init(array(), $options);
        if ($result < 0) {
            return -1;
        }

        // Crear tablas
        $this->_load_tables('/verifactu/sql/');

        // Registrar modelo PDF
        $sql = "SELECT rowid
                FROM ".MAIN_DB_PREFIX."document_model
                WHERE nom = 'verifactu_crabe'
                  AND type = 'invoice'
                  AND entity = ".((int) $conf->entity);

        $resql = $this->db->query($sql);

        if ($resql && $this->db->num_rows($resql) == 0) {
            $sql = "INSERT INTO ".MAIN_DB_PREFIX."document_model
                    (nom, file, type, entity, libelle, description)
                    VALUES (
                        'verifactu_crabe',
                        'verifactu_crabe',
                        'invoice',
                        ".((int) $conf->entity).",
                        'Crabe + VeriFactu',
                        'Modelo Crabe con QR y registro VeriFactu AEAT'
                    )";
            $this->db->query($sql);
        }

        // Trigger bloqueo borrado facturas
        $sqlTrigger = "
            CREATE TRIGGER trg_block_delete_facture_verifactu
            BEFORE DELETE ON llx_facture
            FOR EACH ROW
            BEGIN
                IF EXISTS (
                    SELECT 1
                    FROM llx_verifactu_registry
                    WHERE fk_facture = OLD.rowid
                ) THEN
                    SIGNAL SQLSTATE '45000'
                    SET MESSAGE_TEXT = 'No se puede eliminar esta factura porque está registrada en VeriFactu. Utilice una factura rectificativa.';
                END IF;
            END
        ";

        $check = $this->db->query("
            SELECT TRIGGER_NAME
            FROM INFORMATION_SCHEMA.TRIGGERS
            WHERE TRIGGER_SCHEMA = DATABASE()
            AND TRIGGER_NAME = 'trg_block_delete_facture_verifactu'
        ");

        if ($check && $this->db->num_rows($check) === 0) {
            $this->db->query($sqlTrigger);
        }

        return 1;
    }

    /**
     * Desinstalación del módulo
     */
    public function remove($options = '')
    {
        $this->db->query("DROP TRIGGER IF EXISTS trg_block_delete_facture_verifactu");
        return $this->_remove(array(), $options);
    }
}
