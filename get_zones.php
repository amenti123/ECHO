<?php
require_once __DIR__ . '/../helpers.php';

if (function_exists('hrs_prepare_json_response')) { hrs_prepare_json_response(); } else { header('Content-Type: application/json; charset=utf-8'); }
if (isset($_GET['region_id'])) {
    $region_id = (int)$_GET['region_id'];
    $zones = get_zones_by_region($region_id);
    echo json_encode($zones);
} else {
    echo json_encode([]);
}
?>