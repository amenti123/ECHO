<?php
/**
 * Indicator Export Handler
 * Handles export to Excel, PDF, Word, CSV formats
 */

require_once __DIR__ . '/../helpers.php';
// NOTE: Do NOT include header.php here (it outputs HTML and breaks file downloads)
require_login();

if (!is_admin()) {
    die('Access denied');
}

$pdo = getPDO();

// Helper function to normalize project_ids (only declare if not already declared)
if (!function_exists('normalizeProjectIds')) {
    function normalizeProjectIds($project_ids) {
        if (empty($project_ids)) {
            return [];
        }
        if (is_array($project_ids)) {
            return array_filter(array_map('intval', $project_ids));
        }
        if (is_string($project_ids)) {
            return array_filter(array_map('intval', explode(',', $project_ids)));
        }
        return [];
    }
}

$project_ids = normalizeProjectIds($_GET['project_ids'] ?? []);
$action = $_GET['action'] ?? 'export_excel';

if (empty($project_ids)) {
    die('No projects selected');
}

$placeholders = str_repeat('?,', count($project_ids) - 1) . '?';
$sql = "SELECT i.*, p.title as project_title, p.code as project_code
        FROM indicators i
        LEFT JOIN projects p ON i.project_id = p.id
        WHERE i.project_id IN ($placeholders)
        ORDER BY i.project_id, i.indicator_level, i.code";
$stmt = $pdo->prepare($sql);
$stmt->execute($project_ids);
$indicators = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get project names
$project_names = [];
$stmt = $pdo->prepare("SELECT id, title FROM projects WHERE id IN ($placeholders)");
$stmt->execute($project_ids);
$projects = $stmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($projects as $p) {
    $project_names[] = $p['title'];
}

$filename = 'indicators_' . implode('_', $project_names) . '_' . date('Y-m-d');

