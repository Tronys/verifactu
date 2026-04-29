<?php
define('NOCSRFCHECK', 1);

// ======================================================
// CARGA ENTORNO DOLIBARR
// ======================================================
$res = 0;
if (!$res && file_exists(__DIR__.'/../main.inc.php')) $res = require __DIR__.'/../main.inc.php';
if (!$res && file_exists(__DIR__.'/../../main.inc.php')) $res = require __DIR__.'/../../main.inc.php';
if (!$res) die('Dolibarr environment not found');

// ======================================================
// SEGURIDAD
// ======================================================
if (empty($user->rights->verifactu->read)) {
    accessforbidden();
}

// ======================================================
// PARAMETROS
// ======================================================
$dateFrom = GETPOST('date_from', 'alpha');
$dateTo   = GETPOST('date_to', 'alpha');

if (empty($dateFrom) || empty($dateTo)) {
    accessforbidden('Debe seleccionar un periodo');
}

// ======================================================
// CLASES
// ======================================================
require_once __DIR__.'/class/VeriFactuRegistry.class.php';

$registry = new VeriFactuRegistry($db);
$records  = $registry->fetchFiltered(null, $dateFrom, $dateTo, 100000, 0);

// ======================================================
// RESOLVER RUTAS XML (CLAVE PARA QUE EL ZIP NO SALGA VACÍO)
// ======================================================
function vf_resolve_xml_path($relativeOrName)
{
    $p = (string) $relativeOrName;
    if ($p === '') return '';

    // 1) Ruta absoluta
    if ($p[0] === '/' && file_exists($p)) {
        return $p;
    }

    // 2) Ruta estándar del módulo
    $baseDir = DOL_DATA_ROOT . '/verifactu/XMLverifactu/';
    $candidate = $baseDir . ltrim($p, '/');
    if (file_exists($candidate)) {
        return $candidate;
    }

    // 3) Ruta relativa a DOL_DATA_ROOT
    $candidate = DOL_DATA_ROOT . '/' . ltrim($p, '/');
    if (file_exists($candidate)) {
        return $candidate;
    }

    return '';
}

// ======================================================
// ZIP
// ======================================================
$tmpZip = tempnam(sys_get_temp_dir(), 'verifactu_');
$zip = new ZipArchive();

if ($zip->open($tmpZip, ZipArchive::OVERWRITE) !== true) {
    accessforbidden('No se pudo crear el ZIP');
}

// Estructura
$zip->addEmptyDir('xml/originales');
$zip->addEmptyDir('xml/firmados');

// ======================================================
// MANIFEST (INTEGRADO)
// ======================================================
$manifest = [
    'expediente' => 'VeriFactu',
    'periodo' => [
        'desde' => $dateFrom,
        'hasta' => $dateTo,
    ],
    'generado_en' => dol_print_date(dol_now(), '%Y-%m-%dT%H:%M:%S'),
    'sistema' => [
        'erp' => 'Dolibarr',
        'modulo' => 'VeriFactu',
        'entity' => (int) $conf->entity,
    ],
    'contenido' => [
        'xml_originales' => [
            'cantidad' => 0,
            'hash_global_sha256' => '',
        ],
        'xml_firmados' => [
            'cantidad' => 0,
            'hash_global_sha256' => '',
        ],
    ],
    'ficheros' => [],
];

$hashOriginales = hash_init('sha256');
$hashFirmados   = hash_init('sha256');

// ======================================================
// AÑADIR XML + HASHES
// ======================================================
foreach ($records as $r) {

    // XML ORIGINAL
    if (!empty($r->xml_vf_path)) {
        $fullPath = vf_resolve_xml_path($r->xml_vf_path);

        if (!empty($fullPath) && file_exists($fullPath)) {
            $zipPath = 'xml/originales/' . basename($fullPath);
            $zip->addFile($fullPath, $zipPath);

            $sha = hash_file('sha256', $fullPath);
            hash_update($hashOriginales, $sha);

            $manifest['contenido']['xml_originales']['cantidad']++;
            $manifest['ficheros'][] = [
                'ruta'   => $zipPath,
                'sha256' => $sha,
            ];
        }
    }

    // XML FIRMADO
    if (!empty($r->xml_signed_path)) {
        $fullPath = vf_resolve_xml_path($r->xml_signed_path);

        if (!empty($fullPath) && file_exists($fullPath)) {
            $zipPath = 'xml/firmados/' . basename($fullPath);
            $zip->addFile($fullPath, $zipPath);

            $sha = hash_file('sha256', $fullPath);
            hash_update($hashFirmados, $sha);

            $manifest['contenido']['xml_firmados']['cantidad']++;
            $manifest['ficheros'][] = [
                'ruta'   => $zipPath,
                'sha256' => $sha,
            ];
        }
    }
}

// Hashes globales
$manifest['contenido']['xml_originales']['hash_global_sha256'] = hash_final($hashOriginales);
$manifest['contenido']['xml_firmados']['hash_global_sha256']   = hash_final($hashFirmados);

// ======================================================
// AÑADIR MANIFEST.json
// ======================================================
$zip->addFromString(
    'MANIFEST.json',
    json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
);

// ======================================================
// README
// ======================================================
$zip->addFromString(
    'README.txt',
    "Expediente VeriFactu\n".
    "Periodo: $dateFrom a $dateTo\n".
    "Generado: ".dol_print_date(dol_now(), 'dayhour')."\n\n".
    "Incluye:\n".
    "- XML originales\n".
    "- XML firmados (XAdES)\n".
    "- MANIFEST.json (índice e integridad)\n\n".
    "Sistema: Dolibarr + módulo VeriFactu\n"
);

$zip->close();

// ======================================================
// DESCARGA
// ======================================================
$filename = 'verifactu_expediente_'.$dateFrom.'_'.$dateTo.'.zip';

header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="'.$filename.'"');
header('Content-Length: '.filesize($tmpZip));

readfile($tmpZip);
@unlink($tmpZip);
exit;
