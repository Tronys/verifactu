<?php
/**
 * Hooks de interfaz para el módulo VeriFactu
 */

class ActionsVerifactu
{
    /**
     * Intercepta acciones de la UI
     */
    public function doActions($parameters, &$object, &$action, $hookmanager)
    {
        global $db;

        // Solo en ficha de factura
        if (empty($parameters['currentcontext']) || $parameters['currentcontext'] !== 'invoicecard') {
            return 0;
        }

        // ==========================================
        // Función interna: ¿Factura registrada?
        // ==========================================
        $isRegisteredInVerifactu = function($facid) use ($db) {
            if (empty($facid)) return false;

            $sql = "SELECT COUNT(*) as nb
                    FROM ".MAIN_DB_PREFIX."verifactu_registry
                    WHERE fk_facture = ".((int)$facid);

            $res = $db->query($sql);
            if ($res) {
                $obj = $db->fetch_object($res);
                return (!empty($obj->nb) && (int)$obj->nb > 0);
            }
            return false;
        };

        // ======================================================
        // 🔒 BLOQUEAR MODIFICAR FACTURA (UI)
        // ======================================================
        if (in_array($action, ['modif', 'edit'], true)) {

            if (!empty($object->id) && $isRegisteredInVerifactu($object->id)) {

                setEventMessages(
                    'No se puede modificar esta factura porque está registrada en VeriFactu. '
                    .'Para corregirla, emita una factura rectificativa.',
                    null,
                    'errors'
                );

                $action = '';
                $_GET['action'] = '';
                $_POST['action'] = '';

                return -1;
            }
        }

        // ======================================================
        // 🔒 BLOQUEAR BORRADO DE FACTURAS (UI)
        // ======================================================
        if ($action === 'confirm_delete') {

            if (!empty($object->id) && $isRegisteredInVerifactu($object->id)) {

                setEventMessages(
                    'No se puede eliminar esta factura porque está registrada en VeriFactu. '
                    .'Para corregirla, emita una factura rectificativa.',
                    null,
                    'errors'
                );

                $action = '';
                $_GET['action'] = '';
                $_POST['action'] = '';

                return -1;
            }
        }

        return 0;
    }



    /**
     * ======================================================
     * 🧾 TAKEPOS (Dolibarr 22)
     * Generar ticket COMPLETO con QR VeriFactu
     * ======================================================
     */
    public function TakeposReceipt($parameters, &$object, &$action, $hookmanager)
    {
        global $db, $conf, $langs, $mysoc;

        // $object es Facture
        if (empty($object) || empty($object->id)) {
            return 0;
        }

        // Buscar registro VeriFactu
        $sql = "SELECT hash_actual
                FROM ".MAIN_DB_PREFIX."verifactu_registry
                WHERE fk_facture = ".((int)$object->id)."
                ORDER BY rowid DESC
                LIMIT 1";

        $resql = $db->query($sql);
        if (!$resql || !$db->num_rows($resql)) {
            return 0; // Sin VeriFactu → usar ticket estándar
        }

        $reg = $db->fetch_object($resql);
        if (empty($reg->hash_actual)) {
            return 0;
        }

// ==================================================
// URL ABSOLUTA de verificación (OBLIGATORIO PARA QR)
// ==================================================
$baseUrl = getDolGlobalString('MAIN_URL_ROOT');

if (empty($baseUrl)) {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $baseUrl = $scheme.'://'.$_SERVER['HTTP_HOST'];
}

$verifyUrl = $baseUrl.'/custom/verifactu/verifactu.card.php?hash='.$reg->hash_actual;

        // QR externo (compatible con TakePOS)
        $qrImg = 'https://api.qrserver.com/v1/create-qr-code/?size=150x150&data='
               . urlencode($verifyUrl);

        // ==================================================
        // CONSTRUIR HTML COMPLETO DEL TICKET
        // ==================================================
        ob_start();
        ?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="robots" content="noindex,nofollow">
<title>Ticket</title>
</head>
<body style="font-family: Arial, sans-serif; font-size:12px">

<center>
    <b><?php echo dol_escape_htmltag($mysoc->name); ?></b>
</center>

<br>

<div style="text-align:right">
    <?php echo $langs->trans('Date').' '.dol_print_date($object->date, 'day'); ?><br>
    <?php echo dol_escape_htmltag($object->ref); ?>
</div>

<hr>

<table width="100%">
<?php foreach ($object->lines as $line): ?>
<tr>
    <td><?php echo dol_escape_htmltag($line->desc ?: $line->product_label); ?></td>
    <td align="right"><?php echo $line->qty; ?></td>
    <td align="right"><?php echo price($line->total_ttc); ?></td>
</tr>
<?php endforeach; ?>
</table>

<hr>

<table width="100%">
<tr>
    <td>Total</td>
    <td align="right"><?php echo price($object->total_ttc); ?></td>
</tr>
</table>

<br><br>

<center>
    <img src="<?php echo $qrImg; ?>" style="width:120px;height:120px"><br>
    <span style="font-size:10px">
        Factura verificable (VeriFactu)
    </span>
</center>

<script>
    window.print();
</script>

</body>
</html>
<?php
        $html = ob_get_clean();

        // DEVOLVER HTML COMPLETO
        $this->resprints = $html;
        return 1;
    }



    /**
     * (Se mantiene por compatibilidad, aunque TakePOS NO lo usa en v22)
     */
    public function addHtmlContent($parameters, &$object, &$action, $hookmanager)
    {
        global $db;

        if (empty($parameters['currentcontext']) || $parameters['currentcontext'] !== 'takeposreceipt') {
            return 0;
        }

        $html = "\n<!-- VERIFACTU TAKEPOS HOOK OK -->\n";

        $facid = (int) ($parameters['facid'] ?? 0);
        if ($facid <= 0) {
            $this->resprints = $html;
            return 1;
        }

        $this->resprints = $html;
        return 1;
    }
}
