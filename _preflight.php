<?php
declare(strict_types=1);

// pages/_preflight.php - shared bootstrap for all pages (vB)
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$root_dir = dirname(__DIR__); // /health_reporting_system_vB
require_once $root_dir . '/config.php';
require_once $root_dir . '/helpers.php';

if (!function_exists('hrs_set_selected_project_id')) {
    function hrs_set_selected_project_id(int $project_id): void {
        if ($project_id > 0) {
            $_SESSION['selected_project_id'] = $project_id;
        }
    }
}

if (!function_exists('hrs_selected_project_id')) {
    function hrs_selected_project_id(): int {
        return (int)($_SESSION['selected_project_id'] ?? 0);
    }
}

if (!function_exists('hrs_capture_project_selection')) {
    function hrs_capture_project_selection(): int {
        $incoming = 0;
        if (isset($_POST['project_id']) && is_numeric($_POST['project_id'])) {
            $incoming = (int)$_POST['project_id'];
        } elseif (isset($_GET['project_id']) && is_numeric($_GET['project_id'])) {
            $incoming = (int)$_GET['project_id'];
        }
        if ($incoming > 0) {
            hrs_set_selected_project_id($incoming);
            return $incoming;
        }
        return hrs_selected_project_id();
    }
}

// Capture once per request
hrs_capture_project_selection();
