<?php
/**
 * ENHANCED INDICATORS MANAGEMENT SYSTEM v3.0
 * 
 * Features:
 * - Pulls all indicators from planning.php (impact_indicators, outcome_indicators, output_indicators)
 * - Multi-project selection and combined indicator view
 * - Standard indicator definitions (global standards)
 * - Online indicator definition websites access
 * - AI generation for indicator definitions
 * - Full CRUD operations (Create, Read, Update, Delete)
 * - Export/Import (Excel, PDF, Word)
 * - Generate Indicator Module document
 * - Enhanced UI with icons and modern design
 * - Sync with planning.php (bidirectional)
 */

require_once __DIR__ . '/../header.php';
require_login();

if (!is_admin()) {
    echo "<p>You must be admin to manage indicators.</p>";
    require_once __DIR__ . '/../footer.php';
    exit;
}

$pdo     = getPDO();
$message = '';
$error   = '';

/**
 * XLSX SIMPLE PARSER (first sheet only)
 * Parses Excel XLSX files to array of rows
 */
if (!function_exists('parseXLSXToRows')) {
    function parseXLSXToRows($filePath) {
        $rows = [];
        if (!class_exists('ZipArchive')) {
            return $rows;
        }

        $zip = new ZipArchive();
        if ($zip->open($filePath) !== TRUE) {
            return $rows;
        }

        $sharedStrings = [];
        $sharedIndex   = $zip->locateName('xl/sharedStrings.xml');
        if ($sharedIndex !== false) {
            $xml = simplexml_load_string($zip->getFromIndex($sharedIndex));
            foreach ($xml->si as $i => $si) {
                $t = '';
                if (isset($si->t)) {
                    $t = (string)$si->t;
                } elseif (isset($si->r)) {
                    foreach ($si->r as $run) {
                        $t .= (string)$run->t;
                    }
                }
                $sharedStrings[(int)$i] = $t;
            }
        }

        $sheetIndex = $zip->locateName('xl/worksheets/sheet1.xml');
        if ($sheetIndex === false) {
            $zip->close();
            return $rows;
        }

        $xml = simplexml_load_string($zip->getFromIndex($sheetIndex));
        if (!$xml || !isset($xml->sheetData)) {
            $zip->close();
            return $rows;
        }

        foreach ($xml->sheetData->row as $row) {
            $rowData = [];
            foreach ($row->c as $c) {
                $v    = isset($c->v) ? (string)$c->v : '';
                $type = isset($c['t']) ? (string)$c['t'] : '';
                if ($type === 's' && $v !== '' && isset($sharedStrings[(int)$v])) {
                    $rowData[] = $sharedStrings[(int)$v];
                } else {
                    $rowData[] = $v;
                }
            }
            if (!empty($rowData)) {
                $rows[] = $rowData;
            }
        }

        $zip->close();
        return $rows;
    }
}

// Helper function to normalize project_ids (handles both string and array)
// Only declare if not already declared (prevents redeclaration errors)
if (!function_exists('normalizeProjectIds')) {
    function normalizeProjectIds($project_ids) {
        if (empty($project_ids)) {
            return [];
        }
        
        // If it's already an array, use it directly
        if (is_array($project_ids)) {
            return array_filter(array_map('intval', $project_ids));
        }
        
        // If it's a string (comma-separated), explode it
        if (is_string($project_ids)) {
            return array_filter(array_map('intval', explode(',', $project_ids)));
        }
        
        return [];
    }
}

// Handle export requests
if (isset($_GET['action'])) {
    $action = $_GET['action'];
    
    if ($action === 'export_excel' || $action === 'export_pdf' || $action === 'export_word' || $action === 'export_csv') {
        $project_ids = normalizeProjectIds($_GET['project_ids'] ?? []);
        
        if (empty($project_ids)) {
            $error = 'Please select at least one project.';
        } else {
            require_once __DIR__ . '/indicator_export_handler.php';
            exit;
        }
    }
    
    if ($action === 'generate_module') {
        $project_ids = normalizeProjectIds($_GET['project_ids'] ?? []);
        $format = $_GET['format'] ?? 'pdf';
        
        if (empty($project_ids)) {
            $error = 'Please select at least one project.';
        } else {
            require_once __DIR__ . '/indicator_module_generator.php';
            exit;
        }
    }
}

// Ensure indicators table exists with all required columns
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS indicators (
            id INT AUTO_INCREMENT PRIMARY KEY,
            project_id INT DEFAULT 0,
            code VARCHAR(100) NOT NULL,
            name VARCHAR(255) NOT NULL,
            definition TEXT NULL,
            source_url VARCHAR(500) NULL,
            unit_type VARCHAR(50) DEFAULT 'persons',
            input_mode VARCHAR(50) DEFAULT 'sadd',
            indicator_level VARCHAR(50) NULL,
            indicator_type VARCHAR(50) NULL,
            unit_label VARCHAR(100) NULL,
            disaggregation VARCHAR(255) NULL,
            show_in_reports TINYINT(1) NOT NULL DEFAULT 1,
            planning_table VARCHAR(50) NULL,
            planning_id INT NULL,
            result_id INT NULL,
            beneficiary_type VARCHAR(50) NULL,
            total_target INT DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_project (project_id),
            INDEX idx_code (code),
            INDEX idx_planning (planning_table, planning_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    
    // Add new columns if they don't exist
    $new_columns = [
        'planning_table' => "ALTER TABLE indicators ADD COLUMN planning_table VARCHAR(50) NULL",
        'planning_id' => "ALTER TABLE indicators ADD COLUMN planning_id INT NULL",
        'result_id' => "ALTER TABLE indicators ADD COLUMN result_id INT NULL",
        'beneficiary_type' => "ALTER TABLE indicators ADD COLUMN beneficiary_type VARCHAR(50) NULL",
        'total_target' => "ALTER TABLE indicators ADD COLUMN total_target INT DEFAULT 0",
        'updated_at' => "ALTER TABLE indicators ADD COLUMN updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP"
    ];
    
    $existing_columns = [];
    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM indicators");
        if ($stmt) {
            $existing_columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
        }
    } catch (PDOException $e) {
        // Table might not exist yet
    }
    
    foreach ($new_columns as $col => $sql) {
        if (!in_array($col, $existing_columns, true)) {
            try {
                $pdo->exec($sql);
            } catch (PDOException $e) {
                // Column might already exist
            }
        }
    }
} catch (PDOException $e) {
    $error = 'Failed to ensure indicators table exists: ' . $e->getMessage();
}

// Load AI helper if available
$aiHelperPath = __DIR__ . '/../nexus_ai.php';
if (file_exists($aiHelperPath)) {
    require_once $aiHelperPath;
}

