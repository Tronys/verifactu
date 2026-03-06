<?php
/**
 * Informe PDF de Integridad VeriFactu (firmado)
 *
 * - Genera un PDF con:
 *   Portada + marco legal + identificación del sistema + resumen + listado + validación de cadena
 * - Firma el PDF (PAdES) usando el certificado PFX configurado (VERIFACTU_PFX_PATH / VERIFACTU_PFX_PASSWORD)
 * - En modo NOSEND, no muestra columnas AEAT/CSV (no aplican)
 */

define('NOCSRFCHECK', 1);

// ======================================================
// CARGA ENTORNO DOLIBARR
// ======================================================
$res = 0;
if (!$res && file_exists(__DIR__ . '/../main.inc.php')) $res = require __DIR__ . '/../main.inc.php';
if (!$res && file_exists(__DIR__ . '/../../main.inc.php')) $res = require __DIR__ . '/../../main.inc.php';
if (!$res) die('Dolibarr environment not found');

// ======================================================
// SEGURIDAD
// ======================================================
if (empty($user->rights->verifactu->read)) {
    accessforbidden();
}

// ======================================================
// CLASES
// ======================================================
require_once DOL_DOCUMENT_ROOT.'/core/lib/date.lib.php';
require_once DOL_DOCUMENT_ROOT.'/custom/verifactu/class/VeriFactuRegistry.class.php';
require_once DOL_DOCUMENT_ROOT.'/custom/verifactu/class/VeriFactuChainValidator.class.php';

// TCPDF (incluido en Dolibarr)
require_once DOL_DOCUMENT_ROOT.'/includes/tecnickcom/tcpdf/tcpdf.php';

// ======================================================
// PARAMS / FILTROS
// ======================================================
$filter_ref       = GETPOST('ref', 'alpha');
$filter_date_from = GETPOST('date_from', 'alpha'); // YYYY-MM-DD
$filter_date_to   = GETPOST('date_to', 'alpha');   // YYYY-MM-DD
$doValidateChain  = (int) GETPOST('validate_chain', 'int') === 1;

