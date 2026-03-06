<?php
global $conf;

$verifactuMode = $conf->global->VERIFACTU_MODE ?? 'NOSEND';

if (!is_array($records) || empty($records)) {
    print '<div class="warning">No existen registros VeriFactu para los criterios seleccionados.</div>';
    return;
}
?>

<?php if ($verifactuMode === 'NOSEND'): ?>
    <div class="info">
        🗂️ <strong>Modo conservación interna activado</strong>.<br>
        Los registros VeriFactu se almacenan de forma inalterable y estarán disponibles
        en caso de requerimiento de la AEAT.
    </div>
<?php else: ?>
    <div class="info">
        📡 <strong>Modo envío a AEAT activado</strong>.<br>
        Las facturas pueden enviarse automáticamente o manualmente a la AEAT.
    </div>
<?php endif; ?>

<style>
.verifactu-error { background-color:#ffe6e6 !important; }
.verifactu-muted { color:#999;font-style:italic; }
.hash { font-family:monospace;font-size:11px;word-break:break-all; }
</style>

<table class="liste centpercent">
<tr class="liste_titre">
    <th width="40">ID</th>
    <th>Factura</th>
    <th width="70">Tipo</th>
    <th width="130">Fecha</th>
    <th width="80">Total</th>
    <th>Hash actual</th>
    <th>Hash anterior</th>
    <th width="90">XML</th>
    <th width="90">Firma</th>
    <th width="90">AEAT</th>
    <th width="120">CSV</th>
    <th width="120">Enviado</th>
    <th width="60">Cadena</th>
</tr>

<?php foreach ($records as $r):

    $type = strtoupper(trim($r->record_type ?? 'ALTA'));

    $chainOK  = true;
    $chainMsg = '';
    if (!empty($validationMap[$r->rowid]) && $validationMap[$r->rowid]['status'] !== 'OK') {
        $chainOK  = false;
        $chainMsg = $validationMap[$r->rowid]['message'];
    }
?>

<tr class="<?php echo $chainOK ? '' : 'verifactu-error'; ?>">

<td class="center"><?php echo (int)$r->rowid; ?></td>

<td>
    <a href="<?php echo DOL_URL_ROOT; ?>/compta/facture/card.php?facid=<?php echo (int)$r->fk_facture; ?>">
        <?php echo dol_escape_htmltag($r->ref); ?>
    </a>
</td>

<td class="center">
    <span class="badge <?php echo $type === 'ALTA' ? 'badge-status4' : 'badge-status8'; ?>">
        <?php echo $type; ?>
    </span>
</td>

<td class="center"><?php echo dol_print_date($r->date_creation,'dayhour'); ?></td>
<td class="right"><?php echo price($r->total_ttc); ?></td>

<td class="hash"><?php echo dol_escape_htmltag($r->hash_actual); ?></td>

<td class="hash">
<?php
echo $r->hash_anterior
    ? dol_escape_htmltag($r->hash_anterior)
    : '<span class="verifactu-muted">Inicio cadena</span>';
?>
</td>

<!-- ===============================
     XML / ACCIONES
     =============================== -->
<td class="center">

<?php if ($r->has_vf_xml): ?>

    <a class="butAction"
       title="XML original (sin firmar)"
       target="_blank"
       href="<?php echo DOL_URL_ROOT; ?>/document.php?modulepart=verifactu&file=<?php
            echo urlencode('XMLverifactu/'.$r->xml_vf_path); ?>">
        XML
    </a>

    <br>

    <?php if ($r->has_signed_xml): ?>
        <a class="butAction"
           title="XML firmado"
           target="_blank"
           href="<?php echo DOL_URL_ROOT; ?>/document.php?modulepart=verifactu&file=<?php
                echo urlencode('XMLverifactu/'.$r->xml_signed_path); ?>">
            FIRMADO
        </a>
    <?php endif; ?>

    <?php if (
        $verifactuMode === 'SEND'
        && $r->has_signed_xml
        && empty($r->aeat_status)
    ): ?>
        <br>
        <a class="butAction"
           title="Preparar payload AEAT"
           href="<?php echo $_SERVER['PHP_SELF']; ?>?action=prepare_aeat&id=<?php echo (int)$r->rowid; ?>&token=<?php echo newToken(); ?>">
            PREPARAR
        </a>
    <?php endif; ?>

    <?php if (
        $verifactuMode === 'SEND'
        && in_array($r->aeat_status, array('PENDING','ERROR','REJECTED'), true)
    ): ?>
        <br>
        <a class="butAction"
           title="Reintentar envío manual a la AEAT"
           href="<?php echo $_SERVER['PHP_SELF']; ?>?action=send_aeat&id=<?php echo (int)$r->rowid; ?>&token=<?php echo newToken(); ?>">
            🔁 REINTENTAR AEAT
        </a>
    <?php endif; ?>

<?php else: ?>
    <span class="verifactu-muted">—</span>
<?php endif; ?>

</td>

<td class="center">
<?php
if ($r->has_signed_xml && $r->signature_status === 'XADES-T') {
    print '<span class="badge badge-success">XAdES-T</span>';
} elseif ($r->has_signed_xml) {
    print '<span class="badge badge-info">XAdES-BES</span>';
} else {
    print '<span class="verifactu-muted">—</span>';
}
?>
</td>

<td class="center">
<?php
if ($verifactuMode === 'NOSEND') {
    print '<span class="badge badge-info">🗂️ Conservado</span>';
} else {
    if ($r->aeat_status === 'ACCEPTED') {
        print '<span class="badge badge-success">OK</span>';
    } elseif ($r->aeat_status === 'PENDING') {
        print '<span class="badge badge-warning">PENDING</span>';
    } elseif ($r->aeat_status === 'REJECTED') {
        print '<span class="badge badge-danger">RECHAZADO</span>';
    } elseif ($r->aeat_status === 'ERROR') {
        print '<span class="badge badge-danger">ERROR</span>';
    } else {
        print '<span class="verifactu-muted">—</span>';
    }
}
?>
</td>

<td class="center"><?php echo $r->aeat_csv ?: '<span class="verifactu-muted">—</span>'; ?></td>

<td class="center">
<?php
echo $r->aeat_sent_at
    ? dol_print_date($r->aeat_sent_at,'dayhour')
    : '<span class="verifactu-muted">—</span>';
?>
</td>

<td class="center">
<?php if ($chainOK): ?>
    <span style="color:#2b7a0b;font-weight:bold;">✔ OK</span>
<?php else: ?>
    <span style="color:#b00020;font-weight:bold;"
          title="<?php echo dol_escape_htmltag($chainMsg); ?>">✖</span>
<?php endif; ?>
</td>

</tr>
<?php endforeach; ?>
</table>

<?php
if (!empty($total) && $total > $limit) {
    print '<div class="pagination">';
    print_fleche_navigation($page, $total, $limit);
    print '</div>';
}
