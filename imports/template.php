<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/importers.php';
require_once __DIR__ . '/../includes/import_engine.php';
require_login();

$type = input('type');
$importer = get_importer($type);
if (!$importer) {
    http_response_code(404);
    die('Unknown import type.');
}
require_module_manage($importer['permission_module']);

$sampleParam = input('sample', '0');
$sampleLimit = match ($sampleParam) {
    '5' => 5,
    '50' => 50,
    'all' => null,
    default => 0,
};

import_send_template($importer, $type, $sampleLimit);