// ======================================================
// VALIDACIÓN OBLIGATORIA DE PERIODO (para evitar informes "históricos" sin rango)
// ======================================================
if (empty($filter_date_from) || empty($filter_date_to)) {
    setEventMessages(
        'Debe seleccionar un periodo (fecha desde y fecha hasta) antes de generar el informe PDF de integridad VeriFactu.',
        null,
        'errors'
    );

    // Volver a la pantalla anterior si existe, si no, ir al index del módulo
    $back = !empty($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : (DOL_URL_ROOT.'/custom/verifactu/verifactu.index.php');
    header('Location: '.$back);
    exit;
}

// Modo
$verifactuMode = !empty($conf->global->VERIFACTU_MODE) ? (string)$conf->global->VERIFACTU_MODE : 'NOSEND';
$showAeatCols  = ($verifactuMode === 'SEND');

// ======================================================
// DATOS
// ======================================================
$registry = new VeriFactuRegistry($db);

$total = $registry->countFiltered($filter_ref, $filter_date_from, $filter_date_to);

// Para PDF, mejor traer “muchos”
$limit  = 10000;
$offset = 0;

$records = $registry->fetchFiltered($filter_ref, $filter_date_from, $filter_date_to, $limit, $offset);

// Validación cadena (opcional)
$validationResult = null;
$validationMap = [];
if ($doValidateChain) {
    $validator = new VeriFactuChainValidator($db);
    $validationResult = $validator->validate();
    if (!empty($validationResult['details'])) {
        foreach ($validationResult['details'] as $d) {
            $validationMap[$d['id']] = $d;
        }
    }
}

// ======================================================
// UTILIDADES
// ======================================================
function vf_pdf_escape($s)
{
    $s = (string)$s;
    $s = str_replace(["\r", "\n", "\t"], ' ', $s);
    return trim($s);
}

function vf_date_label($ymd)
{
    if (empty($ymd)) return '';
    $ts = dol_stringtotime($ymd);
    if ($ts <= 0) return $ymd;
    return dol_print_date($ts, 'day');
}

/**
 * Formatea hashes largos para que NO se salgan del PDF (TCPDF no hace word-break fiable)
 */
function vf_format_hash($hash, $chunk = 32)
{
    $hash = vf_pdf_escape($hash);
    if ($hash === '' || $hash === 'Inicio cadena') return $hash;
    return implode("<br/>", str_split($hash, $chunk));
}

// ======================================================
// RESUMEN PERIODO
// ======================================================
$periodFromLabel = $filter_date_from ? vf_date_label($filter_date_from) : '—';
$periodToLabel   = $filter_date_to   ? vf_date_label($filter_date_to)   : '—';

$firstRef = '';
$firstDate = '';
$lastRef = '';
$lastDate = '';
$totalAmount = 0.0;

if (is_array($records) && !empty($records)) {
    $minTs = null;
    $maxTs = null;

    foreach ($records as $r) {
        $totalAmount += (float)($r->total_ttc ?? 0);

        $ts = !empty($r->date_creation) ? (int)$r->date_creation : 0;
        if ($ts > 0) {
            if ($minTs === null || $ts < $minTs) {
                $minTs = $ts;
                $firstRef = (string)($r->ref ?? '');
                $firstDate = dol_print_date($ts, 'day');
            }
            if ($maxTs === null || $ts > $maxTs) {
                $maxTs = $ts;
                $lastRef = (string)($r->ref ?? '');
                $lastDate = dol_print_date($ts, 'day');
            }
        }
    }
}

// ======================================================
// CÓDIGO INTERNO DE INFORME (para QR y trazabilidad)
// ======================================================
$reportCode = 'VF-'.date('Ymd-His').'-'.strtoupper(substr(sha1(uniqid('', true)), 0, 6));

// ======================================================
// PDF
// ======================================================
// Footer simple
class VF_PDF extends TCPDF {
    public function Footer() {
        $this->SetY(-12);
        $this->SetFont('helvetica', '', 8);
        $this->Cell(0, 8, 'Página '.$this->getAliasNumPage().' / '.$this->getAliasNbPages(), 0, 0, 'R');
    }
}

$pdf = new VF_PDF('P', 'mm', 'A4', true, 'UTF-8', false);

// Meta
$pdf->SetCreator('Dolibarr - VeriFactu');
$pdf->SetAuthor('VeriFactu');
$pdf->SetTitle('Informe de Integridad VeriFactu');
$pdf->SetSubject('Integridad y trazabilidad de registros VeriFactu');
$pdf->SetKeywords('VeriFactu, AEAT, integridad, trazabilidad, RD 1007/2023');

// Márgenes
$pdf->SetMargins(12, 14, 12);
$pdf->SetAutoPageBreak(true, 12);
$pdf->setPrintHeader(false);
$pdf->setPrintFooter(true);

// ======================================================
// FIRMA DEL PDF (PAdES) con PFX (INVISIBLE)
// ======================================================
$pfxPath = !empty($conf->global->VERIFACTU_PFX_PATH) ? (string)$conf->global->VERIFACTU_PFX_PATH : '';
$pfxPass = isset($conf->global->VERIFACTU_PFX_PASSWORD) ? (string)$conf->global->VERIFACTU_PFX_PASSWORD : '';

if (empty($pfxPath) || !is_readable($pfxPath)) {
    setEventMessages('No se puede firmar el PDF: certificado PFX no configurado o no accesible.', null, 'warnings');
} else {
    $pfxData = @file_get_contents($pfxPath);
    $certs = [];
    if ($pfxData !== false && @openssl_pkcs12_read($pfxData, $certs, $pfxPass)) {

        $info = [
            'Name'        => $conf->global->MAIN_INFO_SOCIETE_NOM ?? 'Empresa',
            'Location'    => $conf->global->MAIN_INFO_SOCIETE_TOWN ?? '',
            'Reason'      => 'Informe de Integridad VeriFactu (RD 1007/2023)',
            'ContactInfo' => $conf->global->MAIN_INFO_SOCIETE_MAIL ?? ''
        ];

        $pdf->setSignature($certs['cert'], $certs['pkey'], $pfxPass, '', 2, $info);

    } else {
        setEventMessages('No se puede firmar el PDF: certificado PFX inválido o contraseña incorrecta.', null, 'warnings');
    }
}

// ======================================================
// CONTENIDO
// ======================================================

// 1) Portada
$pdf->AddPage();
$pdf->SetFont('helvetica', 'B', 18);
$pdf->Ln(10);
$pdf->Cell(0, 10, 'Informe de Integridad VeriFactu', 0, 1, 'C');

$pdf->SetFont('helvetica', '', 11);
$pdf->Ln(2);
$pdf->MultiCell(0, 6, "Sistema Informático de Facturación conforme al RD 1007/2023", 0, 'C');

$pdf->Ln(8);
$pdf->SetFont('helvetica', '', 10);

$companyName = $conf->global->MAIN_INFO_SOCIETE_NOM ?? '';
$companyNIF  = $conf->global->MAIN_INFO_SOCIETE_NIF ?? ($conf->global->MAIN_INFO_SIREN ?? '');
$genDate     = dol_print_date(dol_now(), 'dayhour');

$lines = [];
$lines[] = "Empresa: ".vf_pdf_escape($companyName ?: '—');
$lines[] = "NIF: ".vf_pdf_escape($companyNIF ?: '—');
$lines[] = "Periodo: ".$periodFromLabel."  --  ".$periodToLabel;
$lines[] = "Generado: ".$genDate;
$lines[] = "Código informe: ".$reportCode;
$lines[] = "Modo: ".($verifactuMode === 'SEND' ? 'Envío a AEAT (SEND)' : 'Conservación interna (NOSEND)');
$lines[] = "Algoritmo hash: SHA-256";
$lines[] = "Firma: XAdES en XML + Firma digital del PDF (PAdES)";

$pdf->Ln(2);
$pdf->MultiCell(0, 6, implode("\n", $lines), 0, 'L');

// -----------------------
// QR EN PORTADA + TEXTO EXPLICATIVO
// -----------------------
$qrText = "VERIFACTU|".$reportCode;

// Ajuste de posición (A4 vertical)
$qrSize = 28;                  // mm
$x = 210 - 12 - $qrSize;       // A4 width - margen derecho - size
$y = 297 - 55;                 // un poco por encima del borde inferior

$pdf->write2DBarcode($qrText, 'QRCODE,M', $x, $y, $qrSize, $qrSize, ['border' => 0], 'N');

// Texto explicativo (a la izquierda del QR)
$pdf->SetFont('helvetica', '', 8);
$pdf->SetXY($x - 62, $y + 6);
$pdf->MultiCell(
    58,
    4,
    "Código identificador\ndel informe VeriFactu:\n".$reportCode,
    0,
    'R'
);

// 2) Marco legal
$pdf->AddPage();
$pdf->SetFont('helvetica', 'B', 13);
$pdf->Cell(0, 8, 'Marco legal y finalidad', 0, 1, 'L');
$pdf->SetFont('helvetica', '', 10);

$legal = "El presente documento se genera en cumplimiento de lo establecido en el Real Decreto 1007/2023, "
       . "por el que se regulan los requisitos que deben adoptar los sistemas informáticos de facturación, "
       . "así como de la normativa asociada al sistema VeriFactu de la Agencia Estatal de Administración Tributaria.\n\n"
       . "Este informe recoge los registros de facturación generados por el sistema, incluyendo su encadenamiento (hash anterior/actual), "
       . "la disponibilidad del XML original y firmado, y, en su caso, el estado de envío a la AEAT.\n\n"
       . "El sistema garantiza la integridad, inalterabilidad, trazabilidad y conservación de los registros.";

$pdf->MultiCell(0, 6, $legal, 0, 'L');

// 3) Identificación del sistema
$pdf->Ln(4);
$pdf->SetFont('helvetica', 'B', 13);
$pdf->Cell(0, 8, 'Identificación del sistema', 0, 1, 'L');
$pdf->SetFont('helvetica', '', 10);

$sys = [];
$sys[] = "Sistema base: Dolibarr ERP";
$sys[] = "Módulo: VeriFactu (custom/verifactu)";
$sys[] = "Entidad Dolibarr: ".(int)$conf->entity;
$sys[] = "Modo: ".($verifactuMode === 'SEND' ? 'SEND' : 'NOSEND');
$sys[] = "Hash: SHA-256 (cadena encadenada)";
$sys[] = "Firma XML: XAdES-BES / XAdES-T (según TSA configurada)";
$sys[] = "Firma PDF: PAdES (con certificado configurado)";

$pdf->MultiCell(0, 6, implode("\n", $sys), 0, 'L');

// 4) Resumen periodo
$pdf->AddPage();
$pdf->SetFont('helvetica', 'B', 13);
$pdf->Cell(0, 8, 'Resumen del periodo', 0, 1, 'L');
$pdf->SetFont('helvetica', '', 10);

$summaryHtml = '<table border="1" cellpadding="4">'
    .'<tr><td width="50%"><b>Total registros</b></td><td width="50%">'.$total.'</td></tr>'
    .'<tr><td><b>Primera factura</b></td><td>'.vf_pdf_escape($firstRef ?: '—').' ('.vf_pdf_escape($firstDate ?: '—').')</td></tr>'
    .'<tr><td><b>Última factura</b></td><td>'.vf_pdf_escape($lastRef ?: '—').' ('.vf_pdf_escape($lastDate ?: '—').')</td></tr>'
    .'<tr><td><b>Total facturado (suma registros)</b></td><td>'.price($totalAmount).'</td></tr>'
    .'</table>';

$pdf->writeHTML($summaryHtml, true, false, true, false, '');

// ======================================================
// 5) Listado detallado (EN HORIZONTAL)
// ======================================================
$pdf->AddPage('L');                 // Solo el listado en apaisado
$pdf->SetMargins(10, 12, 10);       // Márgenes ajustados para que entren todas
$pdf->SetAutoPageBreak(true, 12);

$pdf->SetFont('helvetica', 'B', 13);
$pdf->Cell(0, 8, 'Listado de registros', 0, 1, 'L');
$pdf->SetFont('helvetica', '', 8);

if (!is_array($records) || empty($records)) {
    $pdf->MultiCell(0, 6, 'No existen registros VeriFactu para los criterios seleccionados.', 0, 'L');
} else {

    // Anchos suman 100%
    $thead = '<tr style="background-color:#f2f2f2;">'
           . '<th width="4%"><b>ID</b></th>'
           . '<th width="12%"><b>Factura</b></th>'
           . '<th width="10%"><b>Fecha</b></th>'
           . '<th width="8%"><b>Total</b></th>'
           . '<th width="28%"><b>Hash actual</b></th>'
           . '<th width="28%"><b>Hash anterior</b></th>';

    if ($showAeatCols) {
        $thead .= '<th width="5%"><b>AEAT</b></th>'
                . '<th width="5%"><b>CSV</b></th>';
    }

    $thead .= '</tr>';

    $rowsHtml = '';
    foreach ($records as $r) {

        $id   = (int)($r->rowid ?? 0);
        $ref  = vf_pdf_escape($r->ref ?? '');
        $date = !empty($r->date_creation) ? dol_print_date($r->date_creation, 'dayhour') : '—';
        $tot  = price((float)($r->total_ttc ?? 0));

        $bg = '';
        if (!empty($validationMap[$id]) && ($validationMap[$id]['status'] ?? '') !== 'OK') {
            $bg = ' style="background-color:#ffe6e6;"';
        }

        $hashActual  = vf_format_hash($r->hash_actual ?? '');
        $hashAnterior = !empty($r->hash_anterior) ? vf_format_hash($r->hash_anterior) : 'Inicio cadena';

        $rowsHtml .= '<tr'.$bg.'>'
            . '<td width="4%">'.$id.'</td>'
            . '<td width="12%">'.$ref.'</td>'
            . '<td width="10%">'.$date.'</td>'
            . '<td width="8%" align="right">'.$tot.'</td>'
            . '<td width="28%"><font size="6" face="courier">'.$hashActual.'</font></td>'
            . '<td width="28%"><font size="6" face="courier">'.$hashAnterior.'</font></td>';

        if ($showAeatCols) {
            $st = vf_pdf_escape($r->aeat_status ?? '');
            if ($st === '') $st = '—';

            $csv = vf_pdf_escape($r->aeat_csv ?? '');
            if ($csv === '') $csv = '—';

            $rowsHtml .= '<td width="5%">'.$st.'</td>'
                       . '<td width="5%"><font size="7">'.$csv.'</font></td>';
        }

        $rowsHtml .= '</tr>';
    }

    $tableHtml = '<table border="1" cellpadding="3">'.$thead.$rowsHtml.'</table>';
    $pdf->writeHTML($tableHtml, true, false, true, false, '');
}

// ======================================================
// 6) Validación de integridad (SOLO SI SE EJECUTA)
//    -> Eliminado el texto “Validación no ejecutada...”
// ======================================================
if ($doValidateChain) {

    $pdf->AddPage();
    $pdf->SetFont('helvetica', 'B', 13);
    $pdf->Cell(0, 8, 'Validación de integridad de la cadena', 0, 1, 'L');
    $pdf->SetFont('helvetica', '', 10);

    $status = ($validationResult['status'] ?? '') === 'OK' ? 'OK' : 'ERROR';
    if ($status === 'OK') {
        $pdf->MultiCell(0, 6, "Resultado: ✅ Cadena íntegra.\nNo se han detectado rupturas ni inconsistencias.", 0, 'L');
    } else {
        $pdf->MultiCell(0, 6, "Resultado: ❌ Se detectan inconsistencias en la cadena.\nDetalles a continuación:", 0, 'L');

        $rows = '';
        foreach (($validationResult['details'] ?? []) as $d) {
            if (($d['status'] ?? '') === 'OK') continue;
            $rows .= '<tr>'
                .'<td width="15%">'.$d['id'].'</td>'
                .'<td width="20%">'.$d['status'].'</td>'
                .'<td width="65%">'.vf_pdf_escape($d['message'] ?? '').'</td>'
                .'</tr>';
        }
        $t = '<table border="1" cellpadding="4">'
            .'<tr style="background-color:#f2f2f2;"><th width="15%"><b>ID</b></th><th width="20%"><b>Estado</b></th><th width="65%"><b>Mensaje</b></th></tr>'
            .$rows
            .'</table>';
        $pdf->writeHTML($t, true, false, true, false, '');
    }
}

// 7) Declaración final
$pdf->AddPage();
$pdf->SetFont('helvetica', 'B', 13);
$pdf->Cell(0, 8, 'Declaración', 0, 1, 'L');
$pdf->SetFont('helvetica', '', 10);

$decl = "El presente informe ha sido generado automáticamente por el sistema informático de facturación, "
      . "garantizando que los datos contenidos coinciden con los registros originales almacenados.\n\n"
      . "Código informe: ".$reportCode."\n"
      . "Fecha y hora de generación: ".$genDate;

$pdf->MultiCell(0, 6, $decl, 0, 'L');

// Firma visible discreta (si hay firma configurada)
// $pdf->setSignatureAppearance(160, 265, 40, 12); // esquina inferior derecha

// ======================================================
// SALIDA
// ======================================================
$filename = 'informe_integridad_verifactu_'.date('Ymd_His').'.pdf';
$pdf->Output($filename, 'D');
exit;
