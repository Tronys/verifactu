<?php
defined('DOL_DOCUMENT_ROOT') or die();

/**
 * Hooks de interfaz VeriFactu.
 *
 * Responsabilidades:
 *  - Bloquear en UI la modificación/borrado de facturas registradas
 *  - Mostrar aviso visual en la ficha de factura protegida
 *  - Generar el ticket TakePos con QR VeriFactu
 *
 * La creación de registros ALTA/BAJA la gestiona el trigger, no este fichero.
 */
class ActionsVerifactu
{
    /**
     * Bloquea acciones de modificación/borrado en UI cuando la factura
     * ya está registrada en VeriFactu.
     */
    public function doActions($parameters, &$object, &$action, $hookmanager)
    {
        global $db;

        if (empty($parameters['currentcontext']) || $parameters['currentcontext'] !== 'invoicecard') {
            return 0;
        }

        if (!is_object($object) || empty($object->id)) {
            return 0;
        }

        $blocked = ['modif', 'edit', 'confirm_delete'];
        if (!in_array($action, $blocked, true)) {
            return 0;
        }

        if (!$this->isRegistered($db, $object->id)) {
            return 0;
        }

        setEventMessages(
            'No se puede modificar esta factura porque está registrada en VeriFactu. Emita una factura rectificativa.',
            null,
            'errors'
        );

        $action           = '';
        $_GET['action']   = '';
        $_POST['action']  = '';

        return -1;
    }

    /**
     * Muestra aviso visual en la ficha de factura protegida por VeriFactu.
     */
    public function formObjectOptions($parameters, &$object, &$action, $hookmanager)
    {
        global $db;

        if (!is_object($object) || empty($object->id)) {
            return 0;
        }

        if (empty($object->element) || $object->element !== 'facture') {
            return 0;
        }

        // Solo facturas validadas
        if ((int) $object->statut !== 1) {
            return 0;
        }

        if (!$this->isRegistered($db, $object->id)) {
            return 0;
        }

        print '<div class="info">'
            . '<b>Factura protegida por VeriFactu (RD 1007/2023)</b><br>'
            . 'Este documento está registrado en el libro de facturación inmutable y no puede modificarse.'
            . '</div>';

        return 0;
    }

    /**
     * Genera el ticket TakePos con QR VeriFactu.
     */
    public function TakeposReceipt($parameters, &$object, &$action, $hookmanager)
    {
        global $db, $mysoc, $langs;

        if (!is_object($object) || empty($object->id)) {
            return 0;
        }

        $sql = "SELECT hash_actual FROM ".MAIN_DB_PREFIX."verifactu_registry
                WHERE fk_facture = ".(int) $object->id."
                ORDER BY rowid DESC LIMIT 1";

        $resql = $db->query($sql);
        if (!$resql || !$db->num_rows($resql)) {
            return 0;
        }

        $reg = $db->fetch_object($resql);
        if (empty($reg->hash_actual)) {
            return 0;
        }

        $baseUrl = getDolGlobalString('MAIN_URL_ROOT');
        if (empty($baseUrl)) {
            $scheme  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $baseUrl = $scheme . '://' . $_SERVER['HTTP_HOST'];
        }

        $verifyUrl = $baseUrl . '/custom/verifactu/verifactu.card.php?hash=' . urlencode($reg->hash_actual);
        $qrImg     = 'https://api.qrserver.com/v1/create-qr-code/?size=150x150&data=' . urlencode($verifyUrl);

        ob_start();
        ?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Ticket</title>
</head>
<body style="font-family:Arial,sans-serif;font-size:12px">
<center><b><?php echo dol_escape_htmltag($mysoc->name); ?></b></center>
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
<br>
<center>
    <img src="<?php echo $qrImg; ?>" style="width:120px;height:120px"><br>
    <span style="font-size:10px">Factura verificable (VeriFactu)</span>
</center>
<script>window.print();</script>
</body>
</html>
        <?php
        $this->resprints = ob_get_clean();
        return 1;
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function isRegistered($db, $factureId)
    {
        $sql = "SELECT rowid FROM ".MAIN_DB_PREFIX."verifactu_registry
                WHERE fk_facture = ".(int) $factureId." LIMIT 1";
        $res = $db->query($sql);
        return ($res && $db->num_rows($res) > 0);
    }
}