// Standard Indicator Definitions Library (Global Standards)
$standardIndicatorDefinitions = [
    'beneficiaries_reached' => [
        'title' => 'Beneficiaries Reached',
        'definition' => 'The total number of unique individuals who have directly received goods, services, or training through project interventions.',
        'measurement' => 'Count of unique individuals receiving direct support',
        'data_source' => 'Project attendance records, registration databases, distribution lists',
        'frequency' => 'Monthly or quarterly',
        'purpose' => 'To track the scale and demographic distribution of direct beneficiary engagement',
        'disaggregation' => 'Age, sex, disability status, location (region/zone/woreda), status (IDP/host/returnee)',
        'standard_source' => 'USAID, ECHO, UNHCR Indicator Reference Sheets'
    ],
    'training_completion' => [
        'title' => 'Training Completion Rate',
        'definition' => 'The percentage of enrolled participants who successfully complete a training program, meeting all required attendance and assessment criteria.',
        'measurement' => '(Number of participants completing training / Total enrolled) × 100',
        'data_source' => 'Training attendance records, assessment results, completion certificates',
        'frequency' => 'Per training cycle',
        'purpose' => 'To assess training program effectiveness and participant retention',
        'disaggregation' => 'Age, sex, disability status, training type',
        'standard_source' => 'USAID Performance Indicator Reference Sheets'
    ],
    'satisfaction_rate' => [
        'title' => 'Beneficiary Satisfaction Rate',
        'definition' => 'The percentage of beneficiaries reporting satisfaction with project services, typically measured through standardized surveys.',
        'measurement' => '(Number of satisfied beneficiaries / Total surveyed) × 100',
        'data_source' => 'Satisfaction surveys, feedback mechanisms, focus group discussions',
        'frequency' => 'Quarterly or semi-annually',
        'purpose' => 'To measure perceived quality and relevance of project interventions',
        'disaggregation' => 'Age, sex, service type, location',
        'standard_source' => 'ECHO, UNHCR Monitoring Standards'
    ],
    'health_service_coverage' => [
        'title' => 'Health Service Coverage',
        'definition' => 'The proportion of the target population that has access to and utilizes specific health services.',
        'measurement' => '(Number of people receiving service / Total target population) × 100',
        'data_source' => 'Health facility records, population surveys, service statistics',
        'frequency' => 'Quarterly or annually',
        'purpose' => 'To measure health service accessibility and utilization',
        'disaggregation' => 'Age, sex, service type, location',
        'standard_source' => 'WHO Health Indicators, USAID Health Reference Sheets'
    ],
    'vaccination_coverage' => [
        'title' => 'Vaccination Coverage',
        'definition' => 'The percentage of the target population that has received specific vaccinations.',
        'measurement' => '(Number of vaccinated individuals / Total target population) × 100',
        'data_source' => 'Vaccination records, health facility reports, survey data',
        'frequency' => 'Monthly or quarterly during campaigns',
        'purpose' => 'To monitor immunization program performance and population protection',
        'disaggregation' => 'Age, sex, vaccine type, location',
        'standard_source' => 'WHO Immunization Indicators, UNICEF MICS'
    ],
    'nutrition_status' => [
        'title' => 'Nutrition Status (GAM/SAM)',
        'definition' => 'The prevalence of Global Acute Malnutrition (GAM) or Severe Acute Malnutrition (SAM) among children under 5 years.',
        'measurement' => '(Number of children with GAM/SAM / Total children measured) × 100',
        'data_source' => 'Nutrition surveys, health facility records, MUAC measurements',
        'frequency' => 'Quarterly or semi-annually',
        'purpose' => 'To assess nutritional status and identify at-risk populations',
        'disaggregation' => 'Age (6-23 months, 24-59 months), sex, location',
        'standard_source' => 'WHO Growth Standards, UNICEF Nutrition Indicators'
    ],
    'water_access' => [
        'title' => 'Access to Safe Water',
        'definition' => 'The percentage of households with access to an improved water source within 30 minutes round trip.',
        'measurement' => '(Number of households with access / Total households) × 100',
        'data_source' => 'Household surveys, water point mapping, facility records',
        'frequency' => 'Quarterly or annually',
        'purpose' => 'To measure water accessibility and service delivery',
        'disaggregation' => 'Location, household type, water source type',
        'standard_source' => 'JMP (WHO/UNICEF Joint Monitoring Programme) Indicators'
    ],
    'sanitation_access' => [
        'title' => 'Access to Improved Sanitation',
        'definition' => 'The percentage of households using improved sanitation facilities that are not shared with other households.',
        'measurement' => '(Number of households with improved sanitation / Total households) × 100',
        'data_source' => 'Household surveys, facility assessments',
        'frequency' => 'Quarterly or annually',
        'purpose' => 'To measure sanitation coverage and hygiene practices',
        'disaggregation' => 'Location, household type, facility type',
        'standard_source' => 'JMP (WHO/UNICEF Joint Monitoring Programme) Indicators'
    ]
];

// Function to format definition for display
function formatDefinitionForDisplay($definition_array) {
    $result  = "**Definition**: " . ($definition_array['definition'] ?? '') . "\n\n";
    $result .= "**Measurement**: " . ($definition_array['measurement'] ?? '') . "\n\n";
    $result .= "**Data Sources**: " . ($definition_array['data_source'] ?? '') . "\n\n";
    $result .= "**Frequency**: " . ($definition_array['frequency'] ?? '') . "\n\n";
    $result .= "**Purpose**: " . ($definition_array['purpose'] ?? '') . "\n\n";
    if (!empty($definition_array['disaggregation'])) {
        $result .= "**Disaggregation**: " . $definition_array['disaggregation'] . "\n\n";
    }
    if (!empty($definition_array['standard_source'])) {
        $result .= "**Standard Source**: " . $definition_array['standard_source'];
    }
    return $result;
}

// Function to fetch AI indicator definition
function fetchAIIndicatorDefinition($indicator_name, $indicator_type = 'general') {
    global $standardIndicatorDefinitions;
    
    $name_lower = strtolower($indicator_name);
    
    // Check standard definitions first
    foreach ($standardIndicatorDefinitions as $key => $def) {
        if (strpos($name_lower, str_replace('_', ' ', $key)) !== false) {
            return formatDefinitionForDisplay($def);
        }
    }
    
    // Delegate to Nexus AI if available
    if (function_exists('nexus_ai_indicator_definition')) {
        $aiText = nexus_ai_indicator_definition($indicator_name, $indicator_type);
        if (!empty($aiText)) {
            return $aiText;
        }
    }
    
    // Generate generic definition
    $templates = [
        'general' => "**Definition**: $indicator_name measures the achievement of specific project objectives through quantifiable metrics.\n\n**Measurement**: Quantitative tracking of outputs or outcomes\n\n**Data Sources**: Project records, monitoring systems, surveys\n\n**Frequency**: Monthly or quarterly monitoring\n\n**Purpose**: To evaluate project performance and impact",
        'percentage' => "**Definition**: $indicator_name represents the proportion of a total population or sample that exhibits a specific characteristic.\n\n**Measurement**: Expressed as percentage (numerator/denominator × 100)\n\n**Data Sources**: Surveys, assessments, project records\n\n**Frequency**: Periodic assessment (quarterly/semi-annual)\n\n**Purpose**: To measure relative achievement and coverage",
        'count' => "**Definition**: $indicator_name tracks the absolute number of instances, individuals, or items related to project activities.\n\n**Measurement**: Direct counting of occurrences or beneficiaries\n\n**Data Sources**: Registration systems, attendance records, distribution lists\n\n**Frequency**: Continuous or periodic counting\n\n**Purpose**: To quantify project reach and output delivery"
    ];
    
    return $templates[$indicator_type] ?? $templates['general'];
}

