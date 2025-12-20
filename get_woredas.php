<?php
require_once __DIR__ . '/../helpers.php';

if (function_exists('hrs_prepare_json_response')) { hrs_prepare_json_response(); } else { header('Content-Type: application/json; charset=utf-8'); }
if (isset($_GET['zone_id'])) {
    $zone_id = (int)$_GET['zone_id'];
    $woredas = get_woredas_by_zone($zone_id);
    echo json_encode($woredas);
} else {
    echo json_encode([]);
}
?>