<?php
require_once __DIR__ . '/../../main.inc.php';

if (!$user->admin) {
    accessforbidden();
}

$id = (int) GETPOST('id', 'int');
if ($id <= 0) {
    accessforbidden('ID inválido');
}

$sql = "SELECT xml_vf_path
        FROM ".MAIN_DB_PREFIX."verifactu_registry
        WHERE rowid = ".$id;

$res = $db->query($sql);
if (!$res || $db->num_rows($res) === 0) {
    accessforbidden('Registro no encontrado');
}

$obj = $db->fetch_object($res);
if (empty($obj->xml_vf_path)) {
    accessforbidden('XML VeriFactu no disponible');
}

$baseDir = DOL_DATA_ROOT . '/verifactu/XMLverifactu';
$filePath = $baseDir . '/' . $obj->xml_vf_path;

if (!file_exists($filePath)) {
    accessforbidden('Fichero XML no encontrado');
}

header('Content-Type: application/xml; charset=UTF-8');
header('Content-Disposition: attachment; filename="'.$obj->xml_vf_path.'"');
header('Content-Length: '.filesize($filePath));

readfile($filePath);
exit;