// Get selected projects (support multiple)
$selected_project_ids = [];
if (isset($_GET['project_ids'])) {
    $selected_project_ids = normalizeProjectIds($_GET['project_ids']);
} elseif (isset($_GET['project_id'])) {
    $selected_project_ids = [(int)$_GET['project_id']];
} elseif (isset($_SESSION['selected_indicator_project_ids'])) {
    $selected_project_ids = $_SESSION['selected_indicator_project_ids'];
}

if (empty($selected_project_ids) && isset($_POST['project_ids'])) {
    $selected_project_ids = normalizeProjectIds($_POST['project_ids']);
}

$_SESSION['selected_indicator_project_ids'] = $selected_project_ids;

// Load all projects
$projects = [];
try {
    $stmtProjects = $pdo->query("SELECT id, title, code FROM projects ORDER BY title");
    if ($stmtProjects) {
        $projects = $stmtProjects->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (PDOException $e) {
    // Projects table might not exist
}

// Pull indicators from planning.php tables
function pullIndicatorsFromPlanning($pdo, $project_ids) {
    $all_indicators = [];
    
    if (empty($project_ids)) {
        return $all_indicators;
    }
    
    $placeholders = str_repeat('?,', count($project_ids) - 1) . '?';
    
    // Pull from impact_indicators
    try {
        $sql = "SELECT 
                    id, project_id, result_id, indicator_code as code, indicator_name as name,
                    unit_type, input_mode, beneficiary_type,
                    COALESCE(total_target, 0) as total_target,
                    'impact_indicators' as planning_table,
                    'impact' as indicator_level
                FROM impact_indicators
                WHERE project_id IN ($placeholders)";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($project_ids);
        $indicators = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($indicators as $ind) {
            $all_indicators[] = $ind;
        }
    } catch (PDOException $e) {
        // Table might not exist
    }
    
    // Pull from outcome_indicators
    try {
        $sql = "SELECT 
                    id, project_id, result_id, indicator_code as code, indicator_name as name,
                    unit_type, input_mode, beneficiary_type,
                    COALESCE(total_target, 0) as total_target,
                    'outcome_indicators' as planning_table,
                    'outcome' as indicator_level
                FROM outcome_indicators
                WHERE project_id IN ($placeholders)";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($project_ids);
        $indicators = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($indicators as $ind) {
            $all_indicators[] = $ind;
        }
    } catch (PDOException $e) {
        // Table might not exist
    }
    
    // Pull from output_indicators
    try {
        $sql = "SELECT 
                    id, project_id, result_id, indicator_code as code, indicator_name as name,
                    unit_type, input_mode, beneficiary_type,
                    COALESCE(target_total, 0) as total_target,
                    'output_indicators' as planning_table,
                    'output' as indicator_level
                FROM output_indicators
                WHERE project_id IN ($placeholders)";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($project_ids);
        $indicators = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($indicators as $ind) {
            $all_indicators[] = $ind;
        }
    } catch (PDOException $e) {
        // Table might not exist
    }
    
    return $all_indicators;
}

// Auto-sync indicators from planning to indicators table
function syncIndicatorsFromPlanning($pdo, $project_ids) {
    if (empty($project_ids)) return;
    
    $planning_indicators = pullIndicatorsFromPlanning($pdo, $project_ids);
    
    foreach ($planning_indicators as $pi) {
        $code = trim($pi['code'] ?? '');
        $name = trim($pi['name'] ?? '');
        $project_id = (int)($pi['project_id'] ?? 0);
        
        if (empty($code) || empty($name) || $project_id <= 0) continue;
        
        // Check if indicator already exists
        $check = $pdo->prepare("SELECT id FROM indicators WHERE project_id = ? AND code = ? AND planning_table = ? AND planning_id = ?");
        $check->execute([$project_id, $code, $pi['planning_table'], $pi['id']]);
        $existing = $check->fetchColumn();
        
        if (!$existing) {
            // Insert new indicator
            try {
                $insert = $pdo->prepare("
                    INSERT INTO indicators (
                        project_id, code, name, unit_type, input_mode, indicator_level,
                        planning_table, planning_id, result_id, beneficiary_type, total_target
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $insert->execute([
                    $project_id,
                    $code,
                    $name,
                    $pi['unit_type'] ?? 'persons',
                    $pi['input_mode'] ?? 'sadd',
                    $pi['indicator_level'] ?? '',
                    $pi['planning_table'] ?? '',
                    $pi['id'],
                    $pi['result_id'] ?? null,
                    $pi['beneficiary_type'] ?? null,
                    $pi['total_target'] ?? 0
                ]);
            } catch (PDOException $e) {
                // Ignore duplicates
            }
        }
    }
}

// Sync indicators from planning
if (!empty($selected_project_ids)) {
    syncIndicatorsFromPlanning($pdo, $selected_project_ids);
}

// Handle POST requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // Save indicator
    if (isset($_POST['save'])) {
        $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
        $code = trim($_POST['code'] ?? '');
        $name = trim($_POST['name'] ?? '');
        $definition = trim($_POST['definition'] ?? '');
        $source_url = trim($_POST['source_url'] ?? '');
        $unit_type = $_POST['unit_type'] ?? 'persons';
        $input_mode = $_POST['input_mode'] ?? 'sadd';
        $indicator_level = trim($_POST['indicator_level'] ?? '');
        $indicator_type = trim($_POST['indicator_type'] ?? '');
        $unit_label = trim($_POST['unit_label'] ?? '');
        $disaggregation = trim($_POST['disaggregation'] ?? '');
        $project_id = (int)($_POST['project_id'] ?? ($selected_project_ids[0] ?? 0));
        $planning_table = trim($_POST['planning_table'] ?? '');
        $planning_id = isset($_POST['planning_id']) ? (int)$_POST['planning_id'] : null;
        
        if (empty($name)) {
            $error = 'Indicator name is required.';
        } elseif ($project_id <= 0) {
            $error = 'Please select a project.';
        } else {
            try {
                if ($id > 0) {
                    // Update
                    $update = $pdo->prepare("
                        UPDATE indicators SET
                            code = ?, name = ?, definition = ?, source_url = ?,
                            unit_type = ?, input_mode = ?, indicator_level = ?,
                            indicator_type = ?, unit_label = ?, disaggregation = ?,
                            project_id = ?, planning_table = ?, planning_id = ?
                        WHERE id = ?
                    ");
                    $update->execute([
                        $code, $name, $definition, $source_url,
                        $unit_type, $input_mode, $indicator_level,
                        $indicator_type, $unit_label, $disaggregation,
                        $project_id, $planning_table, $planning_id, $id
                    ]);
                    $message = 'Indicator updated successfully.';
                    
                    // Sync back to planning if linked
                    if ($planning_table && $planning_id) {
                        syncIndicatorToPlanning($pdo, $planning_table, $planning_id, $code, $name, $unit_type, $input_mode, $indicator_level);
                    }
                } else {
                    // Insert
                    $insert = $pdo->prepare("
                        INSERT INTO indicators (
                            project_id, code, name, definition, source_url,
                            unit_type, input_mode, indicator_level, indicator_type,
                            unit_label, disaggregation, planning_table, planning_id
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    $insert->execute([
                        $project_id, $code, $name, $definition, $source_url,
                        $unit_type, $input_mode, $indicator_level, $indicator_type,
                        $unit_label, $disaggregation, $planning_table, $planning_id
                    ]);
                    $message = 'Indicator created successfully.';
                }
            } catch (PDOException $e) {
                $error = 'Database error: ' . $e->getMessage();
            }
        }
    }
    
    // Delete indicator
    elseif (isset($_POST['delete'])) {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            try {
                $delete = $pdo->prepare("DELETE FROM indicators WHERE id = ?");
                $delete->execute([$id]);
                $message = 'Indicator deleted successfully.';
            } catch (PDOException $e) {
                $error = 'Error deleting indicator: ' . $e->getMessage();
            }
        }
    }
    
    // Fetch AI definition
    elseif (isset($_POST['fetch_definition'])) {
        $indicator_name = trim($_POST['indicator_name'] ?? '');
        $indicator_type = trim($_POST['indicator_type'] ?? 'general');
        
        if (!empty($indicator_name)) {
            $ai_definition = fetchAIIndicatorDefinition($indicator_name, $indicator_type);
            $formDefinition = $ai_definition;
            $message = 'AI definition fetched successfully.';
        } else {
            $error = 'Indicator name is required.';
        }
    }
    
    // Import indicators
    elseif (isset($_POST['import_indicators'])) {
        try {
            if (!isset($_FILES['import_file']) || $_FILES['import_file']['error'] !== UPLOAD_ERR_OK) {
                throw new Exception('No file uploaded or upload error.');
            }
            
            $file = $_FILES['import_file']['tmp_name'];
            $extension = strtolower(pathinfo($_FILES['import_file']['name'], PATHINFO_EXTENSION));
            
            if (!in_array($extension, ['csv', 'xlsx', 'xls'])) {
                throw new Exception('Unsupported file format. Please upload CSV or Excel file.');
            }
            
            // Parse file
            $rows = [];
            if ($extension === 'csv') {
                $handle = fopen($file, 'r');
                if (!$handle) {
                    throw new Exception('Cannot open uploaded CSV file.');
                }
                while (($data = fgetcsv($handle)) !== false) {
                    if (!empty(array_filter($data, 'strlen'))) {
                        $rows[] = $data;
                    }
                }
                fclose($handle);
            } elseif ($extension === 'xlsx') {
                $rows = parseXLSXToRows($file);
            } else {
                throw new Exception('XLS (old Excel) format is not supported. Please save as CSV or XLSX and import again.');
            }
            
            if (empty($rows) || count($rows) < 2) {
                throw new Exception('No data rows found in the file. Please ensure the file has a header row and at least one data row.');
            }
            
            $header = array_map('trim', array_map('strtolower', $rows[0]));
            $imported = 0;
            $errors = [];
            $selected_project_ids = normalizeProjectIds($_POST['selected_project_ids'] ?? []);
            
            // If no project selected, use project_id from import or default to 0
            foreach (array_slice($rows, 1) as $rowIndex => $dataRow) {
                if (!is_array($dataRow)) continue;
                
                // Normalize row length
                if (count($dataRow) < count($header)) {
                    $dataRow = array_pad($dataRow, count($header), '');
                } elseif (count($dataRow) > count($header)) {
                    $dataRow = array_slice($dataRow, 0, count($header));
                }
                
                $rowData = @array_combine($header, $dataRow);
                if ($rowData === false || empty(array_filter($rowData, 'strlen'))) {
                    continue;
                }
                
                try {
                    $code = trim($rowData['code'] ?? '');
                    $name = trim($rowData['name'] ?? '');
                    
                    if (empty($code) && empty($name)) {
                        continue;
                    }
                    
                    if (empty($code)) {
                        $code = 'IND-' . time() . '-' . $rowIndex;
                    }
                    
                    // Get project_id from import or use selected projects
                    $project_id = 0;
                    if (!empty($rowData['project id'])) {
                        $project_id = (int)$rowData['project id'];
                    } elseif (!empty($selected_project_ids)) {
                        $project_id = $selected_project_ids[0]; // Use first selected project
                    }
                    
                    // Check if indicator exists
                    $checkStmt = $pdo->prepare("SELECT id FROM indicators WHERE code = ? AND project_id = ?");
                    $checkStmt->execute([$code, $project_id]);
                    $existing = $checkStmt->fetch();
                    
                    if ($existing) {
                        $stmt = $pdo->prepare("
                            UPDATE indicators SET
                                name = ?, definition = ?, source_url = ?, unit_type = ?,
                                input_mode = ?, indicator_level = ?, indicator_type = ?,
                                unit_label = ?, disaggregation = ?, beneficiary_type = ?,
                                total_target = ?, show_in_reports = ?
                            WHERE id = ?
                        ");
                        $stmt->execute([
                            $name,
                            $rowData['definition'] ?? null,
                            $rowData['source url'] ?? $rowData['source_url'] ?? null,
                            $rowData['unit type'] ?? $rowData['unit_type'] ?? 'persons',
                            $rowData['input mode'] ?? $rowData['input_mode'] ?? 'sadd',
                            $rowData['indicator level'] ?? $rowData['indicator_level'] ?? null,
                            $rowData['indicator type'] ?? $rowData['indicator_type'] ?? null,
                            $rowData['unit label'] ?? $rowData['unit_label'] ?? null,
                            $rowData['disaggregation'] ?? null,
                            $rowData['beneficiary type'] ?? $rowData['beneficiary_type'] ?? null,
                            !empty($rowData['total target']) ? (int)$rowData['total target'] : (!empty($rowData['total_target']) ? (int)$rowData['total_target'] : 0),
                            isset($rowData['show in reports']) ? (int)$rowData['show in reports'] : (isset($rowData['show_in_reports']) ? (int)$rowData['show_in_reports'] : 1),
                            $existing['id']
                        ]);
                    } else {
                        $stmt = $pdo->prepare("
                            INSERT INTO indicators (project_id, code, name, definition, source_url, unit_type, input_mode, indicator_level, indicator_type, unit_label, disaggregation, beneficiary_type, total_target, show_in_reports)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                        ");
                        $stmt->execute([
                            $project_id,
                            $code,
                            $name,
                            $rowData['definition'] ?? null,
                            $rowData['source url'] ?? $rowData['source_url'] ?? null,
                            $rowData['unit type'] ?? $rowData['unit_type'] ?? 'persons',
                            $rowData['input mode'] ?? $rowData['input_mode'] ?? 'sadd',
                            $rowData['indicator level'] ?? $rowData['indicator_level'] ?? null,
                            $rowData['indicator type'] ?? $rowData['indicator_type'] ?? null,
                            $rowData['unit label'] ?? $rowData['unit_label'] ?? null,
                            $rowData['disaggregation'] ?? null,
                            $rowData['beneficiary type'] ?? $rowData['beneficiary_type'] ?? null,
                            !empty($rowData['total target']) ? (int)$rowData['total target'] : (!empty($rowData['total_target']) ? (int)$rowData['total_target'] : 0),
                            isset($rowData['show in reports']) ? (int)$rowData['show in reports'] : (isset($rowData['show_in_reports']) ? (int)$rowData['show_in_reports'] : 1)
                        ]);
                    }
                    $imported++;
                } catch (PDOException $e) {
                    $errors[] = "Row " . ($rowIndex + 2) . ": " . $e->getMessage();
                    error_log("Indicator import error on row " . ($rowIndex + 2) . ": " . $e->getMessage());
                } catch (Exception $e) {
                    $errors[] = "Row " . ($rowIndex + 2) . ": " . $e->getMessage();
                }
            }
            
            if ($imported > 0) {
                $message = "Successfully imported {$imported} indicator(s).";
                if (!empty($errors)) {
                    $message .= " " . count($errors) . " error(s) occurred.";
                }
            } else {
                $error = 'No indicators were imported. ' . (!empty($errors) ? implode('; ', array_slice($errors, 0, 5)) : 'Please check your file format.');
            }
        } catch (Exception $e) {
            $error = 'Import failed: ' . $e->getMessage();
            error_log("Indicator Import Error: " . $e->getMessage());
        }
    }
}

// Load indicators for selected projects
$indicators = [];
if (!empty($selected_project_ids)) {
    $placeholders = str_repeat('?,', count($selected_project_ids) - 1) . '?';
    try {
        $sql = "SELECT i.*, p.title as project_title, p.code as project_code
                FROM indicators i
                LEFT JOIN projects p ON i.project_id = p.id
                WHERE i.project_id IN ($placeholders)
                ORDER BY i.project_id, i.indicator_level, i.code";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($selected_project_ids);
        $indicators = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $error = 'Error loading indicators: ' . $e->getMessage();
    }
}

// Get edit indicator
$editId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$editRow = null;
if ($editId > 0) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM indicators WHERE id = ?");
        $stmt->execute([$editId]);
        $editRow = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $error = 'Error loading indicator: ' . $e->getMessage();
    }
}

// Sync indicator back to planning
function syncIndicatorToPlanning($pdo, $planning_table, $planning_id, $code, $name, $unit_type, $input_mode, $indicator_level) {
    if (empty($planning_table) || empty($planning_id)) return;
    
    try {
        $code_col = 'indicator_code';
        $name_col = 'indicator_name';
        
        $update = $pdo->prepare("
            UPDATE $planning_table SET
                $code_col = ?, $name_col = ?, unit_type = ?, input_mode = ?
            WHERE id = ?
        ");
        $update->execute([$code, $name, $unit_type, $input_mode, $planning_id]);
    } catch (PDOException $e) {
        // Ignore sync errors
    }
}

// Prefill form
$formId = $editRow['id'] ?? 0;
$formCode = $editRow['code'] ?? '';
$formName = $editRow['name'] ?? '';
$formDefinition = isset($formDefinition) ? $formDefinition : ($editRow['definition'] ?? '');
$formSourceUrl = $editRow['source_url'] ?? '';
$formUnitType = $editRow['unit_type'] ?? 'persons';
$formInputMode = $editRow['input_mode'] ?? 'sadd';
$formLevel = $editRow['indicator_level'] ?? '';
$formType = $editRow['indicator_type'] ?? '';
$formUnitLabel = $editRow['unit_label'] ?? '';
$formDisaggregation = $editRow['disaggregation'] ?? '';
$formProjectId = $editRow['project_id'] ?? ($selected_project_ids[0] ?? 0);
$formPlanningTable = $editRow['planning_table'] ?? '';
$formPlanningId = $editRow['planning_id'] ?? null;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>📊 Enhanced Indicators Management System</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
    <style>
        :root {
            --primary: #4361ee;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
            --info: #3b82f6;
            --purple: #8b5cf6;
        }
        
        * {
            box-sizing: border-box;
        }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
            margin: 0;
            padding: 20px;
            background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
            min-height: 100vh;
        }
        
        .container {
            max-width: 1400px;
            margin: 0 auto;
            background: white;
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.1);
            overflow: hidden;
        }
        
        .header {
            background: linear-gradient(135deg, var(--primary) 0%, var(--purple) 100%);
            color: white;
            padding: 40px;
            text-align: center;
        }
        
        .header h1 {
            margin: 0;
            font-size: 2.5em;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 15px;
        }
        
        .header p {
            margin: 10px 0 0;
            opacity: 0.9;
            font-size: 1.1em;
        }
        
        .content {
            padding: 30px;
        }
        
        .alert {
            padding: 15px 20px;
            border-radius: 10px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .alert-success {
            background: #d4edda;
            color: #155724;
            border-left: 4px solid var(--success);
        }
        
        .alert-error {
            background: #f8d7da;
            color: #721c24;
            border-left: 4px solid var(--danger);
        }
        
        .card {
            background: white;
            border-radius: 15px;
            padding: 25px;
            margin-bottom: 25px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
            border: 1px solid #e5e7eb;
        }
        
        .card h2 {
            margin-top: 0;
            color: var(--primary);
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #374151;
        }
        
        .form-control {
            width: 100%;
            padding: 12px;
            border: 2px solid #e5e7eb;
            border-radius: 8px;
            font-size: 14px;
            transition: all 0.3s;
        }
        
        .form-control:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(67, 97, 238, 0.1);
        }
        
        .form-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
        }
        
        .btn {
            padding: 12px 24px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s;
            text-decoration: none;
        }
        
        .btn-primary {
            background: var(--primary);
            color: white;
        }
        
        .btn-primary:hover {
            background: #3a56d4;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(67, 97, 238, 0.3);
        }
        
        .btn-success {
            background: var(--success);
            color: white;
        }
        
        .btn-danger {
            background: var(--danger);
            color: white;
        }
        
        .btn-secondary {
            background: #6c757d;
            color: white;
        }
        
        .btn-sm {
            padding: 6px 12px;
            font-size: 12px;
        }
        
        .table-container {
            overflow-x: auto;
            margin-top: 20px;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
            background: white;
        }
        
        th, td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #e5e7eb;
        }
        
        th {
            background: #f8f9fa;
            font-weight: 600;
            color: #374151;
            position: sticky;
            top: 0;
        }
        
        tr:hover {
            background: #f9fafb;
        }
        
        .badge {
            padding: 4px 12px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
            display: inline-block;
        }
        
        .badge-primary {
            background: var(--primary);
            color: white;
        }
        
        .badge-success {
            background: var(--success);
            color: white;
        }
        
        .badge-warning {
            background: var(--warning);
            color: white;
        }
        
        .action-buttons {
            display: flex;
            gap: 5px;
        }
        
        .multi-select {
            min-height: 150px;
            max-height: 200px;
        }
        
        .toolbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            flex-wrap: wrap;
            gap: 10px;
        }
        
        .toolbar-actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }
        
        @media (max-width: 768px) {
            .form-row {
                grid-template-columns: 1fr;
            }
            
            .toolbar {
                flex-direction: column;
                align-items: stretch;
            }
        }
        
        /* Print Styles */
        @media print {
            body {
                background: white;
            }
            
            .container {
                box-shadow: none;
                border-radius: 0;
            }
            
            .header {
                background: #4361ee !important;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
            
            .btn, .toolbar, .card:first-of-type {
                display: none !important;
            }
            
            table {
                page-break-inside: auto;
            }
            
            tr {
                page-break-inside: avoid;
                page-break-after: auto;
            }
            
            thead {
                display: table-header-group;
            }
            
            tfoot {
                display: table-footer-group;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>
                <i class="fas fa-chart-line"></i>
                Enhanced Indicators Management System
            </h1>
            <p>Comprehensive indicator management with planning integration, AI definitions, and export capabilities</p>
        </div>
        
        <div class="content">
            <?php if ($message): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i> <?php echo h($message); ?>
                </div>
            <?php endif; ?>
            
            <?php if ($error): ?>
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-triangle"></i> <?php echo h($error); ?>
                </div>
            <?php endif; ?>
            
            <!-- Project Selection (Multi-select) -->
            <div class="card">
                <h2><i class="fas fa-project-diagram"></i> Select Projects</h2>
                <form method="GET" id="projectForm">
                    <div class="form-group">
                        <label for="project_ids">Select Project(s) (Hold Ctrl/Cmd for multiple)</label>
                        <select name="project_ids[]" id="project_ids" multiple class="form-control multi-select" onchange="submitProjectForm()">
                            <?php foreach ($projects as $project): ?>
                                <option value="<?php echo (int)$project['id']; ?>"
                                    <?php echo in_array((int)$project['id'], $selected_project_ids) ? 'selected' : ''; ?>>
                                    <?php echo h($project['title']); ?> (<?php echo h($project['code'] ?? 'N/A'); ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <small style="color: #6c757d; margin-top: 5px; display: block;">
                            Select one or more projects to view and manage their indicators. Indicators from planning.php will be automatically synced.
                        </small>
                    </div>
                </form>
            </div>
            
            <?php if (!empty($selected_project_ids)): ?>
                <!-- Toolbar -->
                <div class="toolbar">
                    <div>
                        <strong>Selected Projects:</strong> <?php echo count($selected_project_ids); ?> project(s)
                        <br>
                        <strong>Total Indicators:</strong> <?php echo count($indicators); ?> indicator(s)
                    </div>
                    <div class="toolbar-actions">
                        <button onclick="exportIndicators('excel')" class="btn btn-success btn-sm">
                            <i class="fas fa-file-excel"></i> Export Excel
                        </button>
                        <button onclick="exportIndicators('pdf')" class="btn btn-danger btn-sm">
                            <i class="fas fa-file-pdf"></i> Export PDF
                        </button>
                        <button onclick="exportIndicators('word')" class="btn btn-primary btn-sm">
                            <i class="fas fa-file-word"></i> Export Word
                        </button>
                        <button onclick="exportIndicators('csv')" class="btn btn-secondary btn-sm">
                            <i class="fas fa-file-csv"></i> Export CSV
                        </button>
                        <button onclick="generateModule('pdf')" class="btn btn-primary btn-sm">
                            <i class="fas fa-file-alt"></i> Generate Module (PDF)
                        </button>
                        <button onclick="generateModule('excel')" class="btn btn-success btn-sm">
                            <i class="fas fa-file-excel"></i> Generate Module (Excel)
                        </button>
                        <button onclick="generateModule('word')" class="btn btn-info btn-sm">
                            <i class="fas fa-file-word"></i> Generate Module (Word)
                        </button>
                        <button onclick="showImportModal()" class="btn btn-secondary btn-sm">
                            <i class="fas fa-upload"></i> Import
                        </button>
                        <button onclick="printIndicators()" class="btn btn-secondary btn-sm">
                            <i class="fas fa-print"></i> Print
                        </button>
                        <button onclick="shareIndicators()" class="btn btn-info btn-sm">
                            <i class="fas fa-share-alt"></i> Share
                        </button>
                        <button onclick="downloadIndicators()" class="btn btn-primary btn-sm">
                            <i class="fas fa-download"></i> Download All
                        </button>
                    </div>
                </div>
                
                <!-- Indicator Form -->
                <div class="card">
                    <h2>
                        <i class="fas fa-<?php echo $formId ? 'edit' : 'plus'; ?>"></i>
                        <?php echo $formId ? 'Edit Indicator' : 'Create New Indicator'; ?>
                    </h2>
                    
                    <form method="POST" id="indicatorForm">
                        <input type="hidden" name="id" value="<?php echo $formId; ?>">
                        <input type="hidden" name="planning_table" value="<?php echo h($formPlanningTable); ?>">
                        <input type="hidden" name="planning_id" value="<?php echo $formPlanningId; ?>">
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label for="project_id">Project *</label>
                                <select name="project_id" id="project_id" class="form-control" required>
                                    <?php foreach ($projects as $project): ?>
                                        <option value="<?php echo (int)$project['id']; ?>"
                                            <?php echo (int)$project['id'] === $formProjectId ? 'selected' : ''; ?>>
                                            <?php echo h($project['title']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="form-group">
                                <label for="code">Indicator Code *</label>
                                <input type="text" name="code" id="code" class="form-control" 
                                       value="<?php echo h($formCode); ?>" required
                                       placeholder="e.g. IND001, BEN001">
                            </div>
                            
                            <div class="form-group">
                                <label for="name">Indicator Name *</label>
                                <input type="text" name="name" id="name" class="form-control" 
                                       value="<?php echo h($formName); ?>" required
                                       placeholder="e.g. Beneficiaries Reached">
                            </div>
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label for="indicator_level">Indicator Level</label>
                                <select name="indicator_level" id="indicator_level" class="form-control">
                                    <option value="">-- Select Level --</option>
                                    <option value="impact" <?php echo $formLevel === 'impact' ? 'selected' : ''; ?>>Impact</option>
                                    <option value="outcome" <?php echo $formLevel === 'outcome' ? 'selected' : ''; ?>>Outcome</option>
                                    <option value="output" <?php echo $formLevel === 'output' ? 'selected' : ''; ?>>Output</option>
                                    <option value="activity" <?php echo $formLevel === 'activity' ? 'selected' : ''; ?>>Activity</option>
                                </select>
                            </div>
                            
                            <div class="form-group">
                                <label for="indicator_type">Indicator Type</label>
                                <select name="indicator_type" id="indicator_type" class="form-control">
                                    <option value="">-- Select Type --</option>
                                    <option value="quantitative" <?php echo $formType === 'quantitative' ? 'selected' : ''; ?>>Quantitative</option>
                                    <option value="qualitative" <?php echo $formType === 'qualitative' ? 'selected' : ''; ?>>Qualitative</option>
                                    <option value="composite" <?php echo $formType === 'composite' ? 'selected' : ''; ?>>Composite/Index</option>
                                </select>
                            </div>
                            
                            <div class="form-group">
                                <label for="unit_type">Unit Type</label>
                                <select name="unit_type" id="unit_type" class="form-control">
                                    <option value="persons" <?php echo $formUnitType === 'persons' ? 'selected' : ''; ?>>Persons</option>
                                    <option value="households" <?php echo $formUnitType === 'households' ? 'selected' : ''; ?>>Households</option>
                                    <option value="percent" <?php echo $formUnitType === 'percent' ? 'selected' : ''; ?>>Percent</option>
                                    <option value="sessions" <?php echo $formUnitType === 'sessions' ? 'selected' : ''; ?>>Sessions</option>
                                    <option value="facilities" <?php echo $formUnitType === 'facilities' ? 'selected' : ''; ?>>Facilities</option>
                                </select>
                            </div>
                            
                            <div class="form-group">
                                <label for="input_mode">Input Mode</label>
                                <select name="input_mode" id="input_mode" class="form-control">
                                    <option value="sadd" <?php echo $formInputMode === 'sadd' ? 'selected' : ''; ?>>SADD (Age/Sex Disaggregated)</option>
                                    <option value="count" <?php echo $formInputMode === 'count' ? 'selected' : ''; ?>>Count Only</option>
                                    <option value="percent_pair" <?php echo $formInputMode === 'percent_pair' ? 'selected' : ''; ?>>Percent Pair</option>
                                    <option value="percent_direct" <?php echo $formInputMode === 'percent_direct' ? 'selected' : ''; ?>>Percent Direct</option>
                                </select>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label for="unit_label">Unit of Measure</label>
                            <input type="text" name="unit_label" id="unit_label" class="form-control" 
                                   value="<?php echo h($formUnitLabel); ?>"
                                   placeholder="e.g. Number of people, % of children">
                        </div>
                        
                        <div class="form-group">
                            <label for="disaggregation">Planned Disaggregation</label>
                            <input type="text" name="disaggregation" id="disaggregation" class="form-control" 
                                   value="<?php echo h($formDisaggregation); ?>"
                                   placeholder="e.g. Sex, age, disability, location">
                        </div>
                        
                        <!-- AI & Online Definition Section -->
                        <div class="card" style="background: #f8f9fa; margin-top: 20px;">
                            <h3 style="margin-top: 0;">
                                <i class="fas fa-robot"></i> Indicator Definition & Methodology
                            </h3>
                            
                            <div class="form-row">
                                <div class="form-group">
                                    <label>Generate Definition</label>
                                    <div style="display: flex; gap: 10px;">
                                        <input type="text" name="indicator_name" id="ai_indicator_name" 
                                               class="form-control" value="<?php echo h($formName); ?>"
                                               placeholder="Indicator name for AI">
                                        <select name="indicator_type_ai" class="form-control" style="max-width: 150px;">
                                            <option value="general">General</option>
                                            <option value="percentage">Percentage</option>
                                            <option value="count">Count</option>
                                        </select>
                                        <button type="submit" name="fetch_definition" class="btn btn-primary">
                                            <i class="fas fa-robot"></i> AI Generate
                                        </button>
                                        <button type="button" onclick="searchOnlineDefinition()" class="btn btn-secondary">
                                            <i class="fas fa-globe"></i> Search Online
                                        </button>
                                    </div>
                                    <small style="color: #6c757d;">
                                        Use AI to generate standard definitions or search online indicator reference sheets (USAID, ECHO, UN, etc.)
                                    </small>
                                </div>
                            </div>
                            
                            <div class="form-group">
                                <label for="definition">Definition & Methodology *</label>
                                <textarea name="definition" id="definition" class="form-control" rows="8" required
                                          placeholder="Enter comprehensive indicator definition including:
**Definition**: ...
**Measurement**: ...
**Data Sources**: ...
**Frequency**: ...
**Purpose**: ..."><?php echo h($formDefinition); ?></textarea>
                            </div>
                            
                            <div class="form-group">
                                <label for="source_url">Source URL (Optional)</label>
                                <input type="url" name="source_url" id="source_url" class="form-control" 
                                       value="<?php echo h($formSourceUrl); ?>"
                                       placeholder="https://... official indicator definition">
                            </div>
                        </div>
                        
                        <div style="margin-top: 20px;">
                            <button type="submit" name="save" class="btn btn-primary">
                                <i class="fas fa-save"></i> <?php echo $formId ? 'Update Indicator' : 'Create Indicator'; ?>
                            </button>
                            <?php if ($formId): ?>
                                <a href="indicators.php?project_ids=<?php echo implode(',', $selected_project_ids); ?>" class="btn btn-secondary">
                                    <i class="fas fa-times"></i> Cancel
                                </a>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>
                
                <!-- Indicators Table -->
                <div class="card">
                    <h2>
                        <i class="fas fa-table"></i> Indicators List
                        <?php if (count($selected_project_ids) > 1): ?>
                            <span style="font-size: 0.6em; color: #6c757d;">
                                (Combined from <?php echo count($selected_project_ids); ?> projects)
                            </span>
                        <?php endif; ?>
                    </h2>
                    
                    <?php if (empty($indicators)): ?>
                        <p style="text-align: center; color: #6c757d; padding: 40px;">
                            <i class="fas fa-info-circle" style="font-size: 2em; display: block; margin-bottom: 10px;"></i>
                            No indicators found for selected projects. Create your first indicator above or sync from planning.php.
                        </p>
                    <?php else: ?>
                        <div class="table-container">
                            <table>
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Project</th>
                                        <th>Code</th>
                                        <th>Name</th>
                                        <th>Level</th>
                                        <th>Type</th>
                                        <th>Unit</th>
                                        <th>Input Mode</th>
                                        <th>Definition</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($indicators as $ind): ?>
                                        <tr>
                                            <td><?php echo (int)$ind['id']; ?></td>
                                            <td>
                                                <span class="badge badge-primary">
                                                    <?php echo h($ind['project_title'] ?? 'N/A'); ?>
                                                </span>
                                            </td>
                                            <td><strong><?php echo h($ind['code']); ?></strong></td>
                                            <td><?php echo h($ind['name']); ?></td>
                                            <td>
                                                <?php if (!empty($ind['indicator_level'])): ?>
                                                    <span class="badge badge-success"><?php echo ucfirst($ind['indicator_level']); ?></span>
                                                <?php else: ?>
                                                    <span style="color: #9ca3af;">-</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if (!empty($ind['indicator_type'])): ?>
                                                    <span class="badge badge-warning"><?php echo ucfirst($ind['indicator_type']); ?></span>
                                                <?php else: ?>
                                                    <span style="color: #9ca3af;">-</span>
                                                <?php endif; ?>
                                            </td>
                                            <td><?php echo h($ind['unit_label'] ?: $ind['unit_type']); ?></td>
                                            <td><?php echo h($ind['input_mode']); ?></td>
                                            <td>
                                                <?php if (!empty($ind['definition'])): ?>
                                                    <details style="cursor: pointer;">
                                                        <summary style="color: var(--primary);">View Definition</summary>
                                                        <div style="margin-top: 10px; padding: 10px; background: #f8f9fa; border-radius: 5px; white-space: pre-wrap; font-size: 12px;">
                                                            <?php echo h($ind['definition']); ?>
                                                        </div>
                                                    </details>
                                                <?php else: ?>
                                                    <span style="color: #9ca3af;">No definition</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <div class="action-buttons">
                                                    <a href="indicators.php?project_ids=<?php echo implode(',', $selected_project_ids); ?>&id=<?php echo (int)$ind['id']; ?>" 
                                                       class="btn btn-primary btn-sm" title="Edit">
                                                        <i class="fas fa-edit"></i>
                                                    </a>
                                                    <form method="POST" style="display: inline;" 
                                                          onsubmit="return confirm('Delete this indicator?');">
                                                        <input type="hidden" name="id" value="<?php echo (int)$ind['id']; ?>">
                                                        <button type="submit" name="delete" class="btn btn-danger btn-sm" title="Delete">
                                                            <i class="fas fa-trash"></i>
                                                        </button>
                                                    </form>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <div class="card">
                    <p style="text-align: center; color: #6c757d; padding: 40px;">
                        <i class="fas fa-info-circle" style="font-size: 2em; display: block; margin-bottom: 10px;"></i>
                        Please select one or more projects to view and manage indicators.
                    </p>
                </div>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Import Modal (simplified) -->
    <div id="importModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000; align-items: center; justify-content: center;">
        <div style="background: white; padding: 30px; border-radius: 10px; max-width: 500px;">
            <h3>Import Indicators</h3>
            <form method="POST" enctype="multipart/form-data">
                <div class="form-group">
                    <label>Select File (CSV or Excel)</label>
                    <input type="file" name="import_file" accept=".csv,.xlsx,.xls" class="form-control" required>
                </div>
                <div style="display: flex; gap: 10px; margin-top: 20px;">
                    <button type="submit" name="import_indicators" class="btn btn-primary">
                        <i class="fas fa-upload"></i> Import
                    </button>
                    <button type="button" onclick="document.getElementById('importModal').style.display='none'" class="btn btn-secondary">
                        Cancel
                    </button>
                </div>
            </form>
        </div>
    </div>
    
    <script>
        function exportIndicators(format) {
            const projectIds = <?php echo json_encode($selected_project_ids); ?>;
            if (projectIds.length === 0) {
                alert('Please select at least one project.');
                return;
            }
            // Pass as comma-separated string in URL
            window.location.href = 'indicators.php?action=export_' + format + '&project_ids=' + projectIds.join(',');
        }
        
        function generateModule(format) {
            const projectIds = <?php echo json_encode($selected_project_ids); ?>;
            if (projectIds.length === 0) {
                alert('Please select at least one project.');
                return;
            }
            // Pass as comma-separated string in URL
            window.location.href = 'indicators.php?action=generate_module&format=' + format + '&project_ids=' + projectIds.join(',');
        }
        
        function showImportModal() {
            document.getElementById('importModal').style.display = 'flex';
        }
        
        function searchOnlineDefinition() {
            const name = document.getElementById('name').value || document.getElementById('ai_indicator_name').value;
            const query = encodeURIComponent(name + ' indicator definition USAID ECHO UN');
            window.open('https://www.google.com/search?q=' + query, '_blank');
        }
        
        function submitProjectForm() {
            const form = document.getElementById('projectForm');
            const select = document.getElementById('project_ids');
            const selected = Array.from(select.selectedOptions).map(opt => opt.value);
            
            // Build URL with comma-separated project IDs
            const url = new URL(window.location.href);
            url.searchParams.delete('project_ids');
            url.searchParams.delete('project_id');
            url.searchParams.delete('id'); // Remove edit ID when changing projects
            
            if (selected.length > 0) {
                url.searchParams.set('project_ids', selected.join(','));
            }
            
            window.location.href = url.toString();
        }
        
        function printIndicators() {
            // Hide non-printable elements
            const style = document.createElement('style');
            style.innerHTML = '@media print { .no-print, .toolbar, .btn, button { display: none !important; } }';
            document.head.appendChild(style);
            window.print();
            // Remove style after printing
            setTimeout(() => document.head.removeChild(style), 1000);
        }
        
        function shareIndicators() {
            const projectIds = <?php echo json_encode($selected_project_ids); ?>;
            if (projectIds.length === 0) {
                alert('Please select at least one project.');
                return;
            }
            
            // Create shareable URL
            const url = window.location.origin + window.location.pathname + '?project_ids=' + projectIds.join(',');
            
            // Try to use Web Share API if available
            if (navigator.share) {
                navigator.share({
                    title: 'Indicators - Health Reporting System',
                    text: 'View indicators for selected projects',
                    url: url
                }).catch(err => {
                    // Fallback to clipboard
                    copyToClipboard(url);
                });
            } else {
                // Fallback to clipboard
                copyToClipboard(url);
            }
        }
        
        function copyToClipboard(text) {
            const textarea = document.createElement('textarea');
            textarea.value = text;
            textarea.style.position = 'fixed';
            textarea.style.opacity = '0';
            document.body.appendChild(textarea);
            textarea.select();
            try {
                document.execCommand('copy');
                alert('Link copied to clipboard!');
            } catch (err) {
                alert('Could not copy to clipboard. Please copy manually: ' + text);
            }
            document.body.removeChild(textarea);
        }
        
        function downloadIndicators() {
            const projectIds = <?php echo json_encode($selected_project_ids); ?>;
            if (projectIds.length === 0) {
                alert('Please select at least one project.');
                return;
            }
            // Download as Excel by default
            exportIndicators('excel');
        }
        
        function copyToClipboard(text) {
            if (navigator.clipboard) {
                navigator.clipboard.writeText(text).then(() => {
                    alert('Link copied to clipboard!\n\n' + text);
                }).catch(err => {
                    prompt('Copy this link:', text);
                });
            } else {
                prompt('Copy this link:', text);
            }
        }
    </script>
</body>
</html>

<?php require_once __DIR__ . '/../footer.php'; ?>