if ($action === 'export_excel' || $action === 'export_csv') {
    if ($action === 'export_excel') {
        // Excel format (HTML table that Excel can open)
        header('Content-Type: application/vnd.ms-excel; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '.xls"');
        
        echo '<html><head><meta charset="UTF-8"><style>
            body { font-family: Arial, sans-serif; }
            table { border-collapse: collapse; width: 100%; }
            th, td { border: 1px solid #000; padding: 8px; text-align: left; }
            th { background-color: #4361ee; color: white; font-weight: bold; }
            tr:nth-child(even) { background-color: #f9fafb; }
        </style></head><body>';
        echo '<h1>🚀 Health Reporting System - Indicators Export</h1>';
        echo '<p><strong>Projects:</strong> ' . htmlspecialchars(implode(', ', $project_names)) . '</p>';
        echo '<p><strong>Export Date:</strong> ' . date('Y-m-d H:i:s') . '</p>';
        echo '<table>';
        echo '<tr><th>ID</th><th>Project</th><th>Code</th><th>Name</th><th>Level</th><th>Type</th><th>Unit</th><th>Input Mode</th><th>Definition</th><th>Source URL</th></tr>';
        
        foreach ($indicators as $ind) {
            echo '<tr>';
            echo '<td>' . htmlspecialchars($ind['id']) . '</td>';
            echo '<td>' . htmlspecialchars($ind['project_title'] ?? 'N/A') . '</td>';
            echo '<td>' . htmlspecialchars($ind['code']) . '</td>';
            echo '<td>' . htmlspecialchars($ind['name']) . '</td>';
            echo '<td>' . htmlspecialchars($ind['indicator_level'] ?? '') . '</td>';
            echo '<td>' . htmlspecialchars($ind['indicator_type'] ?? '') . '</td>';
            echo '<td>' . htmlspecialchars($ind['unit_label'] ?: $ind['unit_type']) . '</td>';
            echo '<td>' . htmlspecialchars($ind['input_mode']) . '</td>';
            echo '<td>' . htmlspecialchars(strip_tags($ind['definition'] ?? '')) . '</td>';
            echo '<td>' . htmlspecialchars($ind['source_url'] ?? '') . '</td>';
            echo '</tr>';
        }
        
        echo '</table></body></html>';
    } else {
        // CSV format
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '.csv"');
        
        $output = fopen('php://output', 'w');
        fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF)); // UTF-8 BOM
        
        // Headers
        fputcsv($output, ['ID', 'Project', 'Code', 'Name', 'Level', 'Type', 'Unit', 'Input Mode', 'Definition', 'Source URL']);
        
        // Data
        foreach ($indicators as $ind) {
            fputcsv($output, [
                $ind['id'],
                $ind['project_title'] ?? 'N/A',
                $ind['code'],
                $ind['name'],
                $ind['indicator_level'] ?? '',
                $ind['indicator_type'] ?? '',
                $ind['unit_label'] ?: $ind['unit_type'],
                $ind['input_mode'],
                strip_tags($ind['definition'] ?? ''),
                $ind['source_url'] ?? ''
            ]);
        }
        
        fclose($output);
    }
    exit;
}

if ($action === 'export_pdf') {
    // REAL PDF format (server-side) if Dompdf is installed; otherwise fallback to print-friendly HTML.

    $html = '<!DOCTYPE html><html><head><meta charset="UTF-8"><style>
        @page { margin: 20mm; }
        body { font-family: Arial, sans-serif; font-size: 10pt; }
        .header { background: #4361ee; color: white; padding: 15px; border-radius: 5px; margin-bottom: 20px; }
        .header h1 { margin: 0 0 5px 0; font-size: 18pt; }
        .header p { margin: 5px 0; font-size: 9pt; }
        table { width: 100%; border-collapse: collapse; margin: 10px 0; font-size: 9pt; }
        th, td { border: 1px solid #333; padding: 6px; text-align: left; }
        th { background: #4361ee; color: white; font-weight: bold; }
        tr:nth-child(even) { background-color: #f9fafb; }
        .definition-cell { max-width: 200px; word-wrap: break-word; }
        @media print {
            .no-print { display: none; }
        }
    </style></head><body>';
    
    $html .= '<div class="header">';
    $html .= '<h1>🚀 Health Reporting System</h1>';
    $html .= '<h2>Indicators Export</h2>';
    $html .= '<p><strong>Projects:</strong> ' . htmlspecialchars(implode(', ', $project_names)) . '</p>';
    $html .= '<p><strong>Export Date:</strong> ' . date('Y-m-d H:i:s') . '</p>';
    $html .= '<p><strong>Total Indicators:</strong> ' . count($indicators) . '</p>';
    $html .= '</div>';
    
    $html .= '<table>';
    $html .= '<tr><th>ID</th><th>Project</th><th>Code</th><th>Name</th><th>Level</th><th>Type</th><th>Unit</th><th class="definition-cell">Definition</th></tr>';
    
    foreach ($indicators as $ind) {
        $html .= '<tr>';
        $html .= '<td>' . htmlspecialchars($ind['id']) . '</td>';
        $html .= '<td>' . htmlspecialchars($ind['project_title'] ?? 'N/A') . '</td>';
        $html .= '<td><strong>' . htmlspecialchars($ind['code']) . '</strong></td>';
        $html .= '<td>' . htmlspecialchars($ind['name']) . '</td>';
        $html .= '<td>' . htmlspecialchars($ind['indicator_level'] ?? '') . '</td>';
        $html .= '<td>' . htmlspecialchars($ind['indicator_type'] ?? '') . '</td>';
        $html .= '<td>' . htmlspecialchars($ind['unit_label'] ?: $ind['unit_type']) . '</td>';
        $def = strip_tags($ind['definition'] ?? '');
        $html .= '<td class="definition-cell">' . htmlspecialchars(strlen($def) > 150 ? substr($def, 0, 150) . '...' : $def) . '</td>';
        $html .= '</tr>';
    }
    
    $html .= '</table>';
    $html .= '<div style="margin-top: 20px; text-align: center; font-size: 8pt; color: #666;">';
    $html .= 'Generated by Health Reporting System on ' . date('Y-m-d H:i:s');
    $html .= '</div>';
    $html .= '</body></html>';
    
    hrs_export_pdf_from_html($html, $filename . '.pdf', 'A4', 'portrait');
}

if ($action === 'export_word') {
    header('Content-Type: application/vnd.ms-word; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '.doc"');
    
    echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        h1 { color: #4361ee; }
        table { width: 100%; border-collapse: collapse; margin: 20px 0; }
        th, td { border: 1px solid #333; padding: 8px; text-align: left; }
        th { background-color: #4361ee; color: white; font-weight: bold; }
        tr:nth-child(even) { background-color: #f9fafb; }
    </style></head><body>';
    echo '<h1>🚀 Health Reporting System</h1>';
    echo '<h2>Indicators Export</h2>';
    echo '<p><strong>Projects:</strong> ' . htmlspecialchars(implode(', ', $project_names)) . '</p>';
    echo '<p><strong>Export Date:</strong> ' . date('Y-m-d H:i:s') . '</p>';
    echo '<p><strong>Total Indicators:</strong> ' . count($indicators) . '</p>';
    echo '<table border="1" cellpadding="5">';
    echo '<tr><th>ID</th><th>Project</th><th>Code</th><th>Name</th><th>Level</th><th>Type</th><th>Unit</th><th>Input Mode</th><th>Definition</th><th>Source URL</th></tr>';
    
    foreach ($indicators as $ind) {
        echo '<tr>';
        echo '<td>' . htmlspecialchars($ind['id']) . '</td>';
        echo '<td>' . htmlspecialchars($ind['project_title'] ?? 'N/A') . '</td>';
        echo '<td><strong>' . htmlspecialchars($ind['code']) . '</strong></td>';
        echo '<td>' . htmlspecialchars($ind['name']) . '</td>';
        echo '<td>' . htmlspecialchars($ind['indicator_level'] ?? '') . '</td>';
        echo '<td>' . htmlspecialchars($ind['indicator_type'] ?? '') . '</td>';
        echo '<td>' . htmlspecialchars($ind['unit_label'] ?: $ind['unit_type']) . '</td>';
        echo '<td>' . htmlspecialchars($ind['input_mode']) . '</td>';
        $def = strip_tags($ind['definition'] ?? '');
        echo '<td>' . htmlspecialchars(strlen($def) > 300 ? substr($def, 0, 300) . '...' : $def) . '</td>';
        echo '<td>' . htmlspecialchars($ind['source_url'] ?? '') . '</td>';
        echo '</tr>';
    }
    
    echo '</table>';
    echo '<p style="margin-top: 20px; font-size: 9pt; color: #666; text-align: center;">';
    echo 'Generated by Health Reporting System on ' . date('Y-m-d H:i:s');
    echo '</p>';
    echo '</body></html>';
    exit;
}

