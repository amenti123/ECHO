<?php
/**
 * Indicator Module Generator
 * Generates comprehensive Indicator Module document in PDF, Excel, or Word format
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
$format = $_GET['format'] ?? 'pdf';

if (empty($project_ids)) {
    die('No projects selected');
}

$placeholders = str_repeat('?,', count($project_ids) - 1) . '?';
$sql = "SELECT i.*, p.title as project_title, p.code as project_code, p.description as project_description
        FROM indicators i
        LEFT JOIN projects p ON i.project_id = p.id
        WHERE i.project_id IN ($placeholders)
        ORDER BY i.project_id, i.indicator_level, i.code";
$stmt = $pdo->prepare($sql);
$stmt->execute($project_ids);
$indicators = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get project details
$project_details = [];
$stmt = $pdo->prepare("SELECT * FROM projects WHERE id IN ($placeholders)");
$stmt->execute($project_ids);
$projects = $stmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($projects as $p) {
    $project_details[$p['id']] = $p;
}

$filename = 'indicator_module_' . date('Y-m-d') . '_' . time();

if ($format === 'pdf') {
    // REAL PDF format (server-side) if Dompdf is installed; otherwise fallback to print-friendly HTML.

    $html = '<!DOCTYPE html><html><head><meta charset="UTF-8"><style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        .header { background: #4361ee; color: white; padding: 20px; border-radius: 8px; margin-bottom: 20px; }
        .header h1 { margin: 0 0 10px 0; }
        .section { margin: 20px 0; page-break-inside: avoid; }
        .section h2 { color: #4361ee; border-bottom: 2px solid #4361ee; padding-bottom: 10px; }
        table { width: 100%; border-collapse: collapse; margin: 10px 0; }
        th, td { border: 1px solid #ddd; padding: 12px; text-align: left; }
        th { background-color: #4361ee; color: white; font-weight: bold; }
        tr:nth-child(even) { background-color: #f9fafb; }
        .definition-box { background: #f8f9fa; padding: 15px; border-radius: 5px; margin: 10px 0; white-space: pre-wrap; }
    </style></head><body>';
    
    $html .= '<div class="header">';
    $html .= '<h1>🚀 Health Reporting System</h1>';
    $html .= '<h2>Indicator Module Document</h2>';
    $html .= '<p>Generated: ' . date('Y-m-d H:i:s') . '</p>';
    $html .= '<p>Projects: ' . implode(', ', array_column($projects, 'title')) . '</p>';
    $html .= '</div>';
    
    // Project Overview
    foreach ($projects as $project) {
        $html .= '<div class="section">';
        $html .= '<h2>📋 Project: ' . htmlspecialchars($project['title']) . '</h2>';
        $html .= '<p><strong>Code:</strong> ' . htmlspecialchars($project['code'] ?? 'N/A') . '</p>';
        if (!empty($project['description'])) {
            $html .= '<p><strong>Description:</strong> ' . nl2br(htmlspecialchars($project['description'])) . '</p>';
        }
        $html .= '</div>';
    }
    
    // Indicators by Level
    $levels = ['impact', 'outcome', 'output', 'activity'];
    foreach ($levels as $level) {
        $level_indicators = array_filter($indicators, function($ind) use ($level) {
            return ($ind['indicator_level'] ?? '') === $level;
        });
        
        if (!empty($level_indicators)) {
            $html .= '<div class="section">';
            $html .= '<h2>📊 ' . ucfirst($level) . ' Indicators</h2>';
            $html .= '<table>';
            $html .= '<tr><th>Code</th><th>Name</th><th>Project</th><th>Type</th><th>Unit</th><th>Definition</th></tr>';
            
            foreach ($level_indicators as $ind) {
                $html .= '<tr>';
                $html .= '<td><strong>' . htmlspecialchars($ind['code']) . '</strong></td>';
                $html .= '<td>' . htmlspecialchars($ind['name']) . '</td>';
                $html .= '<td>' . htmlspecialchars($ind['project_title'] ?? 'N/A') . '</td>';
                $html .= '<td>' . htmlspecialchars($ind['indicator_type'] ?? '') . '</td>';
                $html .= '<td>' . htmlspecialchars($ind['unit_label'] ?: $ind['unit_type']) . '</td>';
                $html .= '<td><div class="definition-box">' . nl2br(htmlspecialchars($ind['definition'] ?? 'No definition')) . '</div></td>';
                $html .= '</tr>';
            }
            
            $html .= '</table>';
            $html .= '</div>';
        }
    }
    
    $html .= '</body></html>';
    
    hrs_export_pdf_from_html($html, $filename . '.pdf', 'A4', 'portrait');
}

if ($format === 'excel' || $format === 'word') {
    $mime = $format === 'excel' ? 'application/vnd.ms-excel' : 'application/vnd.ms-word';
    $ext = $format === 'excel' ? 'xls' : 'doc';
    
    header('Content-Type: ' . $mime);
    header('Content-Disposition: attachment; filename="' . $filename . '.' . $ext . '"');
    
    echo '<html><head><meta charset="UTF-8"></head><body>';
    echo '<h1>Indicator Module Document</h1>';
    echo '<p>Generated: ' . date('Y-m-d H:i:s') . '</p>';
    echo '<p>Projects: ' . implode(', ', array_column($projects, 'title')) . '</p>';
    
    // Similar structure as PDF but in table format
    echo '<table border="1" cellpadding="5">';
    echo '<tr><th>Code</th><th>Name</th><th>Project</th><th>Level</th><th>Type</th><th>Unit</th><th>Definition</th></tr>';
    
    foreach ($indicators as $ind) {
        echo '<tr>';
        echo '<td>' . htmlspecialchars($ind['code']) . '</td>';
        echo '<td>' . htmlspecialchars($ind['name']) . '</td>';
        echo '<td>' . htmlspecialchars($ind['project_title'] ?? 'N/A') . '</td>';
        echo '<td>' . htmlspecialchars($ind['indicator_level'] ?? '') . '</td>';
        echo '<td>' . htmlspecialchars($ind['indicator_type'] ?? '') . '</td>';
        echo '<td>' . htmlspecialchars($ind['unit_label'] ?: $ind['unit_type']) . '</td>';
        echo '<td>' . htmlspecialchars($ind['definition'] ?? 'No definition') . '</td>';
        echo '</tr>';
    }
    
    echo '</table></body></html>';
    exit;
}

