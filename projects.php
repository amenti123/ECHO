<?php
/* Output buffering protects JSON/download responses from notices/warnings */
if (!headers_sent()) { @ob_start(); }
// =======================
// projects.php (top part)
// =======================

// 0) Load helpers/db FIRST (no HTML output yet!)
require_once __DIR__ . '/../helpers.php';
$pdo = getPDO();

// ------------------------------------------------------
// Projects module: safe helpers (avoid redeclare + enable cascade deletes)
// ------------------------------------------------------
if (!function_exists('projectsx_clean_output_buffers')) {
    function projectsx_clean_output_buffers(): void {
        // Clear all output buffers to protect JSON and file downloads
        while (ob_get_level() > 0) {
            @ob_end_clean();
        }
    }
}

if (!function_exists('projectsx_current_db_name')) {
    function projectsx_current_db_name(PDO $pdo): string {
        try {
            $db = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
            if ($db !== '') return $db;
        } catch (Throwable $e) {
            // ignore
        }
        // Fallback to config constant if present
        return defined('DB_NAME') ? (string)DB_NAME : '';
    }
}

if (!function_exists('projectsx_tables_with_project_id')) {
    function projectsx_tables_with_project_id(PDO $pdo): array {
        $db = projectsx_current_db_name($pdo);
        if ($db === '') return [];
        $stmt = $pdo->prepare("SELECT DISTINCT TABLE_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = ? AND COLUMN_NAME = 'project_id'");
        $stmt->execute([$db]);
        $tables = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
        // Keep only safe identifier-like table names
        $safe = [];
        foreach ($tables as $t) {
            $t = (string)$t;
            if ($t !== '' && preg_match('/^[A-Za-z0-9_]+$/', $t)) $safe[] = $t;
        }
        // Ensure stable order; delete child tables first, projects last
        sort($safe);
        // Move `projects` to the end if present
        $safe = array_values(array_filter($safe, fn($t) => $t !== 'projects'));
        $safe[] = 'projects';
        return $safe;
    }
}

if (!function_exists('projectsx_cascade_delete_project')) {
    /**
     * Hard-delete a project AND all linked records across modules (tables with project_id).
     * Returns an array of [table => deleted_rows].
     */
    function projectsx_cascade_delete_project(PDO $pdo, int $projectId): array {
        $summary = [];
        $tables = projectsx_tables_with_project_id($pdo);
        if (!$tables) {
            // Minimal fallback
            $pdo->prepare('DELETE FROM projects WHERE id = ?')->execute([$projectId]);
            $summary['projects'] = 1;
            return $summary;
        }

        // Foreign keys sometimes block deletes across modules; we disable checks temporarily.
        $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        try {
            foreach ($tables as $table) {
                if ($table === 'projects') {
                    $stmt = $pdo->prepare('DELETE FROM projects WHERE id = ?');
                    $stmt->execute([$projectId]);
                    $summary['projects'] = $stmt->rowCount();
                    continue;
                }
                // Delete linked rows
                $sql = "DELETE FROM `{$table}` WHERE project_id = ?";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([$projectId]);
                $summary[$table] = $stmt->rowCount();
            }
        } finally {
            $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
        }
        return $summary;
    }
}


// Ensure donors are seeded in projects.php too
// The seed function is now in helpers.php, so it should be available
if (function_exists('seed_comprehensive_donors')) {
    try {
        seed_comprehensive_donors($pdo);
        error_log("Projects: Donors seeded successfully");
    } catch (Exception $e) {
        error_log("Projects: Error seeding donors: " . $e->getMessage());
    }
} else {
    error_log("Projects: WARNING - seed_comprehensive_donors function not found! This should be in helpers.php");
}

// ---- Safe fallbacks for AJAX data (zones / woredas) ----
if (!function_exists('db_get_zones_by_region')) {
    function db_get_zones_by_region(int $region_id, PDO $pdo): array {
        // Guard against missing table / SQL issues: never throw fatal
        try {
            if (!table_exists($pdo, 'zones')) {
                return [];
            }
            $stmt = $pdo->prepare("SELECT id, name FROM zones WHERE region_id = ? ORDER BY name");
            if (!$stmt) {
                return [];
            }
            if (!$stmt->execute([$region_id])) {
                return [];
            }
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            error_log("db_get_zones_by_region error: " . $e->getMessage());
            return [];
        }
    }
}

if (!function_exists('db_get_woredas_by_zone')) {
    function db_get_woredas_by_zone(int $zone_id, PDO $pdo): array {
        // Guard against missing table / SQL issues: never throw fatal
        $stmt = $pdo->prepare("SELECT id, name FROM woredas WHERE zone_id = ? ORDER BY name");
        if (!$stmt) {
            return [];
        }
        if (!$stmt->execute([$zone_id])) {
            return [];
        }
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}

// 1) EARLY AJAX ENDPOINT — must be BEFORE any echo/HTML/header.php
// Handle AJAX requests for currency rate
if (isset($_GET['action']) && $_GET['action'] === 'get_currency_rate' && isset($_GET['currency'])) {
    header('Content-Type: application/json');
    $currency = trim($_GET['currency'] ?? '');
    $rate = 0;
    if (!empty($currency)) {
        try {
            $currStmt = $pdo->prepare("SELECT default_rate FROM system_currencies WHERE code = ? LIMIT 1");
            $currStmt->execute([$currency]);
            $currRow = $currStmt->fetch();
            if ($currRow && $currRow['default_rate'] > 0) {
                $rate = (float)$currRow['default_rate'];
            }
        } catch (Exception $e) {
            // Ignore
        }
    }
    echo json_encode(['success' => true, 'rate' => $rate]);
    exit;
}

if (isset($_GET['ajax']) && in_array($_GET['ajax'], ['zones','woredas'], true)) {
    header('Content-Type: application/json; charset=utf-8');
    $resp = ['success' => true, 'data' => []];

    try {
        if ($_GET['ajax'] === 'zones') {
            $region_id = (int)($_GET['region_id'] ?? 0);
            $resp['data'] = function_exists('db_get_zones_by_region')
                ? db_get_zones_by_region($region_id, $pdo)
                : [];
        } elseif ($_GET['ajax'] === 'woredas') {
            $zone_id = (int)($_GET['zone_id'] ?? 0);
            $resp['data'] = function_exists('db_get_woredas_by_zone')
                ? db_get_woredas_by_zone($zone_id, $pdo)
                : [];
        }
    } catch (Throwable $e) {
        $resp = ['success' => false, 'error' => $e->getMessage()];
    }

    echo json_encode($resp);
    exit; // stop here for AJAX requests
}

// 2) Now render the normal page (safe to include header.php)
require_once __DIR__ . '/../header.php';
require_login();
if (!is_admin()) {
    echo "<p>You must be admin.</p>";
    require_once __DIR__ . '/../footer.php';
    exit;
}

$message  = '';
$errorMsg = '';

// IMPORTANT: we do NOT call db_get_regions() here anymore.
// The upgraded "Lookups: regions / zones / woredas" section later in this file
// will safely build $regions, $zones, $woredas and $locations for you.
// ------------------------------------------------------ 
// NEW: API Integration and Sharing Functions
// ------------------------------------------------------ 
if (!function_exists('get_shareable_project_data')) {
    function get_shareable_project_data($project_id, $format = 'json') {
        global $pdo;
        
        $stmt = $pdo->prepare("
            SELECT p.*, 
                   r.name AS region_name, 
                   z.name AS zone_name, 
                   w.name AS woreda_name,
                   GROUP_CONCAT(DISTINCT pl.region_id) AS all_region_ids,
                   GROUP_CONCAT(DISTINCT pl.zone_id) AS all_zone_ids,
                   GROUP_CONCAT(DISTINCT pl.woreda_id) AS all_woreda_ids
            FROM projects p
            LEFT JOIN regions r ON p.region_id = r.id
            LEFT JOIN zones z ON p.zone_id = z.id
            LEFT JOIN woredas w ON p.woreda_id = w.id
            LEFT JOIN project_locations pl ON p.id = pl.project_id
            WHERE p.id = ?
            GROUP BY p.id
        ");
        $stmt->execute([$project_id]);
        $project = $stmt->fetch();
        
        if (!$project) {
            return null;
        }
        
        // Get multi-locations
        $locations_stmt = $pdo->prepare("
            SELECT pl.*, 
                   r.name AS region_name, 
                   z.name AS zone_name, 
                   w.name AS woreda_name
            FROM project_locations pl
            LEFT JOIN regions r ON pl.region_id = r.id
            LEFT JOIN zones z ON pl.zone_id = z.id
            LEFT JOIN woredas w ON pl.woreda_id = w.id
            WHERE pl.project_id = ?
        ");
        $locations_stmt->execute([$project_id]);
        $locations = $locations_stmt->fetchAll();
        
        // Parse sectors
        $main_sectors     = function_exists('parse_multi_values') ? parse_multi_values($project['main_sectors'] ?? '') : [];
        $specific_sectors = function_exists('parse_multi_values') ? parse_multi_values($project['specific_sectors'] ?? '') : [];
        
        // Calculate project period
        $project_period = function_exists('calculate_project_period')
            ? calculate_project_period($project['start_date'], $project['end_date'])
            : null;
        $project_months = function_exists('calculate_project_months')
            ? calculate_project_months($project['start_date'], $project['end_date'])
            : null;
        
        $data = [
            'id' => (int)$project['id'],
            'code' => $project['code'],
            'title' => $project['title'],
            'project_type' => $project['project_type'] ?? 'Development',
            'status' => $project['status'],
            'donor' => $project['donor'],
            'donor_ref' => $project['donor_ref'],
            // 'lead_partner' removed because lp_name is deleted
            'implementing_partner' => $project['ip_name'],
            'locations' => $locations,
            'primary_region' => $project['region_name'] ?? $project['region_other'],
            'primary_zone' => $project['zone_name'] ?? $project['zone_other'],
            'primary_woreda' => $project['woreda_name'] ?? $project['woreda_other'],
            'start_date' => $project['start_date'],
            'end_date' => $project['end_date'],
            'project_period' => $project_period,
            'project_months' => $project_months,
            'budget' => [
                'usd' => (float)$project['total_fund_usd'],
                'etb' => (float)$project['total_fund_etb'],
                'exchange_rate' => (float)$project['currency_rate']
            ],
            'contacts' => [
                'owner' => [
                    'name' => $project['owner_name'],
                    'email' => $project['owner_email'],
                    'phone' => $project['owner_phone']
                ],
                'program_manager' => $project['PM_email'],
                'operations_manager' => $project['opm_email'],
                'merl_manager' => $project['merl_email'],
                'finance_head' => $project['finance_head_email'],
                'finance_officer' => $project['finance_officer_email'],
                'project_officer' => $project['project_officer_email']
            ],
            'sectors' => [
                'main' => $main_sectors,
                'specific' => $specific_sectors
            ],
            'timestamps' => [
                'created' => $project['created_at'],
                'modified' => $project['updated_at'] ?? $project['created_at']
            ],
            'shareable_links' => [
                'web' => generate_shareable_link($project_id, 'web'),
                'api' => generate_shareable_link($project_id, 'api'),
                'pdf' => generate_shareable_link($project_id, 'pdf')
            ]
        ];
    
        switch ($format) {
            case 'json':
                return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            case 'array':
                return $data;
            case 'xml':
                return array_to_xml($data);
            default:
                return $data;
        }
    }
}

if (!function_exists('array_to_xml')) {
    function array_to_xml($data, $root = 'project') {
        $xml = new SimpleXMLElement('<?xml version="1.0" encoding="UTF-8"?><' . $root . '/>');
        array_to_xml_converter($data, $xml);
        return $xml->asXML();
    }
    
    function array_to_xml_converter($data, &$xml) {
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                if (is_numeric($key)) {
                    $key = 'item_' . $key;
                }
                $subnode = $xml->addChild($key);
                array_to_xml_converter($value, $subnode);
            } else {
                $xml->addChild($key, htmlspecialchars((string)$value));
            }
        }
    }
}

if (!function_exists('generate_api_token')) {
    function generate_api_token($project_id, $access_level = 'read') {
        $token_data = [
            'project_id' => $project_id,
            'access_level' => $access_level,
            'created' => time(),
            'expires' => time() + (30 * 24 * 60 * 60) // 30 days
        ];
        
        $token = base64_encode(json_encode($token_data));
        return $token;
    }
}

if (!function_exists('validate_api_token')) {
    function validate_api_token($token) {
        try {
            $data = json_decode(base64_decode($token), true);
            if (!$data || !isset($data['expires']) || $data['expires'] < time()) {
                return false;
            }
            return $data;
        } catch (Exception $e) {
            return false;
        }
    }
}

// ------------------------------------------------------ 
// NEW: Webhook and Integration System
// ------------------------------------------------------ 
if (!function_exists('trigger_webhook')) {
    function trigger_webhook($project_id, $event_type) {
        global $pdo;
        
        // Get webhook configurations
        $webhooks = $pdo->query("SELECT * FROM webhooks WHERE is_active = 1")->fetchAll();
        
        foreach ($webhooks as $webhook) {
            if (strpos($webhook['events'], $event_type) !== false || $webhook['events'] === '*') {
                $project_data = get_shareable_project_data($project_id, 'array');
                $payload = [
                    'event' => $event_type,
                    'timestamp' => date('c'),
                    'project' => $project_data
                ];
                
                send_webhook_request($webhook['url'], $payload, $webhook['secret_key']);
            }
        }
    }
}

if (!function_exists('send_webhook_request')) {
    function send_webhook_request($url, $payload, $secret = null) {
        $headers = [
            'Content-Type: application/json',
            'User-Agent: Project-Registry-System/1.0'
        ];
        
        if ($secret) {
            $signature = hash_hmac('sha256', json_encode($payload), $secret);
            $headers[] = 'X-Webhook-Signature: ' . $signature;
        }
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        return $http_code >= 200 && $http_code < 300;
    }
}

// ------------------------------------------------------ 
// NEW: Real-time Collaboration Features
// ------------------------------------------------------ 
if (!function_exists('get_project_collaborators')) {
    function get_project_collaborators($project_id) {
        global $pdo;
        
        $stmt = $pdo->prepare("
            SELECT DISTINCT email, role, last_access
            FROM project_collaborators 
            WHERE project_id = ? 
            ORDER BY last_access DESC
        ");
        $stmt->execute([$project_id]);
        return $stmt->fetchAll();
    }
}

if (!function_exists('update_collaborator_access')) {
    function update_collaborator_access($project_id, $email, $role = 'viewer') {
        global $pdo;
        
        $stmt = $pdo->prepare("
            INSERT INTO project_collaborators (project_id, email, role, last_access) 
            VALUES (?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE last_access = NOW(), role = VALUES(role)
        ");
        return $stmt->execute([$project_id, $email, $role]);
    }
}

// ------------------------------------------------------ 
// Project Period Calculation Function (IMPROVED) 
// ------------------------------------------------------ 
if (!function_exists('calculate_project_period')) { 
    function calculate_project_period($start_date, $end_date) { 
        if (empty($start_date) || empty($end_date)) { 
            return 'N/A'; 
        } 

        try { 
            $start = new DateTime($start_date); 
            $end   = new DateTime($end_date); 

            if ($end < $start) { 
                return 'Invalid dates'; 
            } 

            $interval = $start->diff($end); 
            $years = $interval->y; 
            $months = $interval->m; 
            $days = $interval->d; 

            // Add one month if there are remaining days 
            if ($days > 0) { 
                $months++; 
            } 

            $total_months = ($years * 12) + $months; 

            if ($total_months == 0) { 
                return 'Less than 1 month'; 
            } elseif ($total_months == 1) { 
                return '1 month'; 
            } elseif ($total_months < 12) { 
                return $total_months . ' months'; 
            } else { 
                $years = floor($total_months / 12); 
                $remaining_months = $total_months % 12; 

                if ($remaining_months == 0) { 
                    return $years . ' Year' . ($years > 1 ? 's' : ''); 
                } else { 
                    return $years . ' Year' . ($years > 1 ? 's' : '') . ' ' . 
                            $remaining_months . ' month' . ($remaining_months > 1 ? 's' : ''); 
                } 
            } 
        } catch (Exception $e) { 
            return 'Invalid date format'; 
        } 
    } 
} 

// NEW: Project period in total months (for summary/analytics)
if (!function_exists('calculate_project_months')) {
    function calculate_project_months($start_date, $end_date)
    {
        if (empty($start_date) || empty($end_date)) {
            return null;
        }

        try {
            $start = new DateTime($start_date);
            $end   = new DateTime($end_date);

            if ($end < $start) {
                return null;
            }

            $interval = $start->diff($end);
            $years  = $interval->y;
            $months = $interval->m;
            $days   = $interval->d;

            // If there are remaining days, count it as one more month
            if ($days > 0) {
                $months++;
            }

            return ($years * 12) + $months;
        } catch (Exception $e) {
            return null;
        }
    }
}

// ------------------------------------------------------ 
// NEW: Enhanced Analytics Functions with Project Type
// ------------------------------------------------------ 
if (!function_exists('get_projects_analytics')) { 
    function get_projects_analytics($pdo) { 
        $analytics = []; 

        // Projects by Status 
        $stmt = $pdo->query(" 
            SELECT status, COUNT(*) as count  
            FROM projects  
            GROUP BY status 
        "); 
        $analytics['by_status'] = $stmt->fetchAll(); 

        // NEW: Projects by Project Type
        $stmt = $pdo->query(" 
            SELECT project_type, COUNT(*) as count  
            FROM projects  
            GROUP BY project_type 
        "); 
        $analytics['by_project_type'] = $stmt->fetchAll();

        // Projects by Region 
        $stmt = $pdo->query(" 
            SELECT COALESCE(r.name, p.region_other, 'Not Specified') as region,  
                    COUNT(*) as count  
            FROM projects p  
            LEFT JOIN regions r ON p.region_id = r.id  
            GROUP BY COALESCE(r.name, p.region_other, 'Not Specified') 
            ORDER BY count DESC 
        "); 
        $analytics['by_region'] = $stmt->fetchAll(); 

        // Projects by Zone 
        $stmt = $pdo->query(" 
            SELECT COALESCE(z.name, p.zone_other, 'Not Specified') as zone,  
                    COUNT(*) as count  
            FROM projects p  
            LEFT JOIN zones z ON p.zone_id = z.id  
            GROUP BY COALESCE(z.name, p.zone_other, 'Not Specified') 
            ORDER BY count DESC 
            LIMIT 20 
        "); 
        $analytics['by_zone'] = $stmt->fetchAll(); 

        // Projects by Woreda 
        $stmt = $pdo->query(" 
            SELECT COALESCE(w.name, p.woreda_other, 'Not Specified') as woreda,  
                    COUNT(*) as count  
            FROM projects p  
            LEFT JOIN woredas w ON p.woreda_id = w.id  
            GROUP BY COALESCE(w.name, p.woreda_other, 'Not Specified') 
            ORDER BY count DESC 
            LIMIT 20 
        "); 
        $analytics['by_woreda'] = $stmt->fetchAll(); 

        // Projects by Donor 
        $stmt = $pdo->query(" 
            SELECT COALESCE(donor, 'Not Specified') as donor,  
                    COUNT(*) as count,  
                   SUM(total_fund_usd) as total_funding 
            FROM projects  
            GROUP BY COALESCE(donor, 'Not Specified') 
            ORDER BY total_funding DESC 
        "); 
        $analytics['by_donor'] = $stmt->fetchAll(); 

        // Projects by Implementing Partner 
        $stmt = $pdo->query(" 
            SELECT COALESCE(ip_name, 'Not Specified') as ip,  
                    COUNT(*) as count,  
                   SUM(total_fund_usd) as total_funding 
            FROM projects  
            GROUP BY COALESCE(ip_name, 'Not Specified') 
            ORDER BY total_funding DESC 
        "); 
        $analytics['by_ip'] = $stmt->fetchAll(); 

       // Projects by Project Period (using months function)
        $stmt = $pdo->query("
            SELECT p.*,
                   COALESCE(r.name, p.region_other) as region_name,
                   COALESCE(z.name, p.zone_other) as zone_name,
                   COALESCE(w.name, p.woreda_other) as woreda_name
            FROM projects p
            LEFT JOIN regions r ON p.region_id = r.id
            LEFT JOIN zones   z ON p.zone_id   = z.id
            LEFT JOIN woredas w ON p.woreda_id = w.id
        ");
        $allProjects = $stmt->fetchAll();

        $periodGroups = [
            '0-6 months'  => 0,
            '6-12 months' => 0,
            '1-2 years'   => 0,
            '2+ years'    => 0,
        ];

        foreach ($allProjects as $project) {
            $months = calculate_project_months($project['start_date'], $project['end_date']);
            if ($months === null) {
                continue;
            }

            if ($months <= 6) {
                $periodGroups['0-6 months']++;
            } elseif ($months <= 12) {
                $periodGroups['6-12 months']++;
            } elseif ($months <= 24) {
                $periodGroups['1-2 years']++;
            } else {
                $periodGroups['2+ years']++;
            }
        }

        $analytics['by_period'] = $periodGroups;

        // Funding by Sector 
        $stmt = $pdo->query(" 
            SELECT main_sectors, SUM(total_fund_usd) as total_funding 
            FROM projects  
            WHERE main_sectors IS NOT NULL AND main_sectors != '' 
            GROUP BY main_sectors 
            ORDER BY total_funding DESC 
            LIMIT 10 
        "); 
        $analytics['funding_by_sector'] = $stmt->fetchAll(); 

        // NEW: Funding by Project Type
        $stmt = $pdo->query(" 
            SELECT project_type, SUM(total_fund_usd) as total_funding,
                   COUNT(*) as project_count
            FROM projects  
            WHERE project_type IS NOT NULL 
            GROUP BY project_type 
            ORDER BY total_funding DESC 
        "); 
        $analytics['funding_by_project_type'] = $stmt->fetchAll();

        return $analytics; 
    } 
} 

// ------------------------------------------------------ 
// NEW: Enhanced Project Statistics with Project Type
// ------------------------------------------------------ 
if (!function_exists('get_project_statistics')) { 
    function get_project_statistics($pdo) { 
        $stats = []; 

        // Basic counts 
        $stmt = $pdo->query("SELECT COUNT(*) as total FROM projects"); 
        $stats['total_projects'] = $stmt->fetchColumn(); 

        $stmt = $pdo->query("SELECT COUNT(*) as active FROM projects WHERE status = 'active'"); 
        $stats['active_projects'] = $stmt->fetchColumn(); 

        $stmt = $pdo->query("SELECT COUNT(*) as planned FROM projects WHERE status = 'planned'"); 
        $stats['planned_projects'] = $stmt->fetchColumn(); 

        $stmt = $pdo->query("SELECT COUNT(*) as closed FROM projects WHERE status = 'closed'"); 
        $stats['closed_projects'] = $stmt->fetchColumn(); 

        // NEW: Count by project type
        $stmt = $pdo->query("SELECT project_type, COUNT(*) as count FROM projects GROUP BY project_type"); 
        $stats['by_project_type'] = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

        // Funding totals 
        $stmt = $pdo->query("SELECT SUM(total_fund_usd) as total FROM projects"); 
        $stats['total_funding_usd'] = $stmt->fetchColumn() ?? 0; 

        $stmt = $pdo->query("SELECT SUM(total_fund_etb) as total FROM projects"); 
        $stats['total_funding_etb'] = $stmt->fetchColumn() ?? 0; 

        // NEW: Funding by project type
        $stmt = $pdo->query("SELECT project_type, SUM(total_fund_usd) as total FROM projects GROUP BY project_type"); 
        $stats['funding_by_project_type'] = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

        // Average project duration 
        $stmt = $pdo->query("SELECT AVG(DATEDIFF(end_date, start_date)) as avg_days FROM projects WHERE start_date IS NOT NULL AND end_date IS NOT NULL"); 
        $avgDays = $stmt->fetchColumn() ?? 0; 
        $stats['avg_duration_months'] = round($avgDays / 30.44, 1); 

        return $stats; 
    } 
} 

// ------------------------------------------------------ 
// AI Assistant System 
// ------------------------------------------------------ 
if (!function_exists('get_ai_suggestion')) { 
    function get_ai_suggestion($context, $user_input = '') { 
        $suggestions = [ 
            'project_setup' => [ 
                'title'    => 'Project Setup Assistant', 
                'message'  => 'I recommend completing all required fields including project code, title, donor information. Make sure to set realistic start and end dates.', 
                'priority' => 'info' 
            ], 
            'budget_optimization' => [ 
                'title'    => 'Budget Optimization Tip', 
                'message'  => 'Consider breaking down your budget by quarters and including contingency funds (10–15% recommended).', 
                'priority' => 'warning' 
            ], 
            'team_coordination' => [ 
                'title'    => 'Team Coordination', 
                'message'  => 'Ensure all key team members (Program Manager, Finance, MERL) have been assigned and notified.', 
                'priority' => 'success' 
            ], 
            'risk_management' => [ 
                'title'    => 'Risk Management', 
                'message'  => 'Have you identified potential risks and mitigation strategies for this project?', 
                'priority' => 'danger' 
            ],
            'project_type_selection' => [
                'title'    => 'Project Type Guidance',
                'message'  => 'Choose the project type that best matches your intervention: Development for long-term programs, Emergency for immediate response, Resilience/Recovery for building community capacity, or Outbreak response for disease control.',
                'priority' => 'info'
            ]
        ]; 

        $needle = strtolower($context . ' ' . $user_input);

        if (strpos($needle, 'budget') !== false) { 
            return $suggestions['budget_optimization']; 
        } elseif (strpos($needle, 'team') !== false) { 
            return $suggestions['team_coordination']; 
        } elseif (strpos($needle, 'risk') !== false) { 
            return $suggestions['risk_management']; 
        } elseif (strpos($needle, 'type') !== false) { 
            return $suggestions['project_type_selection'];
        } else { 
            return $suggestions['project_setup']; 
        } 
    } 
} 

// ------------------------------------------------------ 
// Real-time Notification System 
// ------------------------------------------------------ 
if (!function_exists('add_realtime_notification')) { 
    function add_realtime_notification($type, $message, $project_id = null) { 
        if (!isset($_SESSION['realtime_notifications']) || !is_array($_SESSION['realtime_notifications'])) { 
            $_SESSION['realtime_notifications'] = []; 
        } 
        $_SESSION['realtime_notifications'][] = [ 
            'type'       => $type, 
            'message'    => $message, 
            'project_id' => $project_id, 
            'timestamp'  => date('Y-m-d H:i:s') 
        ]; 
    } 
} 

if (!isset($_SESSION['realtime_notifications'])) { 
    $_SESSION['realtime_notifications'] = []; 
} 

// ------------------------------------------------------ 
// Enhanced Shareable Link Generation with API Support
// ------------------------------------------------------ 
if (!function_exists('generate_shareable_link')) { 
    function generate_shareable_link($project_id, $platform) { 
        $base_url    = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://$_SERVER[HTTP_HOST]"; 
        
        switch ($platform) { 
            case 'whatsapp': 
                $project_url = $base_url . str_replace(basename($_SERVER['PHP_SELF']), 'project_view.php?id=' . $project_id, $_SERVER['REQUEST_URI']);
                return "https://wa.me/?text=" . urlencode("Check this project: " . $project_url); 
            case 'telegram': 
                $project_url = $base_url . str_replace(basename($_SERVER['PHP_SELF']), 'project_view.php?id=' . $project_id, $_SERVER['REQUEST_URI']);
                return "https://t.me/share/url?url=" . urlencode($project_url) . "&text=" . urlencode("Project Details"); 
            case 'email': 
                $project_url = $base_url . str_replace(basename($_SERVER['PHP_SELF']), 'project_view.php?id=' . $project_id, $_SERVER['REQUEST_URI']);
                return "mailto:?subject=" . urlencode("Project Details") . "&body=" . urlencode("Check this project: " . $project_url); 
            case 'api':
                return $base_url . '/api/project/' . $project_id . '/json';
            case 'pdf':
                return $base_url . '/export/project/' . $project_id . '/pdf';
            case 'web':
            default:
                return $base_url . str_replace(basename($_SERVER['PHP_SELF']), 'project_view.php?id=' . $project_id, $_SERVER['REQUEST_URI']);
        } 
    } 
} 

// ------------------------------------------------------ 
// FIXED: Clean Export Functions (NO HTML/APP CONTENT) 
// ------------------------------------------------------ 
if (!function_exists('export_to_pdf')) { 
    function export_to_pdf($projects, $filename = 'projects_list.html') { 
        // Clear any previous output 
        if (ob_get_length()) ob_clean(); 

        header("Content-Type: text/html; charset=UTF-8"); 
        header('Content-Disposition: attachment; filename="' . $filename . '"'); 
        header("Pragma: no-cache"); 
        header("Expires: 0"); 

        // SIMPLE HTML without application branding 
        $html = "<!DOCTYPE html><html><head><meta charset='UTF-8'><title>Projects Export</title><style> 
            body { font-family: Arial, sans-serif; margin: 20px; } 
            .header { text-align: center; margin-bottom: 20px; border-bottom: 2px solid #333; padding-bottom: 10px; } 
            table { width: 100%; border-collapse: collapse; margin-top: 10px; } 
            th, td { border: 1px solid #ddd; padding: 8px; text-align: left; font-size: 12px; } 
            th { background-color: #f2f2f2; font-weight: bold; } 
            .footer { margin-top: 20px; text-align: center; font-size: 10px; color: #666; } 
            tr:nth-child(even) { background-color: #f9f9f9; } 
        </style></head><body>"; 

        $html .= "<div class='header'> 
            <h1>Projects Registry Report</h1> 
            <p>Generated on: " . date('Y-m-d H:i:s') . "</p> 
            <p>Total Projects: " . count($projects) . "</p> 
        </div>"; 

        $html .= "<table> 
            <thead> 
                <tr> 
                    <th>ID</th><th>Code</th><th>Title</th><th>Project Type</th><th>Status</th><th>Donor</th><th>Donor Ref</th> 
                    <th>Implementing Partner</th><th>Region</th><th>Zone</th><th>Woreda</th> 
                    <th>Start Date</th><th>End Date</th><th>Project Period</th><th>Total Fund (USD)</th> 
                    <th>Main Sectors</th><th>Specific Areas</th> 
                </tr> 
            </thead> 
            <tbody>"; 

        foreach ($projects as $p) { 
            $project_period  = calculate_project_period($p['start_date'], $p['end_date']); 
            $mainSectors     = function_exists('parse_multi_values') ? parse_multi_values($p['main_sectors'] ?? '') : []; 
            $specificSectors = function_exists('parse_multi_values') ? parse_multi_values($p['specific_sectors'] ?? '') : []; 

            $html .= "<tr> 
                <td>" . (int)$p['id'] . "</td> 
                <td>" . htmlspecialchars($p['code'] ?? '') . "</td> 
                <td>" . htmlspecialchars($p['title'] ?? '') . "</td> 
                <td>" . htmlspecialchars($p['project_type'] ?? 'Development') . "</td> 
                <td>" . htmlspecialchars($p['status'] ?? '') . "</td> 
                <td>" . htmlspecialchars($p['donor'] ?? '') . "</td> 
                <td>" . htmlspecialchars($p['donor_ref'] ?? '') . "</td> 
                <td>" . htmlspecialchars($p['ip_name'] ?? '') . "</td> 
                <td>" . htmlspecialchars($p['region_name'] ?? '') . "</td> 
                <td>" . htmlspecialchars($p['zone_name'] ?? '') . "</td> 
                <td>" . htmlspecialchars($p['woreda_name'] ?? '') . "</td> 
                <td>" . htmlspecialchars($p['start_date'] ?? '') . "</td> 
                <td>" . htmlspecialchars($p['end_date'] ?? '') . "</td> 
                <td>" . htmlspecialchars($project_period) . "</td> 
                <td>$" . number_format((float)($p['total_fund_usd'] ?? 0), 2) . "</td> 
                <td>" . htmlspecialchars(implode(', ', $mainSectors)) . "</td> 
                <td>" . htmlspecialchars(implode(', ', $specificSectors)) . "</td> 
            </tr>"; 
        } 

        $html .= "</tbody></table>"; 
        $html .= "<div class='footer'>Generated on " . date('Y-m-d H:i:s') . "</div>"; 
        $html .= "</body></html>"; 

        echo $html; 
        exit; 
    } 
} 

if (!function_exists('export_to_excel')) { 
    function export_to_excel($projects, $filename = 'projects_list.xls') { 
        // Clear any previous output 
        if (ob_get_length()) ob_clean(); 

        header("Content-Type: application/vnd.ms-excel; charset=UTF-8"); 
        header('Content-Disposition: attachment; filename="' . $filename . '"'); 
        header("Pragma: no-cache"); 
        header("Expires: 0"); 

        // Output BOM for UTF-8 
        echo "\xEF\xBB\xBF"; 

        // Headers only - NO application content 
        echo "ID\tCode\tTitle\tProject Type\tStatus\tDonor\tDonor ref\tImplementing partner\tRegion\tZone\tWoreda\tStart date\tEnd date\tProject Period\tTotal fund (USD)\tCurrency rate\tTotal fund (ETB)\tOwner name\tOwner email\tOwner phone\tED email\tProgram Manager email\tOPM email\tMERL email\tFinance head email\tFinance officer email\tProject officer email\tMain sectors\tSpecific sectors\tCreated at\n"; 

        foreach ($projects as $p) { 
            $project_period  = calculate_project_period($p['start_date'], $p['end_date']); 
            $mainSectors     = function_exists('parse_multi_values') ? parse_multi_values($p['main_sectors'] ?? '') : []; 
            $specificSectors = function_exists('parse_multi_values') ? parse_multi_values($p['specific_sectors'] ?? '') : []; 

            echo (int)$p['id'] . "\t"; 
            echo ($p['code'] ?? '') . "\t"; 
            echo ($p['title'] ?? '') . "\t"; 
            echo ($p['project_type'] ?? 'Development') . "\t"; 
            echo ($p['status'] ?? '') . "\t"; 
            echo ($p['donor'] ?? '') . "\t"; 
            echo ($p['donor_ref'] ?? '') . "\t"; 
            echo ($p['ip_name'] ?? '') . "\t"; 
            echo ($p['region_name'] ?? '') . "\t"; 
            echo ($p['zone_name'] ?? '') . "\t"; 
            echo ($p['woreda_name'] ?? '') . "\t"; 
            echo ($p['start_date'] ?? '') . "\t"; 
            echo ($p['end_date'] ?? '') . "\t"; 
            echo $project_period . "\t"; 
            echo (float)($p['total_fund_usd'] ?? 0) . "\t"; 
            echo (float)($p['currency_rate'] ?? 0) . "\t"; 
            echo (float)($p['total_fund_etb'] ?? 0) . "\t"; 
            echo ($p['owner_name'] ?? '') . "\t"; 
            echo ($p['owner_email'] ?? '') . "\t"; 
            echo ($p['owner_phone'] ?? '') . "\t"; 
            echo ($p['ed_email'] ?? '') . "\t"; 
            echo ($p['PM_email'] ?? '') . "\t"; 
            echo ($p['opm_email'] ?? '') . "\t"; 
            echo ($p['merl_email'] ?? '') . "\t"; 
            echo ($p['finance_head_email'] ?? '') . "\t"; 
            echo ($p['finance_officer_email'] ?? '') . "\t"; 
            echo ($p['project_officer_email'] ?? '') . "\t"; 
            echo implode(', ', $mainSectors) . "\t"; 
            echo implode(', ', $specificSectors) . "\t"; 
            echo ($p['created_at'] ?? '') . "\n"; 
        } 
        exit; 
    } 
} 

if (!function_exists('export_single_project_html')) { 
    function export_single_project_html($project, $filename = 'project_details.html') { 
        // Clear any previous output 
        if (ob_get_length()) ob_clean(); 

        $project_period = calculate_project_period($project['start_date'], $project['end_date']); 

        header("Content-Type: text/html; charset=UTF-8"); 
        header('Content-Disposition: attachment; filename="' . $filename . '"'); 
        header("Pragma: no-cache"); 
        header("Expires: 0"); 

        $mainSectors     = function_exists('parse_multi_values') ? parse_multi_values($project['main_sectors'] ?? '') : []; 
        $specificSectors = function_exists('parse_multi_values') ? parse_multi_values($project['specific_sectors'] ?? '') : []; 

        $html = "<!DOCTYPE html> 
        <html> 
        <head> 
            <meta charset='UTF-8'> 
            <title>Project Details - " . htmlspecialchars($project['title'] ?? '') . "</title> 
            <style> 
                body { font-family: Arial, sans-serif; margin: 20px; line-height: 1.6; } 
                .header { text-align: center; margin-bottom: 30px; border-bottom: 2px solid #333; padding-bottom: 15px; } 
                .section { margin-bottom: 25px; padding: 15px; border: 1px solid #ddd; border-radius: 5px; } 
                .section h3 { margin-top: 0; color: #2c3e50; border-bottom: 1px solid #eee; padding-bottom: 8px; } 
                .info-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 15px; } 
                .info-item { margin-bottom: 10px; } 
                .label { font-weight: bold; color: #555; } 
                .value { color: #333; } 
                .footer { margin-top: 30px; text-align: center; font-size: 12px; color: #666; border-top: 1px solid #ddd; padding-top: 15px; } 
                .period-badge { background: #e3f2fd; color: #1976d2; padding: 4px 8px; border-radius: 4px; font-weight: bold; } 
                .type-badge { background: #e8f5e8; color: #2e7d32; padding: 4px 8px; border-radius: 4px; font-weight: bold; } 
            </style> 
        </head> 
        <body> 
            <div class='header'> 
                <h1>Project Details</h1> 
                <h2>" . htmlspecialchars($project['title'] ?? '') . "</h2> 
                <p>Generated on: " . date('Y-m-d H:i:s') . "</p> 
            </div> 

            <div class='section'> 
                <h3>📋 Basic Information</h3> 
                <div class='info-grid'> 
                    <div class='info-item'><span class='label'>Project Code:</span> <span class='value'>" . htmlspecialchars($project['code'] ?? 'N/A') . "</span></div> 
                    <div class='info-item'><span class='label'>Project Type:</span> <span class='value'><span class='type-badge'>" . htmlspecialchars($project['project_type'] ?? 'Development') . "</span></span></div> 
                    <div class='info-item'><span class='label'>Status:</span> <span class='value'>" . htmlspecialchars($project['status'] ?? 'N/A') . "</span></div> 
                    <div class='info-item'><span class='label'>Donor:</span> <span class='value'>" . htmlspecialchars($project['donor'] ?? 'N/A') . "</span></div> 
                    <div class='info-item'><span class='label'>Donor Reference:</span> <span class='value'>" . htmlspecialchars($project['donor_ref'] ?? 'N/A') . "</span></div> 
                    <div class='info-item'><span class='label'>Implementing Partner:</span> <span class='value'>" . htmlspecialchars($project['ip_name'] ?? 'N/A') . "</span></div> 
                    <div class='info-item'><span class='label'>Project Period:</span> <span class='value'><span class='period-badge'>" . htmlspecialchars($project_period) . "</span></span></div> 
                    <div class='info-item'><span class='label'>Main Programme Sectors:</span> <span class='value'>" . htmlspecialchars(implode(', ', $mainSectors) ?: 'N/A') . "</span></div> 
                    <div class='info-item'><span class='label'>Project Specific Areas:</span> <span class='value'>" . htmlspecialchars(implode(', ', $specificSectors) ?: 'N/A') . "</span></div> 
                </div> 
            </div> 

            <div class='section'> 
                <h3>📍 Location Information</h3> 
                <div class='info-grid'> 
                    <div class='info-item'><span class='label'>Region:</span> <span class='value'>" . htmlspecialchars($project['region_name'] ?? ($project['region_other'] ?? 'N/A')) . "</span></div> 
                    <div class='info-item'><span class='label'>Zone:</span> <span class='value'>" . htmlspecialchars($project['zone_name'] ?? ($project['zone_other'] ?? 'N/A')) . "</span></div> 
                    <div class='info-item'><span class='label'>Woreda:</span> <span class='value'>" . htmlspecialchars($project['woreda_name'] ?? ($project['woreda_other'] ?? 'N/A')) . "</span></div> 
                    <div class='info-item'><span class='label'>Start Date:</span> <span class='value'>" . htmlspecialchars($project['start_date'] ?? 'N/A') . "</span></div> 
                    <div class='info-item'><span class='label'>End Date:</span> <span class='value'>" . htmlspecialchars($project['end_date'] ?? 'N/A') . "</span></div> 
                </div> 
            </div> 

            <div class='section'> 
                <h3>💰 Budget Information</h3> 
                <div class='info-grid'> 
                    <div class='info-item'><span class='label'>Total Fund (USD):</span> <span class='value'>$".number_format((float)($project['total_fund_usd'] ?? 0), 2)."</span></div> 
                    <div class='info-item'><span class='label'>Currency Rate:</span> <span class='value'>".(float)($project['currency_rate'] ?? 0)."</span></div> 
                    <div class='info-item'><span class='label'>Total Fund (ETB):</span> <span class='value'>ETB ".number_format((float)($project['total_fund_etb'] ?? 0), 2)."</span></div> 
                </div> 
            </div> 

            <div class='section'> 
                <h3>👥 Contact Information</h3> 
                <div class='info-grid'> 
                    <div class='info-item'><span class='label'>Owner Name:</span> <span class='value'>".htmlspecialchars($project['owner_name'] ?? 'N/A')."</span></div> 
                    <div class='info-item'><span class='label'>Owner Email:</span> <span class='value'>".htmlspecialchars($project['owner_email'] ?? 'N/A')."</span></div> 
                    <div class='info-item'><span class='label'>Owner Phone:</span> <span class='value'>".htmlspecialchars($project['owner_phone'] ?? 'N/A')."</span></div> 
                    <div class='info-item'><span class='label'>Program Manager Email:</span> <span class='value'>".htmlspecialchars($project['PM_email'] ?? 'N/A')."</span></div> 
                </div> 
            </div> 

            <div class='footer'> 
                <p>Generated on ".date('Y-m-d H:i:s')."</p> 
            </div> 
        </body> 
        </html>"; 

        echo $html; 
        exit; 
    } 
} 

// ------------------------------------------------------ 
// Helpers for schema evolution 
// ------------------------------------------------------ 
if (!function_exists('table_has_column')) { 
    function table_has_column(PDO $pdo, string $table, string $column): bool { 
        $stmt = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE ?"); 
        $stmt->execute([$column]); 
        return (bool)$stmt->fetch(); 
    } 
} 

if (!function_exists('ensure_column')) { 
    function ensure_column(PDO $pdo, string $table, string $column, string $definition): void { 
        if (!table_has_column($pdo, $table, $column)) { 
            $pdo->exec("ALTER TABLE `$table` ADD COLUMN `$column` $definition"); 
        } 
    } 
} 

if (!function_exists('parse_multi_values')) { 
    function parse_multi_values($value): array { 
        if (!$value) { 
            return []; 
        } 
        if (is_array($value)) { 
            return array_values(array_filter(array_map('trim', $value))); 
        } 
        $value = trim((string)$value); 
        $decoded = json_decode($value, true); 
        if (is_array($decoded)) { 
            return array_values(array_filter(array_map('trim', $decoded))); 
        } 
        return array_values(array_filter(array_map('trim', explode(',', $value)))); 
    } 
} 

// ------------------------------------------------------ 
// NEW: Create additional tables for enhanced features
// ------------------------------------------------------ 
$pdo->exec("
    CREATE TABLE IF NOT EXISTS webhooks (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(255) NOT NULL,
        url VARCHAR(500) NOT NULL,
        events TEXT NOT NULL,
        secret_key VARCHAR(255) NULL,
        is_active BOOLEAN DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

$pdo->exec("
    CREATE TABLE IF NOT EXISTS project_collaborators (
        id INT AUTO_INCREMENT PRIMARY KEY,
        project_id INT NOT NULL,
        email VARCHAR(255) NOT NULL,
        role ENUM('viewer', 'editor', 'admin') DEFAULT 'viewer',
        last_access TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY unique_project_email (project_id, email),
        CONSTRAINT fk_collaborator_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

$pdo->exec("
    CREATE TABLE IF NOT EXISTS api_tokens (
        id INT AUTO_INCREMENT PRIMARY KEY,
        token VARCHAR(500) NOT NULL,
        project_id INT NULL,
        access_level ENUM('read', 'write', 'admin') DEFAULT 'read',
        expires_at TIMESTAMP NULL,
        is_active BOOLEAN DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        CONSTRAINT fk_token_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

// ------------------------------------------------------ 
// Ensure projects table exists and has modern fields including project_type
// ------------------------------------------------------ 
$pdo->exec(" 
    CREATE TABLE IF NOT EXISTS projects ( 
        id INT AUTO_INCREMENT PRIMARY KEY, 
        title VARCHAR(255) NOT NULL, 
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 
"); 

// NEW: Add project_type column
ensure_column($pdo, 'projects', 'project_type', "VARCHAR(50) NOT NULL DEFAULT 'Development'");

ensure_column($pdo, 'projects', 'code',                  "VARCHAR(100) NULL"); 
ensure_column($pdo, 'projects', 'donor',                 "VARCHAR(255) NULL"); 
ensure_column($pdo, 'projects', 'donor_ref',             "VARCHAR(255) NULL"); 
ensure_column($pdo, 'projects', 'ip_name',               "VARCHAR(255) NULL"); 
ensure_column($pdo, 'projects', 'status',                "VARCHAR(50) NOT NULL DEFAULT 'active'"); 
ensure_column($pdo, 'projects', 'total_fund_usd',        "DECIMAL(18,2) DEFAULT 0"); 
ensure_column($pdo, 'projects', 'currency_rate',         "DECIMAL(18,6) DEFAULT 0"); 
ensure_column($pdo, 'projects', 'total_fund_etb',        "DECIMAL(18,2) DEFAULT 0"); 
ensure_column($pdo, 'projects', 'owner_name',            "VARCHAR(255) NULL"); 
ensure_column($pdo, 'projects', 'owner_email',           "VARCHAR(255) NULL"); 
ensure_column($pdo, 'projects', 'owner_phone',           "VARCHAR(50)  NULL"); 
ensure_column($pdo, 'projects', 'ed_email',              "VARCHAR(255) NULL"); 
ensure_column($pdo, 'projects', 'PM_email',              "VARCHAR(255) NULL"); 
ensure_column($pdo, 'projects', 'opm_email',             "VARCHAR(255) NULL"); 
ensure_column($pdo, 'projects', 'merl_email',            "VARCHAR(255) NULL"); 
ensure_column($pdo, 'projects', 'finance_head_email',    "VARCHAR(255) NULL"); 
ensure_column($pdo, 'projects', 'finance_officer_email', "VARCHAR(255) NULL"); 
ensure_column($pdo, 'projects', 'project_officer_email', "VARCHAR(255) NULL"); 
ensure_column($pdo, 'projects', 'region_id',             "INT NULL"); 
ensure_column($pdo, 'projects', 'zone_id',               "INT NULL"); 
ensure_column($pdo, 'projects', 'woreda_id',             "INT NULL"); 
ensure_column($pdo, 'projects', 'region_other',          "VARCHAR(255) NULL"); 
ensure_column($pdo, 'projects', 'zone_other',            "VARCHAR(255) NULL"); 
ensure_column($pdo, 'projects', 'woreda_other',          "VARCHAR(255) NULL"); 
ensure_column($pdo, 'projects', 'main_sectors',          "TEXT NULL"); 
ensure_column($pdo, 'projects', 'specific_sectors',      "TEXT NULL"); 
ensure_column($pdo, 'projects', 'start_date',            "DATE NULL"); 
ensure_column($pdo, 'projects', 'end_date',              "DATE NULL"); 

// ------------------------------------------------------ 
// Multi-location table (for one project in many areas) 
// ------------------------------------------------------ 
$pdo->exec("
    CREATE TABLE IF NOT EXISTS project_locations (
        id INT AUTO_INCREMENT PRIMARY KEY,
        project_id INT NOT NULL,
        region_id INT NULL,
        zone_id INT NULL,
        woreda_id INT NULL,
        region_other VARCHAR(255) NULL,
        zone_other VARCHAR(255) NULL,
        woreda_other VARCHAR(255) NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        CONSTRAINT fk_pl_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

if (!function_exists('save_project_locations')) {
    function save_project_locations(PDO $pdo, int $projectId, $primaryRegionRaw, $primaryZoneRaw, $primaryWoredaRaw, string $primaryRegionOther, string $primaryZoneOther, string $primaryWoredaOther, array $extraLocations): void {
        // Clear existing
        $stmtDel = $pdo->prepare("DELETE FROM project_locations WHERE project_id = ?");
        $stmtDel->execute([$projectId]);

        $insert = $pdo->prepare("
            INSERT INTO project_locations (project_id, region_id, zone_id, woreda_id, region_other, zone_other, woreda_other)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");

        $regId = ($primaryRegionRaw && $primaryRegionRaw !== 'other') ? (int)$primaryRegionRaw : null;
        $zId   = ($primaryZoneRaw   && $primaryZoneRaw   !== 'other') ? (int)$primaryZoneRaw   : null;
        $wId   = ($primaryWoredaRaw && $primaryWoredaRaw !== 'other') ? (int)$primaryWoredaRaw : null;

        if ($regId || $zId || $wId || $primaryRegionOther || $primaryZoneOther || $primaryWoredaOther) {
            $insert->execute([
                $projectId,
                $regId,
                $zId,
                $wId,
                $primaryRegionOther ?: null,
                $primaryZoneOther   ?: null,
                $primaryWoredaOther ?: null
            ]);
        }

        foreach ($extraLocations as $loc) {
            $rRaw = $loc['region_id'] ?? '';
            $zRaw = $loc['zone_id'] ?? '';
            $wRaw = $loc['woreda_id'] ?? '';
            $rOther = trim($loc['region_other'] ?? '');
            $zOther = trim($loc['zone_other'] ?? '');
            $wOther = trim($loc['woreda_other'] ?? '');

            $rId = ($rRaw && $rRaw !== 'other') ? (int)$rRaw : null;
            $zId = ($zRaw && $zRaw !== 'other') ? (int)$zRaw : null;
            $wId = ($wRaw && $wRaw !== 'other') ? (int)$wRaw : null;

            if (!$rId && !$zId && !$wId && $rOther === '' && $zOther === '' && $wOther === '') {
                continue;
            }

            $insert->execute([
                $projectId,
                $rId,
                $zId,
                $wId,
                $rOther ?: null,
                $zOther ?: null,
                $wOther ?: null
            ]);
        }
    }
}

// ------------------------------------------------------ 
// Lookups: regions / zones / woredas  (UPGRADED)
// ------------------------------------------------------ 

if (!function_exists('normalize_region_rows_for_select')) {
    function normalize_region_rows_for_select(array $rows): array
    {
        $out = [];

        foreach ($rows as $r) {
            if (!is_array($r)) {
                continue;
            }

            // Common pattern: id / name
            if (isset($r['id']) && isset($r['name'])) {
                $out[] = [
                    'id'   => (int)$r['id'],
                    'name' => (string)$r['name'],
                ];
                continue;
            }

            // Alternative pattern: region_id / region_name
            if (isset($r['region_id']) && isset($r['region_name'])) {
                $out[] = [
                    'id'   => (int)$r['region_id'],
                    'name' => (string)$r['region_name'],
                ];
                continue;
            }

            // Fallback: first two columns
            $vals = array_values($r);
            if (count($vals) >= 2) {
                $out[] = [
                    'id'   => (int)$vals[0],
                    'name' => (string)$vals[1],
                ];
            }
        }

        return $out;
    }
}

// ---------------- Regions ----------------
$regions = [];

// 1) Try helper if available
if (function_exists('get_regions')) {
    try {
        $regions = normalize_region_rows_for_select((array)get_regions());
    } catch (Throwable $e) {
        $regions = [];
    }
}

// 2) Try direct DB lookup (and ensure table exists)
if (!$regions) {
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS regions (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(255) NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        $stmt = $pdo->query("SELECT id, name FROM regions ORDER BY name");
        if ($stmt instanceof PDOStatement) {
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $regions = normalize_region_rows_for_select($rows);
        } else {
            $regions = [];
        }
    } catch (Throwable $e) {
        $regions = [];
    }
}


// ---------------- Zones ----------------
$zones = [];
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS zones (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(255) NOT NULL,
            region_id INT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $stmtZones = $pdo->query("SELECT id, name, region_id FROM zones ORDER BY name");
    if ($stmtZones instanceof PDOStatement) {
        $zones = $stmtZones->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $zones = [];
    }
} catch (Throwable $e) {
    $zones = [];
}

// ---------------- Woredas ----------------
$woredas = [];
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS woredas (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(255) NOT NULL,
            zone_id INT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $stmtW = $pdo->query("SELECT id, name, zone_id FROM woredas ORDER BY name");
    if ($stmtW instanceof PDOStatement) {
        $woredas = $stmtW->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $woredas = [];
    }
} catch (Throwable $e) {
    $woredas = [];
}

// ---------------- Group for JS dependent dropdowns ----------------
$zonesByRegion = [];
foreach ($zones as $z) {
    $rid = isset($z['region_id']) ? (int)$z['region_id'] : 0;
    if ($rid <= 0) {
        continue;
    }
    if (!isset($zonesByRegion[$rid])) {
        $zonesByRegion[$rid] = [];
    }
    $zonesByRegion[$rid][] = [
        'id'   => (int)$z['id'],
        'name' => (string)$z['name'],
    ];
}

$woredasByZone = [];
foreach ($woredas as $w) {
    $zid = isset($w['zone_id']) ? (int)$w['zone_id'] : 0;
    if ($zid <= 0) {
        continue;
    }
    if (!isset($woredasByZone[$zid])) {
        $woredasByZone[$zid] = [];
    }
    $woredasByZone[$zid][] = [
        'id'   => (int)$w['id'],
        'name' => (string)$w['name'],
    ];
}
// Build hierarchical locations array: RegionName => ZoneName => [WoredaName,...]
$locations = [];

// Map region id → name
$regionIdToName = [];
foreach ($regions as $r) {
    $regionIdToName[(int)$r['id']] = $r['name'];
}

// Map zone id → (name, region_id)
$zoneIdToInfo = [];
foreach ($zones as $z) {
    $zoneIdToInfo[(int)$z['id']] = [
        'name'      => $z['name'],
        'region_id' => isset($z['region_id']) ? (int)$z['region_id'] : null,
    ];
}

// Build $locations
foreach ($zoneIdToInfo as $zoneId => $info) {
    $rid = $info['region_id'];
    if (!$rid || !isset($regionIdToName[$rid])) {
        continue;
    }

    $rName = $regionIdToName[$rid];
    $zName = $info['name'];

    if (!isset($locations[$rName])) {
        $locations[$rName] = [];
    }
    if (!isset($locations[$rName][$zName])) {
        $locations[$rName][$zName] = [];
    }

    // Add woredas under this zone
    if (isset($woredasByZone[$zoneId])) {
        foreach ($woredasByZone[$zoneId] as $w) {
            $locations[$rName][$zName][] = $w['name'];
        }
    }
}

// HTML snippets for JS-created rows
$regionOptionsHtml = '';
foreach ($regions as $r) {
    $regionOptionsHtml .= '<option value="' . (int)$r['id'] . '">' . htmlspecialchars($r['name'], ENT_QUOTES) . '</option>';
}
// Also allow "Other" for extra locations
$regionOptionsHtml .= '<option value="other">Other (specify)</option>';

// ------------------------------------------------------ 
// NEW: Project Types Definition
// ------------------------------------------------------ 
$projectTypes = [
    'Development' => 'Long-term development programs and capacity building',
    'Emergency' => 'Immediate response to crises and disasters', 
    'Resilience/Recovery' => 'Building community resilience and recovery programs',
    'Outbreak response' => 'Disease outbreak and health emergency response'
];

// ------------------------------------------------------ 
// Standard lists for sectors (multi-select) 
// ------------------------------------------------------ 
$mainProgrammeSectors = [
    'Health',
    'Nutrition',
    'WASH',
    'Protection',
    'GBV',
    'Child Protection',
    'Education',
    'ES/NFI (Shelter & NFI)',
    'CCCM',
    'Food Security',
    'Livelihoods / Early Recovery',
    'Agriculture',
    'MHPSS',
    'SRH / Family Planning',
    'Cash and Voucher Assistance (CVA)',
    'DRR / Resilience',
    'Social Protection',
    'HIV/AIDS, TB, Malaria',
    'Peacebuilding / Social Cohesion',
    'Multi-sector',
    'Other'
];

$specificProgrammeAreas = [
    'Mobile Health and Nutrition Team (MHNT)',
    'Primary Health Care (PHC)',
    'Cholera / Outbreak Response',
    'SAM Treatment (OTP / SC)',
    'IYCF-E',
    'SRH / Family Planning',
    'Hygiene Promotion',
    'Water Supply & Treatment',
    'Sanitation Infrastructure',
    'Shelter Kits / ES-NFI',
    'Protection Monitoring',
    'GBV Response & Risk Mitigation',
    'Child Protection in Emergencies',
    'Community-based MHPSS',
    'Peacebuilding Dialogues',
    'Livelihoods / IGAs',
    'Cash Transfers',
    'Education in Emergencies',
    'Capacity Building / Training',
    'Systems Strengthening',
    'Other'
];

// ------------------------------------------------------ 
// Handle POST (Create / Update / Delete / Token) 
// ------------------------------------------------------ 
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['ai_assistant'])) {
    $action = $_POST['action'] ?? '';

    // Common fields for create/update
    $code      = trim($_POST['code']       ?? '');
    $title     = trim($_POST['title']      ?? '');
    
    // Handle donor - from dropdown or "Other" text input
    $donor = '';
    $donor_id = $_POST['donor_id'] ?? '';
    if ($donor_id === 'OTHER' || $donor_id === '') {
        $donor = trim($_POST['donor_other'] ?? '');
    } elseif (!empty($donor_id) && is_numeric($donor_id)) {
        // Get donor name from database
        try {
            $donorStmt = $pdo->prepare("SELECT name FROM gms_donors WHERE id = ? LIMIT 1");
            $donorStmt->execute([(int)$donor_id]);
            $donorRow = $donorStmt->fetch();
            $donor = $donorRow ? $donorRow['name'] : '';
        } catch (Exception $e) {
            $donor = '';
        }
    } else {
        // Fallback to old donor field if present
        $donor = trim($_POST['donor'] ?? '');
    }
    
    $donor_ref = trim($_POST['donor_ref']  ?? '');
    $ip_name   = trim($_POST['ip_name']    ?? '');
    $status    = trim($_POST['status']     ?? 'active');
    
    // NEW: Project Type
    $project_type = trim($_POST['project_type'] ?? 'Development');

    // Handle currency
    $currency = trim($_POST['currency'] ?? 'USD');
    $currency_other = trim($_POST['currency_other'] ?? '');
    if ($currency === 'OTHER' && !empty($currency_other)) {
        $currency = $currency_other;
    }
    
    // Get default currency rate if currency is selected and rate not provided
    $currency_rate = (float)($_POST['currency_rate'] ?? 0);
    if ($currency_rate == 0 && !empty($currency) && $currency !== 'USD') {
        try {
            $currStmt = $pdo->prepare("SELECT default_rate FROM system_currencies WHERE code = ? LIMIT 1");
            $currStmt->execute([$currency]);
            $currRow = $currStmt->fetch();
            if ($currRow && $currRow['default_rate'] > 0) {
                $currency_rate = (float)$currRow['default_rate'];
            }
        } catch (Exception $e) {
            // Ignore, use provided rate or 0
        }
    }
    // If still 0 and currency is USD, set default rate (e.g., 55.5 for USD to ETB)
    if ($currency_rate == 0 && $currency === 'USD') {
        $currency_rate = 55.5; // Default USD to ETB rate
    }

    $total_fund_usd = (float)($_POST['total_fund_usd'] ?? 0);
    $total_fund_etb = $currency_rate > 0
        ? $total_fund_usd * $currency_rate
        : (float)($_POST['total_fund_etb'] ?? 0);

    $owner_name            = trim($_POST['owner_name']            ?? '');
    $owner_email           = trim($_POST['owner_email']           ?? '');
    $owner_phone           = trim($_POST['owner_phone']           ?? '');
    $ed_email              = trim($_POST['ed_email']              ?? '');
    $pm_email              = trim($_POST['pm_email']              ?? '');
    $opm_email             = trim($_POST['opm_email']             ?? '');
    $merl_email            = trim($_POST['merl_email']            ?? '');
    $finance_head_email    = trim($_POST['finance_head_email']    ?? '');
    $finance_officer_email = trim($_POST['finance_officer_email'] ?? '');
    $project_officer_email = trim($_POST['project_officer_email'] ?? '');

    // Primary location (with "Other" support)
    $region_id_raw  = $_POST['region_id']  ?? '';
    $zone_id_raw    = $_POST['zone_id']    ?? '';
    $woreda_id_raw  = $_POST['woreda_id']  ?? '';

    $region_other   = trim($_POST['region_other'] ?? '');
    $zone_other     = trim($_POST['zone_other']   ?? '');
    $woreda_other   = trim($_POST['woreda_other'] ?? '');

    $region_id = ($region_id_raw && $region_id_raw !== 'other') ? (int)$region_id_raw : null;
    $zone_id   = ($zone_id_raw   && $zone_id_raw   !== 'other') ? (int)$zone_id_raw   : null;
    $woreda_id = ($woreda_id_raw && $woreda_id_raw !== 'other') ? (int)$woreda_id_raw : null;

    $start_date = (($_POST['start_date'] ?? '') !== '') ? $_POST['start_date'] : null;
    $end_date   = (($_POST['end_date'] ?? '') !== '') ? $_POST['end_date'] : null;

    // Programme sectors from multi-selects or raw text
    $main_sectors_str     = null;
    $specific_sectors_str = null;

    $mainSectors = [];
    if (isset($_POST['main_sectors']) && is_array($_POST['main_sectors'])) {
        foreach ($_POST['main_sectors'] as $s) {
            $s = trim($s);
            if ($s !== '') $mainSectors[] = $s;
        }
    }
    if (!$mainSectors && !empty($_POST['main_sectors_raw'])) {
        foreach (explode(',', $_POST['main_sectors_raw']) as $s) {
            $s = trim($s);
            if ($s !== '') $mainSectors[] = $s;
        }
    }
    if (!empty($_POST['main_sectors_other'])) {
        $mainSectors[] = trim($_POST['main_sectors_other']);
    }
    $mainSectors = array_values(array_unique(array_filter($mainSectors)));
    if ($mainSectors) {
        $main_sectors_str = json_encode($mainSectors);
    }

    $specificSectors = [];
    if (isset($_POST['specific_sectors']) && is_array($_POST['specific_sectors'])) {
        foreach ($_POST['specific_sectors'] as $s) {
            $s = trim($s);
            if ($s !== '') $specificSectors[] = $s;
        }
    }
    if (!$specificSectors && !empty($_POST['specific_sectors_raw'])) {
        foreach (explode(',', $_POST['specific_sectors_raw']) as $s) {
            $s = trim($s);
            if ($s !== '') $specificSectors[] = $s;
        }
    }
    if (!empty($_POST['specific_sectors_other'])) {
        $specificSectors[] = trim($_POST['specific_sectors_other']);
    }
    $specificSectors = array_values(array_unique(array_filter($specificSectors)));
    if ($specificSectors) {
        $specific_sectors_str = json_encode($specificSectors);
    }

    // Extra locations (multi-location)
    $extraLocations = [];
    if (!empty($_POST['locations']) && is_array($_POST['locations'])) {
        $extraLocations = $_POST['locations'];
    }

    if ($action === 'create') {
        if ($title === '') {
            $errorMsg = 'Project title is required.';
            add_realtime_notification('error', 'Project creation failed: Title is required');
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO projects
                    (code, title, project_type, donor, donor_ref, ip_name, status,
                     total_fund_usd, currency_rate, total_fund_etb,
                     owner_name, owner_email, owner_phone,
                     ed_email, PM_email, opm_email, merl_email,
                     finance_head_email, finance_officer_email, project_officer_email,
                     region_id, zone_id, woreda_id,
                     region_other, zone_other, woreda_other,
                     main_sectors, specific_sectors,
                     start_date, end_date)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
            ");
            $stmt->execute([
                $code, $title, $project_type, $donor, $donor_ref, $ip_name, $status,
                $total_fund_usd, $currency_rate, $total_fund_etb,
                $owner_name, $owner_email, $owner_phone,
                $ed_email, $pm_email, $opm_email, $merl_email,
                $finance_head_email, $finance_officer_email, $project_officer_email,
                $region_id, $zone_id, $woreda_id,
                $region_other ?: null, $zone_other ?: null, $woreda_other ?: null,
                $main_sectors_str, $specific_sectors_str,
                $start_date, $end_date
            ]);
            $project_id = (int)$pdo->lastInsertId();
            $message = 'Project created successfully.';
            add_realtime_notification('success', 'Project "' . $title . '" has been created successfully', $project_id);

            // Save locations (primary + extras)
            save_project_locations(
                $pdo,
                $project_id,
                $region_id_raw,
                $zone_id_raw,
                $woreda_id_raw,
                $region_other,
                $zone_other,
                $woreda_other,
                $extraLocations
            );

            // NEW: Trigger webhook for project creation
            trigger_webhook($project_id, 'project.created');

            $ai_suggestion = get_ai_suggestion('project_setup');
            add_realtime_notification($ai_suggestion['priority'], $ai_suggestion['message'], $project_id);
        }

    } elseif ($action === 'update') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) {
            $errorMsg = 'Invalid project ID.';
            add_realtime_notification('error', 'Project update failed: Invalid project ID');
        } elseif ($title === '') {
            $errorMsg = 'Project title is required.';
            add_realtime_notification('error', 'Project update failed: Title is required', $id);
        } else {
            $stmt = $pdo->prepare("
                UPDATE projects
                SET code = ?, title = ?, project_type = ?, donor = ?, donor_ref = ?, ip_name = ?, status = ?,
                    total_fund_usd = ?, currency_rate = ?, total_fund_etb = ?,
                    owner_name = ?, owner_email = ?, owner_phone = ?,
                    ed_email = ?, PM_email = ?, opm_email = ?, merl_email = ?,
                    finance_head_email = ?, finance_officer_email = ?, project_officer_email = ?,
                    region_id = ?, zone_id = ?, woreda_id = ?,
                    region_other = ?, zone_other = ?, woreda_other = ?,
                    main_sectors = ?, specific_sectors = ?,
                    start_date = ?, end_date = ?
                WHERE id = ?
            ");
            $stmt->execute([
                $code, $title, $project_type, $donor, $donor_ref, $ip_name, $status,
                $total_fund_usd, $currency_rate, $total_fund_etb,
                $owner_name, $owner_email, $owner_phone,
                $ed_email, $pm_email, $opm_email, $merl_email,
                $finance_head_email, $finance_officer_email, $project_officer_email,
                $region_id, $zone_id, $woreda_id,
                $region_other ?: null, $zone_other ?: null, $woreda_other ?: null,
                $main_sectors_str, $specific_sectors_str,
                $start_date, $end_date,
                $id
            ]);

            // Update locations
            save_project_locations(
                $pdo,
                $id,
                $region_id_raw,
                $zone_id_raw,
                $woreda_id_raw,
                $region_other,
                $zone_other,
                $woreda_other,
                $extraLocations
            );

            // NEW: Trigger webhook for project update
            trigger_webhook($id, 'project.updated');

            $message = 'Project updated successfully.';
            add_realtime_notification('success', 'Project "' . $title . '" has been updated successfully', $id);
        }

    } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $stmt = $pdo->prepare("SELECT title FROM projects WHERE id = ?");
            $stmt->execute([$id]);
            $project = $stmt->fetch();
            $project_title = $project['title'] ?? 'Unknown Project';

            try {
                $pdo->beginTransaction();

                // HARD DELETE (CASCADE): remove the project AND all linked records across modules
                $delete_summary = projectsx_cascade_delete_project($pdo, $id);

                $pdo->commit();

                // NEW: Trigger webhook for project deletion
                if (function_exists('trigger_webhook')) {
                    trigger_webhook($id, 'project.deleted');
                }

// Sync cross-app state after deletion (prevents stale projects in dropdowns across modules)
try {
    if (session_status() === PHP_SESSION_NONE) { @session_start(); }
    if (isset($_SESSION['selected_project_id']) && (int)$_SESSION['selected_project_id'] === (int)$id) {
        unset($_SESSION['selected_project_id']);
    }
    $crossApp = CrossAppCommunicator::getInstance();
    $crossApp->setData('active_project_id', null, 'global');
    $crossApp->setData('current_project', null, 'projects');
    // Refresh available_projects snapshot
    if (function_exists('get_projects')) { get_projects(); }
} catch (Throwable $eSync) {
    // ignore
}

                // Human-readable summary for the UI
                $pairs = [];
                foreach ($delete_summary as $tbl => $cnt) {
                    $cnt = (int)$cnt;
                    if ($cnt > 0 && $tbl !== 'projects') $pairs[] = $tbl . '=' . $cnt;
                }
                $message = 'Project deleted successfully (including linked data across modules).';
                if (!empty($pairs)) {
                    $message .= ' Deleted: ' . implode(', ', $pairs) . '.';
                }

                if (function_exists('add_realtime_notification')) {
                    add_realtime_notification('warning', 'Project "' . $project_title . '" and related data were deleted', $id);
                }
            } catch (Throwable $ex) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $errorMsg = 'Could not delete project. Details: ' . $ex->getMessage();
                if (function_exists('add_realtime_notification')) {
                    add_realtime_notification('error', 'Delete failed for "' . $project_title . '": ' . $ex->getMessage(), $id);
                }
            }
        }
    } elseif ($action === 'generate_api_token') {
        $project_id   = (int)($_POST['project_id'] ?? 0);
        $access_level = $_POST['access_level'] ?? 'read';
        
        if ($project_id > 0) {
            $token = generate_api_token($project_id, $access_level);
            
            // Store token in database
            $stmt = $pdo->prepare("
                INSERT INTO api_tokens (token, project_id, access_level, expires_at) 
                VALUES (?, ?, ?, FROM_UNIXTIME(?))
            ");
            $stmt->execute([$token, $project_id, $access_level, time() + (30 * 24 * 60 * 60)]);
            
            $message = 'API token generated successfully. Token: ' . $token;
            add_realtime_notification('success', 'API token generated for project', $project_id);
        }
    }
}

// ------------------------------------------------------ 
// Handle AI Assistant Request (AJAX) 
// ------------------------------------------------------ 
if (isset($_POST['ai_assistant'])) {
    projectsx_clean_output_buffers();
    $context    = $_POST['context'] ?? 'project_setup';
    $user_input = $_POST['user_input'] ?? '';
    $ai_suggestion = get_ai_suggestion($context, $user_input);
    header('Content-Type: application/json');
    echo json_encode($ai_suggestion);
    exit;
}

// ------------------------------------------------------ 
// NEW: Handle API Requests
// ------------------------------------------------------ 
if (isset($_GET['api'])) {
    $api_action = $_GET['api'] ?? '';
    
    if ($api_action === 'projects') {
        header('Content-Type: application/json');
        
        // Check for API token
        $token = $_GET['token'] ?? ($_SERVER['HTTP_AUTHORIZATION'] ?? '');
        if ($token) {
            $token = str_replace('Bearer ', '', $token);
            $token_data = validate_api_token($token);
            
            if (!$token_data) {
                http_response_code(401);
                echo json_encode(['error' => 'Invalid or expired token']);
                exit;
            }
        } else {
            // For now, allow public read access. You can enforce tokens here if needed.
        }
        
        $project_id = $_GET['id'] ?? null;
        $format = $_GET['format'] ?? 'json';
        
        if ($project_id) {
            // Single project
            $data = get_shareable_project_data($project_id, $format);
            if ($data) {
                if ($format === 'xml') {
                    header('Content-Type: application/xml');
                }
                echo $data;
            } else {
                http_response_code(404);
                echo json_encode(['error' => 'Project not found']);
            }
        } else {
            // All projects
            $projects = $pdo->query("
                SELECT p.*, r.name AS region_name, z.name AS zone_name, w.name AS woreda_name
                FROM projects p
                LEFT JOIN regions r ON p.region_id = r.id
                LEFT JOIN zones z ON p.zone_id = z.id
                LEFT JOIN woredas w ON p.woreda_id = w.id
                ORDER BY p.created_at DESC
            ")->fetchAll();
            
            $output = [];
            foreach ($projects as $project) {
                $output[] = get_shareable_project_data($project['id'], 'array');
            }
            
            echo json_encode($output, JSON_PRETTY_PRINT);
        }
        exit;
    }
}

// ------------------------------------------------------ 
// Fetch all projects with location labels 
// ------------------------------------------------------ 
$projects = $pdo->query("
    SELECT
        p.*,
        r.name AS region_name,
        z.name AS zone_name,
        w.name AS woreda_name
    FROM projects p
    LEFT JOIN regions r ON p.region_id = r.id
    LEFT JOIN zones   z ON p.zone_id   = z.id
    LEFT JOIN woredas w ON p.woreda_id = w.id
    ORDER BY p.created_at DESC, p.id DESC
")->fetchAll();

// Fetch multi-location list
$projectLocationsMap = [];
try {
    $stmtLoc = $pdo->query("
        SELECT
            pl.*,
            r.name AS region_name,
            z.name AS zone_name,
            w.name AS woreda_name
        FROM project_locations pl
        LEFT JOIN regions r ON pl.region_id = r.id
        LEFT JOIN zones   z ON pl.zone_id   = z.id
        LEFT JOIN woredas w ON pl.woreda_id = w.id
        ORDER BY pl.project_id, pl.id
    ");

    if ($stmtLoc instanceof PDOStatement) {
        $locRows = $stmtLoc->fetchAll(PDO::FETCH_ASSOC);

        foreach ($locRows as $lr) {
            $pid = (int)$lr['project_id'];
            if (!isset($projectLocationsMap[$pid])) {
                $projectLocationsMap[$pid] = [];
            }
            $projectLocationsMap[$pid][] = $lr;
        }
    } else {
        $projectLocationsMap = [];
    }
} catch (Throwable $e) {
    $projectLocationsMap = [];
}

// ------------------------------------------------------ 
// Get Analytics Data 
// ------------------------------------------------------ 
$analytics   = get_projects_analytics($pdo);
$statistics  = get_project_statistics($pdo);

// ------------------------------------------------------ 
// Export Handling 
// ------------------------------------------------------ 
function build_project_export_rows(array $projects): array {
    $rows   = [];
    $rows[] = [
        'ID','Code','Title','Project Type','Status',
        'Donor','Donor ref','Implementing partner',
        'Region','Zone','Woreda',
        'Start date','End date','Project Period',
        'Total fund (USD)','Currency rate','Total fund (ETB)',
        'Owner name','Owner email','Owner phone',
        'ED email','Program Manager email','OPM email','MERL email',
        'Finance head email','Finance officer email','Project officer email',
        'Main sectors','Specific sectors',
        'Created at'
    ];

    foreach ($projects as $p) {
        $project_period  = calculate_project_period($p['start_date'], $p['end_date']);
        $mainSectors     = parse_multi_values($p['main_sectors'] ?? '');
        $specificSectors = parse_multi_values($p['specific_sectors'] ?? '');

        $rows[] = [
            (int)$p['id'],
            $p['code'] ?? '',
            $p['title'] ?? '',
            $p['project_type'] ?? 'Development',
            $p['status'] ?? '',
            $p['donor'] ?? '',
            $p['donor_ref'] ?? '',
            $p['ip_name'] ?? '',
            $p['region_name'] ?? '',
            $p['zone_name'] ?? '',
            $p['woreda_name'] ?? '',
            $p['start_date'] ?? '',
            $p['end_date'] ?? '',
            $project_period,
            (float)($p['total_fund_usd'] ?? 0),
            (float)($p['currency_rate'] ?? 0),
            (float)($p['total_fund_etb'] ?? 0),
            $p['owner_name'] ?? '',
            $p['owner_email'] ?? '',
            $p['owner_phone'] ?? '',
            $p['ed_email'] ?? '',
            $p['PM_email'] ?? '',
            $p['opm_email'] ?? '',
            $p['merl_email'] ?? '',
            $p['finance_head_email'] ?? '',
            $p['finance_officer_email'] ?? '',
            $p['project_officer_email'] ?? '',
            implode(', ', $mainSectors),
            implode(', ', $specificSectors),
            $p['created_at'] ?? ''
        ];
    }
    return $rows;
}

$actionGet = $_GET['action']    ?? '';
// Protect file downloads from stray warnings/notices
if ($actionGet && str_starts_with($actionGet, 'export_')) { projectsx_clean_output_buffers(); }
$projectId = (int)($_GET['project_id'] ?? 0);

// Single project exports
if ($actionGet === 'export_single_project_pdf' && $projectId) {
    $stmt = $pdo->prepare("
        SELECT p.*, r.name AS region_name, z.name AS zone_name, w.name AS woreda_name
        FROM projects p
        LEFT JOIN regions r ON p.region_id = r.id
        LEFT JOIN zones   z ON p.zone_id   = z.id
        LEFT JOIN woredas w ON p.woreda_id = w.id
        WHERE p.id = ?
    ");
    $stmt->execute([$projectId]);
    $project = $stmt->fetch();
    if ($project) {
        export_single_project_html($project, 'project_' . $projectId . '_details.html');
    }
    exit;
}

if ($actionGet === 'export_single_project_word' && $projectId) {
    $stmt = $pdo->prepare("
        SELECT p.*, r.name AS region_name, z.name AS zone_name, w.name AS woreda_name
        FROM projects p
        LEFT JOIN regions r ON p.region_id = r.id
        LEFT JOIN zones   z ON p.zone_id   = z.id
        LEFT JOIN woredas w ON p.woreda_id = w.id
        WHERE p.id = ?
    ");
    $stmt->execute([$projectId]);
    $project = $stmt->fetch();
    if ($project) {
        $project_period  = calculate_project_period($project['start_date'], $project['end_date']);
        $mainSectors     = parse_multi_values($project['main_sectors'] ?? '');
        $specificSectors = parse_multi_values($project['specific_sectors'] ?? '');

        header("Content-Type: application/msword; charset=UTF-8");
        header('Content-Disposition: attachment; filename="project_' . $projectId . '_details.doc"');

        echo "<html><head><meta charset=\"UTF-8\"><title>Project Details - " . htmlspecialchars($project['title'] ?? '') . "</title></head><body>";
        echo "<h1>Project Details: " . htmlspecialchars($project['title'] ?? '') . "</h1>";
        echo "<table border=\"1\" cellspacing=\"0\" cellpadding=\"4\">";
        echo "<tr><th>Field</th><th>Value</th></tr>";
        echo "<tr><td>Project Code</td><td>" . htmlspecialchars($project['code'] ?? '') . "</td></tr>";
        echo "<tr><td>Title</td><td>" . htmlspecialchars($project['title'] ?? '') . "</td></tr>";
        echo "<tr><td>Project Type</td><td>" . htmlspecialchars($project['project_type'] ?? 'Development') . "</td></tr>";
        echo "<tr><td>Status</td><td>" . htmlspecialchars($project['status'] ?? '') . "</td></tr>";
        echo "<tr><td>Donor</td><td>" . htmlspecialchars($project['donor'] ?? '') . "</td></tr>";
        echo "<tr><td>Donor Reference</td><td>" . htmlspecialchars($project['donor_ref'] ?? '') . "</td></tr>";
        echo "<tr><td>Implementing Partner</td><td>" . htmlspecialchars($project['ip_name'] ?? '') . "</td></tr>";
        echo "<tr><td>Project Period</td><td>" . htmlspecialchars($project_period) . "</td></tr>";
        echo "<tr><td>Main Programme Sectors</td><td>" . htmlspecialchars(implode(', ', $mainSectors)) . "</td></tr>";
        echo "<tr><td>Project Specific Areas</td><td>" . htmlspecialchars(implode(', ', $specificSectors)) . "</td></tr>";
        echo "<tr><td>Region</td><td>" . htmlspecialchars($project['region_name'] ?? ($project['region_other'] ?? '')) . "</td></tr>";
        echo "<tr><td>Zone</td><td>" . htmlspecialchars($project['zone_name'] ?? ($project['zone_other'] ?? '')) . "</td></tr>";
        echo "<tr><td>Woreda</td><td>" . htmlspecialchars($project['woreda_name'] ?? ($project['woreda_other'] ?? '')) . "</td></tr>";
        echo "<tr><td>Start Date</td><td>" . htmlspecialchars($project['start_date'] ?? '') . "</td></tr>";
        echo "<tr><td>End Date</td><td>" . htmlspecialchars($project['end_date'] ?? '') . "</td></tr>";
        echo "<tr><td>Total Fund (USD)</td><td>$" . number_format((float)($project['total_fund_usd'] ?? 0), 2) . "</td></tr>";
        echo "<tr><td>Currency Rate</td><td>" . (float)($project['currency_rate'] ?? 0) . "</td></tr>";
        echo "<tr><td>Total Fund (ETB)</td><td>ETB " . number_format((float)($project['total_fund_etb'] ?? 0), 2) . "</td></tr>";
        echo "</table>";
        echo "</body></html>";
    }
    exit;
}

if ($actionGet === 'export_single_project_excel' && $projectId) {
    $stmt = $pdo->prepare("
        SELECT p.*, r.name AS region_name, z.name AS zone_name, w.name AS woreda_name
        FROM projects p
        LEFT JOIN regions r ON p.region_id = r.id
        LEFT JOIN zones   z ON p.zone_id   = z.id
        LEFT JOIN woredas w ON p.woreda_id = w.id
        WHERE p.id = ?
    ");
    $stmt->execute([$projectId]);
    $project = $stmt->fetch();
    if ($project) {
        $project_period  = calculate_project_period($project['start_date'], $project['end_date']);
        $mainSectors     = parse_multi_values($project['main_sectors'] ?? '');
        $specificSectors = parse_multi_values($project['specific_sectors'] ?? '');

        header("Content-Type: application/vnd.ms-excel; charset=UTF-8");
        header('Content-Disposition: attachment; filename="project_' . $projectId . '_details.xls"');
        echo "\xEF\xBB\xBF";

        echo "Field\tValue\n";
        echo "Project Code\t" . ($project['code'] ?? '') . "\n";
        echo "Title\t" . ($project['title'] ?? '') . "\n";
        echo "Project Type\t" . ($project['project_type'] ?? 'Development') . "\n";
        echo "Status\t" . ($project['status'] ?? '') . "\n";
        echo "Donor\t" . ($project['donor'] ?? '') . "\n";
        echo "Donor Reference\t" . ($project['donor_ref'] ?? '') . "\n";
        echo "Implementing Partner\t" . ($project['ip_name'] ?? '') . "\n";
        echo "Project Period\t" . $project_period . "\n";
        echo "Main Programme Sectors\t" . implode(', ', $mainSectors) . "\n";
        echo "Project Specific Areas\t" . implode(', ', $specificSectors) . "\n";
        echo "Region\t" . ($project['region_name'] ?? ($project['region_other'] ?? '')) . "\n";
        echo "Zone\t" . ($project['zone_name'] ?? ($project['zone_other'] ?? '')) . "\n";
        echo "Woreda\t" . ($project['woreda_name'] ?? ($project['woreda_other'] ?? '')) . "\n";
        echo "Start Date\t" . ($project['start_date'] ?? '') . "\n";
        echo "End Date\t" . ($project['end_date'] ?? '') . "\n";
        echo "Total Fund (USD)\t" . (float)($project['total_fund_usd'] ?? 0) . "\n";
        echo "Currency Rate\t" . (float)($project['currency_rate'] ?? 0) . "\n";
        echo "Total Fund (ETB)\t" . (float)($project['total_fund_etb'] ?? 0) . "\n";
        echo "Owner Name\t" . ($project['owner_name'] ?? '') . "\n";
        echo "Owner Email\t" . ($project['owner_email'] ?? '') . "\n";
        echo "Owner Phone\t" . ($project['owner_phone'] ?? '') . "\n";
        echo "Program Manager Email\t" . ($project['PM_email'] ?? '') . "\n";
    }
    exit;
}

if ($actionGet === 'export_single_project_html' && $projectId) {
    $stmt = $pdo->prepare("
        SELECT p.*, r.name AS region_name, z.name AS zone_name, w.name AS woreda_name
        FROM projects p
        LEFT JOIN regions r ON p.region_id = r.id
        LEFT JOIN zones   z ON p.zone_id   = z.id
        LEFT JOIN woredas w ON p.woreda_id = w.id
        WHERE p.id = ?
    ");
    $stmt->execute([$projectId]);
    $project = $stmt->fetch();
    if ($project) {
        export_single_project_html($project, 'project_' . $projectId . '_details.html');
    }
    exit;
}

// Bulk exports
if (in_array($actionGet, ['export_projects_csv','export_projects_word','export_projects_excel','export_projects_pdf'], true)) {
    $rows = build_project_export_rows($projects);

    if ($actionGet === 'export_projects_csv') {
        if (function_exists('array_to_csv_download')) {
            array_to_csv_download($rows, 'projects_list.csv');
        } else {
            header('Content-Type: text/csv; charset=UTF-8');
            header('Content-Disposition: attachment; filename="projects_list.csv"');
            $output = fopen('php://output', 'w');
            fputs($output, "\xEF\xBB\xBF");
            foreach ($rows as $row) {
                fputcsv($output, $row);
            }
            fclose($output);
        }
        exit;
    } elseif ($actionGet === 'export_projects_word') {
        header("Content-Type: application/msword; charset=UTF-8");
        header('Content-Disposition: attachment; filename="projects_list.doc"');
        echo "<html><head><meta charset=\"UTF-8\"><title>Projects List</title></head><body>";
        echo "<h1>Projects List</h1>";
        echo "<table border=\"1\" cellspacing=\"0\" cellpadding=\"4\">";
        foreach ($rows as $i => $row) {
            echo "<tr>";
            foreach ($row as $cell) {
                $tag = ($i === 0) ? 'th' : 'td';
                echo "<{$tag}>" . htmlspecialchars((string)$cell) . "</{$tag}>";
            }
            echo "</tr>";
        }
        echo "</table>";
        echo "</body></html>";
        exit;
    } elseif ($actionGet === 'export_projects_excel') {
        export_to_excel($projects, 'projects_list.xls');
        exit;
    } elseif ($actionGet === 'export_projects_pdf') {
        export_to_pdf($projects, 'projects_list.html');
        exit;
    }
}

// ------------------------------------------------------ 
// NEW: Handle API token generation request via GET
// ------------------------------------------------------ 
if ($actionGet === 'generate_api_token' && $projectId) {
    $access_level = $_GET['access_level'] ?? 'read';
    $token = generate_api_token($projectId, $access_level);
    
    // Store token in database
    $stmt = $pdo->prepare("
        INSERT INTO api_tokens (token, project_id, access_level, expires_at) 
        VALUES (?, ?, ?, FROM_UNIXTIME(?))
    ");
    $stmt->execute([$token, $projectId, $access_level, time() + (30 * 24 * 60 * 60)]);
    
    $message = 'API token generated successfully. Use this token for API access.';
    add_realtime_notification('success', 'API token generated for project', $projectId);
}

// ------------------------------------------------------ 
// Render page 
// ------------------------------------------------------ 
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>📋 Project Registration System</title>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        .ai-assistant {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 15px;
            border-radius: 10px;
            margin-bottom: 20px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
        }
        .notification-container {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 1000;
            max-width: 400px;
        }
        .notification {
            padding: 15px;
            margin-bottom: 10px;
            border-radius: 5px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            animation: slideIn 0.3s ease-out;
        }
        .notification.success { background: #d4edda; color: #155724; border-left: 4px solid #28a745; }
        .notification.error   { background: #f8d7da; color: #721c24; border-left: 4px solid #dc3545; }
        .notification.warning { background: #fff3cd; color: #856404; border-left: 4px solid #ffc107; }
        .notification.info    { background: #d1ecf1; color: #0c5460; border-left: 4px solid #17a2b8; }
        @keyframes slideIn {
            from { transform: translateX(100%); opacity: 0; }
            to   { transform: translateX(0);   opacity: 1; }
        }
        .share-buttons {
            display: flex;
            gap: 5px;
            margin-top: 10px;
            flex-wrap: wrap;
        }
        .share-btn {
            padding: 5px 10px;
            border: none;
            border-radius: 3px;
            cursor: pointer;
            font-size: 12px;
            text-decoration: none;
            display: inline-block;
        }
        .share-whatsapp { background: #25D366; color: white; }
        .share-telegram { background: #0088cc; color: white; }
        .share-email    { background: #ea4335; color: white; }
        .share-api      { background: #6f42c1; color: white; }

        .print-header { display: none; }
        @media print {
            .no-print { display: none !important; }
            .print-header {
                display: block;
                text-align: center;
                margin-bottom: 20px;
                border-bottom: 2px solid #000;
                padding-bottom: 10px;
            }
            .table { font-size: 10px; }
            .card { border: none; box-shadow: none; }
        }

        .card {
            background: white;
            border-radius: 10px;
            box-shadow: 0 2px 20px rgba(0,0,0,0.1);
            margin-bottom: 20px;
            overflow: hidden;
        }
        .card h1, .card h2 {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 15px 20px;
            margin: 0;
        }
        .card-content { padding: 20px; }

        .form-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 15px;
            margin-bottom: 15px;
        }
        .form-row label {
            display: flex;
            flex-direction: column;
            font-weight: 500;
        }
        .form-row input,
        .form-row select,
        .form-row textarea {
            padding: 8px 12px;
            border: 1px solid #ddd;
            border-radius: 5px;
            margin-top: 5px;
        }

        .table {
            width: 100%;
            border-collapse: collapse;
        }
        .table th {
            background: #f8f9fa;
            padding: 12px;
            text-align: left;
            font-weight: 600;
            border-bottom: 2px solid #dee2e6;
        }
        .table td {
            padding: 12px;
            border-bottom: 1px solid #dee2e6;
            vertical-align: top;
        }
        .table tr:hover { background: #f8f9fa; }

        .btn-sm {
            padding: 8px 16px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 14px;
            transition: all 0.3s ease;
            text-decoration: none;
            display: inline-block;
            text-align: center;
        }
        .btn-primary { background: #007bff; color: white; }
        .btn-secondary { background: #6c757d; color: white; }
        .btn-success { background: #28a745; color: white; }
        .btn-danger  { background: #dc3545; color: white; }
        .btn-info    { background: #17a2b8; color: white; }
        .btn-warning { background: #ffc107; color: #212529; }
        .btn-sm:hover {
            transform: translateY(-1px);
            box-shadow: 0 2px 10px rgba(0,0,0,0.2);
        }

        .badge {
            padding: 8px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }
        .badge-success { background: #d4edda; color: #155724; }
        .badge-danger  { background: #f8d7da; color: #721c24; }

        .ai-chat {
            background: white;
            border-radius: 10px;
            padding: 15px;
            margin-top: 15px;
            border: 1px solid #e0e0e0;
        }
        .ai-response {
            background: #f8f9fa;
            padding: 10px;
            border-radius: 5px;
            margin-top: 10px;
            border-left: 4px solid #007bff;
        }

        .project-details-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
            margin-bottom: 20px;
        }
        .detail-section {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 8px;
            border-left: 4px solid #007bff;
        }
        .detail-section h4 {
            margin-top: 0;
            color: #2c3e50;
            border-bottom: 1px solid #dee2e6;
            padding-bottom: 8px;
        }
        .detail-item {
            margin-bottom: 10px;
            display: flex;
            justify-content: space-between;
        }
        .detail-label { font-weight: 600; color: #555; }
        .detail-value { color: #333; text-align: right; }

        .action-buttons {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            margin-top: 15px;
        }

        .status-badge {
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
        }
        .status-active  { background: #d4edda; color: #155724; }
        .status-planned { background: #fff3cd; color: #856404; }
        .status-closed  { background: #f8d7da; color: #721c24; }

        .period-badge {
            background: #e3f2fd;
            color: #1976d2;
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
            display: inline-block;
        }
        
        .type-badge {
            background: #e8f5e8;
            color: #2e7d32;
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
            display: inline-block;
        }

        .date-period-info {
            background: #e8f5e8;
            border: 1px solid #4caf50;
            border-radius: 5px;
            padding: 10px;
            margin: 10px 0;
            font-size: 14px;
        }
        .period-display {
            font-weight: bold;
            color: #2e7d32;
            font-size: 16px;
        }

        .location-row { width: 100%; }

        .dropdown-content a { text-decoration: none; }

        /* Analytics Styles */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 30px;
        }
        .stat-card {
            padding: 15px;
            border-radius: 8px;
            text-align: center;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .stat-card h3 {
            margin: 0;
            font-size: 14px;
        }
        .stat-card p {
            font-size: 24px;
            font-weight: bold;
            margin: 10px 0 0 0;
        }

        .chart-container {
            background: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            margin-bottom: 20px;
        }
        .chart-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(400px, 1fr));
            gap: 20px;
        }

        /* NEW: API Integration Styles */
        .api-section {
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 8px;
            padding: 15px;
            margin: 15px 0;
        }
        .api-token {
            background: #e9ecef;
            padding: 10px;
            border-radius: 4px;
            font-family: monospace;
            word-break: break-all;
            margin: 10px 0;
        }
        .integration-buttons {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            margin: 10px 0;
        }

        @media (max-width: 768px) {
            .form-row { grid-template-columns: 1fr; }
            .project-details-grid { grid-template-columns: 1fr; }
            .action-buttons { flex-direction: column; }
            .btn-sm { width: 100%; margin-bottom: 5px; }
            .chart-grid { grid-template-columns: 1fr; }
            .stats-grid { grid-template-columns: repeat(2, 1fr); }
            .integration-buttons { flex-direction: column; }
        }
    </style>
</head>
<body>
    <div class="print-header">
        <h1>📋 SMART Nexus Project Registration System</h1>
        <p>Generated on: <?php echo date('Y-m-d H:i:s'); ?></p>
        <hr>
    </div>

    <div class="notification-container">
        <?php foreach ($_SESSION['realtime_notifications'] as $notification): ?>
            <div class="notification <?php echo $notification['type']; ?>">
                <strong><?php echo ucfirst($notification['type']); ?>:</strong>
                <?php echo h($notification['message']); ?>
                <br><small><?php echo $notification['timestamp']; ?></small>
            </div>
        <?php endforeach; ?>
        <?php $_SESSION['realtime_notifications'] = []; ?>
    </div>

    <div class="card">
        <h1>📋 Project Registration System</h1>
        <div class="card-content">
            <?php if ($message): ?>
                <p class="badge badge-success"><?php echo h($message); ?></p>
            <?php endif; ?>

            <?php if ($errorMsg): ?>
                <p class="badge badge-danger"><?php echo h($errorMsg); ?></p>
            <?php endif; ?>

            <div class="ai-assistant">
                <h3>🤖 AI Project Assistant</h3>
                <p>Get intelligent suggestions for your project management needs.</p>
                <div class="ai-chat">
                    <input type="text" id="ai-question" placeholder="Ask me about project setup, budgeting, or team coordination..." style="width: 100%; padding: 8px; margin-bottom: 10px;">
                    <button type="button" onclick="askAI()" class="btn-sm btn-primary">Get AI Suggestion</button>
                    <div id="ai-response" class="ai-response" style="display: none;"></div>
                </div>
            </div>

            <p>
                This page manages the core <strong>project registry</strong> for your SMART Nexus Project Monitoring and Reporting system,
                aligned with typical humanitarian and development project metadata (code, donor, locations, budget, key contacts, sectors).
            </p>

            <div class="form-row no-print" style="justify-content:flex-end; gap:0.5rem;">
                <button type="button" class="btn-sm btn-secondary" onclick="window.print();">
                    📄 Print / PDF
                </button>
                <a href="?action=export_projects_csv" class="btn-sm btn-secondary">
                    📊 Download (CSV)
                </a>
                <a href="?action=export_projects_word" class="btn-sm btn-secondary">
                    📝 Download (Word)
                </a>
                <a href="?action=export_projects_excel" class="btn-sm btn-secondary">
                    📈 Download (Excel)
                </a>
                <a href="?action=export_projects_pdf" class="btn-sm btn-secondary">
                    📋 Download (HTML/PDF style)
                </a>
            </div>
        </div>
    </div>

    <!-- CREATE PROJECT -->
    <div class="card">
        <h2>➕ Create New Project</h2>
        <div class="card-content">
            <form method="post" id="projectForm">
                <input type="hidden" name="action" value="create">

                <div class="form-row">
                    <label>Project code
                        <input type="text" name="code" placeholder="e.g. CBPF-ETH-25-R-NGO-12345">
                    </label>
                    <label>Title *
                        <input type="text" name="title" required>
                    </label>
                    <label>Project Type *
                        <select name="project_type" required>
                            <?php foreach ($projectTypes as $type => $description): ?>
                                <option value="<?php echo h($type); ?>" title="<?php echo h($description); ?>">
                                    <?php echo h($type); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <small style="color: #666; font-size: 12px; margin-top: 4px;">
                            Development: Long-term programs | Emergency: Crisis response | Resilience/Recovery: Community capacity building | Outbreak response: Disease control
                        </small>
                    </label>
                    <label>Status
                        <select name="status">
                            <option value="planned">Planned</option>
                            <option value="active" selected>Active</option>
                            <option value="closed">Closed</option>
                        </select>
                    </label>
                </div>

                <div class="form-row">
                    <label>Donor
                                        <select name="donor_id" id="donor_select" onchange="handleDonorSelection(); updateCurrencyFromDonor();">
                            <?php echo donor_dropdown_options('', true, false); ?>
                        </select>
                        <div id="donor_other_section" style="display: none; margin-top: 5px;">
                            <input type="text" name="donor_other" id="donor_other_input" placeholder="Enter donor name" style="width: 100%;">
                        </div>
                    </label>
                    <label>Donor reference / Contract code
                        <input type="text" name="donor_ref" placeholder="e.g. CBPF-ETH-24-S-NGO-27645">
                    </label>
                    <label>Implementing partner
                        <input type="text" name="ip_name" placeholder="Nexus Ethiopia, FIDO, etc.">
                    </label>
                </div>

                <!-- Main & Specific sectors -->
                <div class="form-row">
                    <label>Main Programme Sectors (multi-select)
                        <select name="main_sectors[]" multiple size="6">
                            <?php foreach ($mainProgrammeSectors as $sector): ?>
                                <option value="<?php echo h($sector); ?>"><?php echo h($sector); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <small>Hold Ctrl (or Cmd) to select multiple.</small>
                        <input type="text" name="main_sectors_other" placeholder="If 'Other', specify here" style="margin-top:4px;">
                    </label>
                    <label>Project Specific Areas (multi-select)
                        <select name="specific_sectors[]" multiple size="6">
                            <?php foreach ($specificProgrammeAreas as $sector): ?>
                                <option value="<?php echo h($sector); ?>"><?php echo h($sector); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <small>Hold Ctrl (or Cmd) to select multiple.</small>
                        <input type="text" name="specific_sectors_other" placeholder="If 'Other', specify here" style="margin-top:4px;">
                    </label>
                </div>

                <div class="form-row">
                    <label>Currency
                        <select name="currency" id="currency_select" onchange="handleCurrencySelection(); updateCurrencyRate()">
                            <?php echo currency_dropdown_options('USD', true, true); ?>
                        </select>
                        <div id="currency_other_section" style="display: none; margin-top: 5px;">
                            <input type="text" name="currency_other" id="currency_other_input" placeholder="Enter currency code (e.g., XYZ)" style="width: 100%;">
                        </div>
                    </label>
                    <label>Total fund (in selected currency)
                        <input type="number" step="0.01" name="total_fund_usd" id="total_fund_usd" onchange="calculateETB()" placeholder="Amount in selected currency">
                    </label>
                    <label>Currency rate (to ETB)
                        <input type="number" step="0.0001" name="currency_rate" id="currency_rate" onchange="calculateETB()" placeholder="Exchange rate to ETB">
                    </label>
                    <label>Total fund (ETB) – auto if rate given
                        <input type="number" step="0.01" name="total_fund_etb" id="total_fund_etb" readonly style="background: #f0f0f0;">
                    </label>
                </div>

                <div class="form-row">
                    <label>Owner name
                        <input type="text" name="owner_name">
                    </label>
                    <label>Owner email
                        <input type="email" name="owner_email">
                    </label>
                    <label>Owner phone
                        <input type="text" name="owner_phone">
                    </label>
                </div>

                <div class="form-row">
                    <label>Executive Director email
                        <input type="email" name="ed_email">
                    </label>
                    <label>Program Manager email *
                        <input type="email" name="pm_email" required>
                    </label>
                    <label>Operations Manager email
                        <input type="email" name="opm_email">
                    </label>
                </div>

                <div class="form-row">
                    <label>MERL / MEAL Manager email
                        <input type="email" name="merl_email">
                    </label>
                    <label>Finance Head email
                        <input type="email" name="finance_head_email">
                    </label>
                    <label>Finance Officer email
                        <input type="email" name="finance_officer_email">
                    </label>
                </div>

                <div class="form-row">
                    <label>Project Officer / Coordinator email
                        <input type="email" name="project_officer_email">
                    </label>
                </div>

                 <!-- Primary location row -->
          <div class="form-row location-row primary-location-row" data-location-row="primary">
           <label>Region
        <select
            name="region_id"
            id="primary_region"
            class="region_input region-select"
            data-location-row="primary"
        >
            <option value="">--</option>
            <?php if (!empty($regions)): ?>
                <?php foreach ($regions as $r): ?>
                    <option value="<?php echo (int)$r['id']; ?>">
                        <?php echo h($r['name']); ?>
                    </option>
                <?php endforeach; ?>
            <?php endif; ?>
            <option value="other">Other (specify)</option>
        </select>
        <input type="text"
               name="region_other"
               class="other-input region-other-input"
               style="display:none;margin-top:4px;"
               placeholder="Specify region">
    </label>

    <label>Zone
        <select
            name="zone_id"
            id="primary_zone"
            class="zone_input zone-select"
            data-location-row="primary"
        >
            <option value="">--</option>
            <option value="other">Other (specify)</option>
        </select>
        <input type="text"
               name="zone_other"
               class="other-input zone-other-input"
               style="display:none;margin-top:4px;"
               placeholder="Specify zone">
    </label>

    <label>Woreda
        <select
            name="woreda_id"
            id="primary_woreda"
            class="woreda_input woreda-select"
            data-location-row="primary"
        >
            <option value="">--</option>
            <option value="other">Other (specify)</option>
        </select>
        <input type="text"
               name="woreda_other"
               class="other-input woreda-other-input"
               style="display:none;margin-top:4px;"
               placeholder="Specify woreda">
    </label>
</div>
               
                <!-- Additional locations for multi-region/zone/woreda -->
                <div class="card" style="margin-bottom:15px;">
                    <div class="card-content">
                        <h3 style="margin-top:0;">📍 Additional Locations (optional)</h3>
                        <p style="font-size:13px; color:#4b5563;">
                            If this project is implemented in multiple Regions/Zones/Woredas, add extra rows here.
                            The first row above is the primary location; these are additional.
                        </p>
                        <div id="additional-locations"></div>
                        <button type="button" class="btn-sm btn-secondary" onclick="addLocationRow();">
                            ➕ Add another location
                        </button>
                    </div>
                </div>

                <div class="form-row">
                    <label>Start date *
                        <input type="date" name="start_date" id="start_date" onchange="calculateProjectPeriod()" required>
                    </label>
                    <label>End date *
                        <input type="date" name="end_date" id="end_date" onchange="calculateProjectPeriod()" required>
                    </label>
                </div>

                <div id="periodCalculation" class="date-period-info" style="display:none;">
                    <strong>📅 Project Period Calculation:</strong>
                    <div class="period-display" id="periodResult"></div>
                    <small>The project period is automatically calculated based on start and end dates.</small>
                </div>

                <div class="form-row">
                    <label>&nbsp;
                        <button type="submit" class="btn-sm btn-success">🚀 Create project</button>
                    </label>
                </div>
            </form>
        </div>
    </div>

    <!-- Portfolio Summary & Analytics -->
    <div class="card">
        <h2>📊 Portfolio Summary & Analytics</h2>
        <div class="card-content">
            <div class="stats-grid">
                <div class="stat-card" style="background:#e3f2fd;">
                    <h3>Total projects</h3>
                    <p><?php echo (int)($statistics['total_projects'] ?? 0); ?></p>
                </div>
                <div class="stat-card" style="background:#e8f5e9;">
                    <h3>Active projects</h3>
                    <p><?php echo (int)($statistics['active_projects'] ?? 0); ?></p>
                </div>
                <div class="stat-card" style="background:#fff3cd;">
                    <h3>Planned projects</h3>
                    <p><?php echo (int)($statistics['planned_projects'] ?? 0); ?></p>
                </div>
                <div class="stat-card" style="background:#f8d7da;">
                    <h3>Closed projects</h3>
                    <p><?php echo (int)($statistics['closed_projects'] ?? 0); ?></p>
                </div>
                <div class="stat-card" style="background:#e2e3e5;">
                    <h3>Total funding (USD)</h3>
                    <p><?php echo number_format((float)($statistics['total_funding_usd'] ?? 0), 0); ?></p>
                </div>
            </div>

            <div class="chart-grid">
                <div class="chart-container">
                    <h3>Projects by status</h3>
                    <canvas id="chartStatus"></canvas>
                </div>
                <div class="chart-container">
                    <h3>Funding by project type (USD)</h3>
                    <canvas id="chartProjectType"></canvas>
                </div>
            </div>
        </div>
    </div>

    <!-- PROJECT DETAILS DISPLAY -->
    <?php if ($projects): ?>
    <div class="card">
        <h2>📊 Project Details Display (<?php echo count($projects); ?> Projects)</h2>
        <div class="card-content">
            <p>Below are all registered projects with complete details and management options.</p>

            <?php foreach ($projects as $project):
                $project_period  = calculate_project_period($project['start_date'], $project['end_date']);
                $mainSectors     = parse_multi_values($project['main_sectors'] ?? '');
                $specificSectors = parse_multi_values($project['specific_sectors'] ?? '');
                $locationsList   = $projectLocationsMap[$project['id']] ?? [];
            ?>
            <div class="card" style="margin-bottom: 25px; border: 1px solid #e0e0e0;">
                <div class="card-content">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px; flex-wrap:wrap; gap:10px;">
                        <h3 style="margin: 0; color: #2c3e50;">
                            <?php echo h($project['title']); ?>
                            <span class="status-badge status-<?php echo h($project['status']); ?>">
                                <?php echo ucfirst($project['status']); ?>
                            </span>
                            <span class="type-badge"><?php echo h($project['project_type'] ?? 'Development'); ?></span>
                            <span class="period-badge">⏱️ <?php echo $project_period; ?></span>
                        </h3>
                        <div class="action-buttons">
                            <button type="button" class="btn-sm btn-primary" onclick="editProject(<?php echo (int)$project['id']; ?>)">
                                ✏️ Edit
                            </button>

                            <form method="post" style="display: inline;">
                                <input type="hidden" name="id" value="<?php echo (int)$project['id']; ?>">
                                <button type="submit" name="action" value="delete" class="btn-sm btn-danger"
                                        onclick="return confirm('Are you sure you want to delete project: <?php echo addslashes($project['title']); ?>?')">
                                    🗑️ Delete
                                </button>
                            </form>

                            <div class="dropdown" style="display: inline; position:relative;">
                                <button type="button" class="btn-sm btn-info dropdown-toggle">📥 Download</button>
                                <div class="dropdown-content" style="display: none; position: absolute; background: white; box-shadow: 0 2px 10px rgba(0,0,0,0.1); border-radius: 5px; padding: 5px; z-index: 100;">
                                    <a href="?action=export_single_project_pdf&project_id=<?php echo (int)$project['id']; ?>" class="btn-sm btn-secondary" style="display:block;margin:2px 0;">Printable HTML</a>
                                    <a href="?action=export_single_project_word&project_id=<?php echo (int)$project['id']; ?>" class="btn-sm btn-secondary" style="display:block;margin:2px 0;">Word</a>
                                    <a href="?action=export_single_project_excel&project_id=<?php echo (int)$project['id']; ?>" class="btn-sm btn-secondary" style="display:block;margin:2px 0;">Excel</a>
                                    <a href="?action=export_single_project_html&project_id=<?php echo (int)$project['id']; ?>" class="btn-sm btn-secondary" style="display:block;margin:2px 0;">HTML</a>
                                </div>
                            </div>

                            <div class="share-buttons">
                                <a href="<?php echo generate_shareable_link($project['id'], 'whatsapp'); ?>"
                                   target="_blank"
                                   class="btn-sm share-whatsapp"
                                   onclick="return confirm('Share project via WhatsApp?')">📱 WhatsApp</a>
                                <a href="<?php echo generate_shareable_link($project['id'], 'telegram'); ?>"
                                   target="_blank"
                                   class="btn-sm share-telegram"
                                   onclick="return confirm('Share project via Telegram?')">✈️ Telegram</a>
                                <a href="<?php echo generate_shareable_link($project['id'], 'email'); ?>"
                                   class="btn-sm share-email"
                                   onclick="return confirm('Share project via Email?')">📧 Email</a>
                                <a href="<?php echo generate_shareable_link($project['id'], 'api'); ?>"
                                   target="_blank"
                                   class="btn-sm share-api"
                                   onclick="return confirm('View API data for this project?')">🔗 API</a>
                            </div>

                            <button type="button" class="btn-sm btn-warning" onclick="printProject(<?php echo (int)$project['id']; ?>)">
                                🖨️ Print
                            </button>
                        </div>
                    </div>

                    <!-- NEW: API Integration Section -->
                    <div class="api-section">
                        <h4>🔗 API Integration</h4>
                        <p>Share this project data with other applications:</p>
                        
                        <div class="integration-buttons">
                            <a href="?api=projects&id=<?php echo (int)$project['id']; ?>&format=json" 
                               target="_blank" class="btn-sm btn-primary">
                                📊 JSON API
                            </a>
                            <a href="?api=projects&id=<?php echo (int)$project['id']; ?>&format=xml" 
                               target="_blank" class="btn-sm btn-info">
                                📄 XML API
                            </a>
                            <a href="?action=generate_api_token&project_id=<?php echo (int)$project['id']; ?>&access_level=read" 
                               class="btn-sm btn-success">
                                🔑 Generate API Token
                            </a>
                        </div>
                        
                        <div>
                            <strong>API Endpoints:</strong>
                            <ul style="font-size: 12px; margin: 5px 0;">
                                <li>JSON: <code><?php echo generate_shareable_link($project['id'], 'api'); ?></code></li>
                                <li>All Projects: <code><?php echo generate_shareable_link(0, 'api'); ?></code></li>
                            </ul>
                        </div>
                    </div>

                    <div class="project-details-grid">
                        <div class="detail-section">
                            <h4>📋 Basic Information</h4>
                            <div class="detail-item">
                                <span class="detail-label">Project Code:</span>
                                <span class="detail-value"><?php echo h($project['code'] ?: 'N/A'); ?></span>
                            </div>
                            <div class="detail-item">
                                <span class="detail-label">Project Type:</span>
                                <span class="detail-value">
                                    <span class="type-badge"><?php echo h($project['project_type'] ?? 'Development'); ?></span>
                                </span>
                            </div>
                            <div class="detail-item">
                                <span class="detail-label">Status:</span>
                                <span class="detail-value">
                                    <span class="status-badge status-<?php echo h($project['status']); ?>">
                                        <?php echo ucfirst($project['status']); ?>
                                    </span>
                                </span>
                            </div>
                            <div class="detail-item">
                                <span class="detail-label">Donor:</span>
                                <span class="detail-value"><?php echo h($project['donor'] ?: 'N/A'); ?></span>
                            </div>
                            <div class="detail-item">
                                <span class="detail-label">Donor Reference:</span>
                                <span class="detail-value"><?php echo h($project['donor_ref'] ?: 'N/A'); ?></span>
                            </div>
                            <div class="detail-item">
                                <span class="detail-label">Implementing Partner:</span>
                                <span class="detail-value"><?php echo h($project['ip_name'] ?: 'N/A'); ?></span>
                            </div>
                            <div class="detail-item">
                                <span class="detail-label">Project Period:</span>
                                <span class="detail-value"><span class="period-badge"><?php echo $project_period; ?></span></span>
                            </div>
                            <div class="detail-item">
                                <span class="detail-label">Main Programme Sectors:</span>
                                <span class="detail-value"><?php echo h(implode(', ', $mainSectors) ?: 'N/A'); ?></span>
                            </div>
                            <div class="detail-item">
                                <span class="detail-label">Project Specific Areas:</span>
                                <span class="detail-value"><?php echo h(implode(', ', $specificSectors) ?: 'N/A'); ?></span>
                            </div>
                        </div>

                        <div class="detail-section">
                            <h4>📍 Location & Timeline</h4>
                            <div class="detail-item">
                                <span class="detail-label">Primary Region:</span>
                                <span class="detail-value"><?php echo h($project['region_name'] ?: ($project['region_other'] ?: 'N/A')); ?></span>
                            </div>
                            <div class="detail-item">
                                <span class="detail-label">Primary Zone:</span>
                                <span class="detail-value"><?php echo h($project['zone_name'] ?: ($project['zone_other'] ?: 'N/A')); ?></span>
                            </div>
                            <div class="detail-item">
                                <span class="detail-label">Primary Woreda:</span>
                                <span class="detail-value"><?php echo h($project['woreda_name'] ?: ($project['woreda_other'] ?: 'N/A')); ?></span>
                            </div>
                            <?php if ($locationsList): ?>
                                <div class="detail-item">
                                    <span class="detail-label">All Locations:</span>
                                    <span class="detail-value">
                                        <?php
                                            $parts = [];
                                            foreach ($locationsList as $loc) {
                                                $rName = $loc['region_name'] ?? $loc['region_other'] ?? '';
                                                $zName = $loc['zone_name']   ?? $loc['zone_other']   ?? '';
                                                $wName = $loc['woreda_name'] ?? $loc['woreda_other'] ?? '';
                                                $locParts = array_filter([$rName, $zName, $wName]);
                                                if ($locParts) {
                                                    $parts[] = implode(' / ', $locParts);
                                                }
                                            }
                                            echo h($parts ? implode('; ', $parts) : 'N/A');
                                        ?>
                                    </span>
                                </div>
                            <?php endif; ?>
                            <div class="detail-item">
                                <span class="detail-label">Start Date:</span>
                                <span class="detail-value"><?php echo h($project['start_date'] ?: 'N/A'); ?></span>
                            </div>
                            <div class="detail-item">
                                <span class="detail-label">End Date:</span>
                                <span class="detail-value"><?php echo h($project['end_date'] ?: 'N/A'); ?></span>
                            </div>
                        </div>

                        <div class="detail-section">
                            <h4>💰 Budget Information</h4>
                            <div class="detail-item">
                                <span class="detail-label">Total Fund (USD):</span>
                                <span class="detail-value">$<?php echo number_format((float)($project['total_fund_usd'] ?? 0), 2); ?></span>
                            </div>
                            <div class="detail-item">
                                <span class="detail-label">Currency Rate:</span>
                                <span class="detail-value"><?php echo (float)($project['currency_rate'] ?? 0); ?></span>
                            </div>
                            <div class="detail-item">
                                <span class="detail-label">Total Fund (ETB):</span>
                                <span class="detail-value">ETB <?php echo number_format((float)($project['total_fund_etb'] ?? 0), 2); ?></span>
                            </div>
                        </div>

                        <div class="detail-section">
                            <h4>👥 Contact Information</h4>
                            <div class="detail-item">
                                <span class="detail-label">Owner Name:</span>
                                <span class="detail-value"><?php echo h($project['owner_name'] ?: 'N/A'); ?></span>
                            </div>
                            <div class="detail-item">
                                <span class="detail-label">Owner Email:</span>
                                <span class="detail-value"><?php echo h($project['owner_email'] ?: 'N/A'); ?></span>
                            </div>
                            <div class="detail-item">
                                <span class="detail-label">Owner Phone:</span>
                                <span class="detail-value"><?php echo h($project['owner_phone'] ?: 'N/A'); ?></span>
                            </div>
                            <div class="detail-item">
                                <span class="detail-label">Program Manager:</span>
                                <span class="detail-value"><?php echo h($project['PM_email'] ?: 'N/A'); ?></span>
                            </div>
                        </div>
                    </div>

                    <div class="action-buttons" style="justify-content:center; margin-top:20px;">
                        <button type="button" class="btn-sm btn-primary" onclick="editProject(<?php echo (int)$project['id']; ?>)">
                            ✏️ Edit Project
                        </button>
                        <form method="post" style="display:inline;">
                            <input type="hidden" name="id" value="<?php echo (int)$project['id']; ?>">
                            <button type="submit" name="action" value="delete" class="btn-sm btn-danger"
                                    onclick="return confirm('Are you sure you want to delete project: <?php echo addslashes($project['title']); ?>? This action cannot be undone.')">
                                🗑️ Delete Project
                            </button>
                        </form>
                        <a href="?action=export_single_project_html&project_id=<?php echo (int)$project['id']; ?>" class="btn-sm btn-info">
                            📥 Download Details
                        </a>
                        <button type="button" class="btn-sm btn-warning" onclick="window.print()">
                            🖨️ Print
                        </button>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- QUICK EDIT TABLE -->
    <div class="card">
        <h2>📋 Quick Edit Projects Table (<?php echo count($projects); ?>)</h2>
        <div class="card-content">
            <p>Inline editing is enabled. Use <strong>Save/Update</strong> or <strong>Delete</strong> per row.</p>

            <table class="table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Code / Title</th>
                        <th>Project Type</th>
                        <th>Donor / Ref</th>
                        <th>IP / Status</th>
                        <th>Sectors</th>
                        <th>Location</th>
                        <th>Budget (USD / ETB)</th>
                        <th>Timeline / Period</th>
                        <th>Key emails</th>
                        <th style="width:220px;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!$projects): ?>
                        <tr><td colspan="11">No projects defined yet.</td></tr>
                    <?php else: ?>
                        <?php foreach ($projects as $p): 
                            $project_period  = calculate_project_period($p['start_date'], $p['end_date']);
                            $project_months  = calculate_project_months($p['start_date'], $p['end_date']);
                            $mainSectors     = parse_multi_values($p['main_sectors'] ?? '');
                            $specificSectors = parse_multi_values($p['specific_sectors'] ?? '');
                            $formId          = 'project-inline-form-' . (int)$p['id'];
                        ?>
                            <tr>
                                <td>
                                    <?php echo (int)$p['id']; ?>
                                </td>
                                <td>
                                    <input type="text"
                                           name="code"
                                           value="<?php echo h($p['code'] ?? ''); ?>"
                                           placeholder="Code"
                                           style="width:100%;max-width:130px;"
                                           form="<?php echo $formId; ?>"><br>
                                    <input type="text"
                                           name="title"
                                           value="<?php echo h($p['title'] ?? ''); ?>"
                                           required
                                           style="width:100%;max-width:220px;"

                                           form="<?php echo $formId; ?>">
                                </td>
                                <td>
                                    <select name="project_type"
                                            style="width:100%;max-width:150px;"
                                            form="<?php echo $formId; ?>">
                                        <?php foreach ($projectTypes as $type => $description): ?>
                                            <option value="<?php echo h($type); ?>"
                                                <?php echo ($type === ($p['project_type'] ?? 'Development')) ? 'selected' : ''; ?>>
                                                <?php echo h($type); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                                <td>
                                    <?php
                                    $currentDonor = $p['donor'] ?? '';
                                    // Try to find donor ID from name
                                    $donorId = '';
                                    if (!empty($currentDonor)) {
                                        try {
                                            $donorFindStmt = $pdo->prepare("SELECT id FROM gms_donors WHERE name = ? OR short_name = ? LIMIT 1");
                                            $donorFindStmt->execute([$currentDonor, $currentDonor]);
                                            $foundDonor = $donorFindStmt->fetch();
                                            if ($foundDonor) {
                                                $donorId = $foundDonor['id'];
                                            }
                                        } catch (Exception $e) {
                                            // Ignore
                                        }
                                    }
                                    ?>
                                    <select name="donor_id" 
                                            id="donor_select_<?php echo $p['id']; ?>"
                                            onchange="handleDonorSelectionInline(<?php echo $p['id']; ?>)"
                                            style="width:100%;max-width:180px;margin-bottom:5px;font-size:12px;"
                                            form="<?php echo $formId; ?>">
                                        <?php 
                                        echo donor_dropdown_options($donorId ?: ($currentDonor === 'OTHER' ? 'OTHER' : ''), true, false);
                                        if (!empty($currentDonor) && !$donorId && $currentDonor !== 'OTHER') {
                                            echo '<option value="OTHER" selected>Other: ' . htmlspecialchars($currentDonor) . '</option>';
                                        }
                                        ?>
                                    </select>
                                    <div id="donor_other_section_<?php echo $p['id']; ?>" style="display: <?php echo (!empty($currentDonor) && !$donorId && $currentDonor !== 'OTHER') ? 'block' : 'none'; ?>; margin-top: 2px; margin-bottom: 5px;">
                                        <input type="text"
                                               name="donor_other"
                                               id="donor_other_input_<?php echo $p['id']; ?>"
                                               value="<?php echo (!empty($currentDonor) && !$donorId) ? h($currentDonor) : ''; ?>"
                                               placeholder="Enter donor name"
                                               style="width:100%;max-width:180px;font-size:11px;"
                                               form="<?php echo $formId; ?>">
                                    </div>
                                    <input type="text"
                                           name="donor_ref"
                                           value="<?php echo h($p['donor_ref'] ?? ''); ?>"
                                           placeholder="Donor ref"
                                           style="width:100%;max-width:180px;font-size:12px;"
                                           form="<?php echo $formId; ?>">
                                </td>
                                <td>
                                    <input type="text"
                                           name="ip_name"
                                           value="<?php echo h($p['ip_name'] ?? ''); ?>"
                                           placeholder="IP name"
                                           style="width:100%;max-width:180px;"
                                           form="<?php echo $formId; ?>"><br>
                                    <select name="status"
                                            style="width:100%;max-width:180px;"
                                            form="<?php echo $formId; ?>">
                                        <option value="planned" <?php echo ($p['status']==='planned'?'selected':''); ?>>Planned</option>
                                        <option value="active"  <?php echo ($p['status']==='active' ?'selected':''); ?>>Active</option>
                                        <option value="closed"  <?php echo ($p['status']==='closed' ?'selected':''); ?>>Closed</option>
                                    </select>
                                </td>
                                <td>
                                    <input type="text"
                                           name="main_sectors_raw"
                                           value="<?php echo h(implode(', ', $mainSectors)); ?>"
                                           placeholder="Main sectors (comma separated)"
                                           style="width:100%;max-width:200px;"
                                           form="<?php echo $formId; ?>"><br>
                                    <input type="text"
                                           name="specific_sectors_raw"
                                           value="<?php echo h(implode(', ', $specificSectors)); ?>"
                                           placeholder="Specific areas"
                                           style="width:100%;max-width:200px;"
                                           form="<?php echo $formId; ?>">
                                </td>
                                <td>
                                    <select name="region_id"
                                            style="width:100%;max-width:120px;"
                                            form="<?php echo $formId; ?>">
                                        <option value="">Region</option>
                                        <?php foreach ($regions as $r): ?>
                                            <option value="<?php echo (int)$r['id']; ?>"
                                                <?php echo ((int)$r['id']==(int)$p['region_id'] ? 'selected' : ''); ?>>
                                                <?php echo h($r['name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                        <option value="other" <?php echo ($p['region_id'] === null && !empty($p['region_other'])) ? 'selected' : ''; ?>>Other</option>
                                    </select><br>
                                    <input type="text"
                                           name="region_other"
                                           value="<?php echo h($p['region_other'] ?? ''); ?>"
                                           placeholder="Region other"
                                           style="width:100%;max-width:120px; margin-top:4px;"
                                           form="<?php echo $formId; ?>">

                                    <select name="zone_id"
                                            style="width:100%;max-width:120px;margin-top:6px;"
                                            form="<?php echo $formId; ?>">
                                        <option value="">Zone</option>
                                        <?php foreach ($zones as $z): ?>
                                            <option value="<?php echo (int)$z['id']; ?>"
                                                <?php echo ((int)$z['id']==(int)$p['zone_id'] ? 'selected' : ''); ?>>
                                                <?php echo h($z['name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                        <option value="other" <?php echo ($p['zone_id'] === null && !empty($p['zone_other'])) ? 'selected' : ''; ?>>Other</option>
                                    </select><br>
                                    <input type="text"
                                           name="zone_other"
                                           value="<?php echo h($p['zone_other'] ?? ''); ?>"
                                           placeholder="Zone other"
                                           style="width:100%;max-width:120px; margin-top:4px;"
                                           form="<?php echo $formId; ?>">

                                    <select name="woreda_id"
                                            style="width:100%;max-width:120px;margin-top:6px;"
                                            form="<?php echo $formId; ?>">
                                        <option value="">Woreda</option>
                                        <?php foreach ($woredas as $w): ?>
                                            <option value="<?php echo (int)$w['id']; ?>"
                                                <?php echo ((int)$w['id']==(int)$p['woreda_id'] ? 'selected' : ''); ?>>
                                                <?php echo h($w['name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                        <option value="other" <?php echo ($p['woreda_id'] === null && !empty($p['woreda_other'])) ? 'selected' : ''; ?>>Other</option>
                                    </select><br>
                                    <input type="text"
                                           name="woreda_other"
                                           value="<?php echo h($p['woreda_other'] ?? ''); ?>"
                                           placeholder="Woreda other"
                                           style="width:100%;max-width:120px; margin-top:4px;"
                                           form="<?php echo $formId; ?>">
                                </td>
                                <td>
                                    <input type="number"
                                           step="0.01"
                                           name="total_fund_usd"
                                           value="<?php echo (float)($p['total_fund_usd'] ?? 0); ?>"
                                           placeholder="USD"
                                           style="width:100%;max-width:120px;"
                                           form="<?php echo $formId; ?>"><br>
                                    <input type="number"
                                           step="0.0001"
                                           name="currency_rate"
                                           value="<?php echo (float)($p['currency_rate'] ?? 0); ?>"
                                           placeholder="Rate"
                                           style="width:100%;max-width:120px;"
                                           form="<?php echo $formId; ?>"><br>
                                    <input type="number"
                                           step="0.01"
                                           name="total_fund_etb"
                                           value="<?php echo (float)($p['total_fund_etb'] ?? 0); ?>"
                                           placeholder="ETB"
                                           style="width:100%;max-width:150px;"
                                           form="<?php echo $formId; ?>">
                                </td>
                                <td>
                                    <input type="date"
                                           name="start_date"
                                           value="<?php echo h($p['start_date'] ?? ''); ?>"
                                           style="width:100%;max-width:150px;"
                                           form="<?php echo $formId; ?>"><br>
                                    <input type="date"
                                           name="end_date"
                                           value="<?php echo h($p['end_date'] ?? ''); ?>"
                                           style="width:100%;max-width:150px;"
                                           form="<?php echo $formId; ?>"><br>
                                    <small style="color: #666; font-weight: bold;">
                                        Period: <?php echo $project_period ?: 'N/A'; ?>
                                        <?php if (!is_null($project_months)): ?>
                                            (<?php echo $project_months; ?> months)
                                        <?php endif; ?>
                                    </small>
                                </td>
                                <td>
                                    <input type="email"
                                           name="ed_email"
                                           value="<?php echo h($p['ed_email'] ?? ''); ?>"
                                           placeholder="ED"
                                           style="width:100%;max-width:180px;"
                                           form="<?php echo $formId; ?>"><br>
                                    <input type="email"
                                           name="pm_email"
                                           value="<?php echo h($p['PM_email'] ?? ''); ?>"
                                           placeholder="PM"
                                           style="width:100%;max-width:180px;"
                                           form="<?php echo $formId; ?>"><br>
                                    <input type="email"
                                           name="opm_email"
                                           value="<?php echo h($p['opm_email'] ?? ''); ?>"
                                           placeholder="OPM"
                                           style="width:100%;max-width:180px;"
                                           form="<?php echo $formId; ?>"><br>
                                    <input type="email"
                                           name="merl_email"
                                           value="<?php echo h($p['merl_email'] ?? ''); ?>"
                                           placeholder="MERL"
                                           style="width:100%;max-width:180px;"
                                           form="<?php echo $formId; ?>">
                                </td>
                                <td>
                                    <button type="submit"
                                            class="btn-sm btn-primary"
                                            form="<?php echo $formId; ?>"
                                            name="action"
                                            value="update">
                                        💾 Save / Update
                                    </button>
                                    <button type="submit"
                                            class="btn-sm btn-danger"
                                            form="<?php echo $formId; ?>"
                                            name="action"
                                            value="delete"
                                            onclick="return confirm('Are you sure you want to delete this project? This action cannot be undone.');">
                                        🗑️ Delete
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>

            <?php if ($projects): ?>
                <?php foreach ($projects as $p): 
                    $formId = 'project-inline-form-' . (int)$p['id'];
                ?>
                    <form id="<?php echo $formId; ?>" method="post" style="display:none;">
                        <input type="hidden" name="id" value="<?php echo (int)$p['id']; ?>">
                    </form>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>


    <!-- ADVANCED ANALYTICS SECTION -->
    <div class="card">
        <h2>📊 Advanced Project Analytics</h2>
        <div class="card-content">

            <!-- Statistics Overview -->
            <div class="stats-grid">
                <div class="stat-card" style="background: #e3f2fd;">
                    <h3 style="color: #1976d2;">Total Projects</h3>
                    <p style="color: #1976d2;"><?php echo $statistics['total_projects']; ?></p>
                </div>
                <div class="stat-card" style="background: #e8f5e8;">
                    <h3 style="color: #2e7d32;">Active Projects</h3>
                    <p style="color: #2e7d32;"><?php echo $statistics['active_projects']; ?></p>
                </div>
                <div class="stat-card" style="background: #fff3e0;">
                    <h3 style="color: #ef6c00;">Total Funding (USD)</h3>
                    <p style="color: #ef6c00;">$<?php echo number_format($statistics['total_funding_usd'], 2); ?></p>
                </div>
                <div class="stat-card" style="background: #fce4ec;">
                    <h3 style="color: #c2185b;">Avg Duration</h3>
                    <p style="color: #c2185b;"><?php echo $statistics['avg_duration_months']; ?> months</p>
                </div>
            </div>

            <!-- Charts Container -->
            <div class="chart-grid">

                <!-- Projects by Status -->
                <div class="chart-container">
                    <h3>Projects by Status</h3>
                    <canvas id="statusChart" width="400" height="300"></canvas>
                </div>

                <!-- Projects by Project Type -->
                <div class="chart-container">
                    <h3>Projects by Type</h3>
                    <canvas id="projectTypeChart" width="400" height="300"></canvas>
                </div>

                <!-- Projects by Region -->
                <div class="chart-container">
                    <h3>Projects by Region</h3>
                    <canvas id="regionChart" width="400" height="300"></canvas>
                </div>

                <!-- Top Donors by Funding -->
                <div class="chart-container">
                    <h3>Top Donors by Funding</h3>
                    <canvas id="donorChart" width="400" height="300"></canvas>
                </div>

                <!-- Projects by Period -->
                <div class="chart-container">
                    <h3>Projects by Duration</h3>
                    <canvas id="periodChart" width="400" height="300"></canvas>
                </div>

                <!-- Funding by Project Type -->
                <div class="chart-container">
                    <h3>Funding by Project Type</h3>
                    <canvas id="fundingByTypeChart" width="400" height="300"></canvas>
                </div>

                <!-- Projects by Implementing Partner -->
                <div class="chart-container">
                    <h3>Projects by Implementing Partner</h3>
                    <canvas id="ipChart" width="400" height="300"></canvas>
                </div>

                <!-- Funding by Sector -->
                <div class="chart-container">
                    <h3>Funding by Sector</h3>
                    <canvas id="sectorChart" width="400" height="300"></canvas>
                </div>

            </div>
        </div>
    </div>

    <script>
    (function () {
        // Analytics data from PHP
        const analyticsData = {
            status: <?php echo json_encode($analytics['by_status'] ?? [], JSON_UNESCAPED_UNICODE); ?>,
            project_type: <?php echo json_encode($analytics['by_project_type'] ?? [], JSON_UNESCAPED_UNICODE); ?>,
            region: <?php echo json_encode(array_slice($analytics['by_region'] ?? [], 0, 10), JSON_UNESCAPED_UNICODE); ?>,
            donor: <?php echo json_encode(array_slice($analytics['by_donor'] ?? [], 0, 10), JSON_UNESCAPED_UNICODE); ?>,
            period: <?php echo json_encode($analytics['by_period'] ?? [], JSON_UNESCAPED_UNICODE); ?>,
            ip: <?php echo json_encode(array_slice($analytics['by_ip'] ?? [], 0, 10), JSON_UNESCAPED_UNICODE); ?>,
            sector: <?php echo json_encode(array_slice($analytics['funding_by_sector'] ?? [], 0, 10), JSON_UNESCAPED_UNICODE); ?>,
            funding_by_type: <?php echo json_encode($analytics['funding_by_project_type'] ?? [], JSON_UNESCAPED_UNICODE); ?>
        };

        // Hierarchical locations from PHP (Region → Zone → [Woreda...])
        const LOCATIONS = <?php echo json_encode($locations ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;

        // ---------------------------- Helpers: ETB & Period ----------------------------

        function calculateETB() {
            const usdField  = document.querySelector('input[name="total_fund_usd"]');
            const rateField = document.querySelector('input[name="currency_rate"]');
            const etbField  = document.getElementById('total_fund_etb');
            if (!usdField || !rateField || !etbField) return;

            const usd  = parseFloat((usdField.value || '').replace(',', '.')) || 0;
            const rate = parseFloat((rateField.value || '').replace(',', '.')) || 0;

            if (usd > 0 && rate > 0) {
                etbField.value = (usd * rate).toFixed(2);
            } else {
                // don't overwrite if user typed manually
            }
        }

        function calculateProjectPeriod() {
            const startDate = document.getElementById('start_date');
            const endDate   = document.getElementById('end_date');
            const box       = document.getElementById('periodCalculation');
            const result    = document.getElementById('periodResult');

            if (!startDate || !endDate || !box || !result) return;

            const sVal = startDate.value;
            const eVal = endDate.value;

            if (!sVal || !eVal) {
                box.style.display = 'none';
                result.textContent = '';
                return;
            }

            const start = new Date(sVal);
            const end   = new Date(eVal);

            if (isNaN(start.getTime()) || isNaN(end.getTime()) || end < start) {
                box.style.display = 'block';
                box.style.background = '#ffebee';
                box.style.borderColor = '#f44336';
                result.textContent = '❌ End date must be after start date.';
                return;
            }

            let years  = end.getFullYear() - start.getFullYear();
            let months = end.getMonth() - start.getMonth();

            if (end.getDate() > start.getDate()) {
                months += 1;
            }
            if (months < 0) {
                years--;
                months += 12;
            }

            let totalMonths = years * 12 + months;
            if (totalMonths <= 0) totalMonths = 1;

            let periodText;
            if (totalMonths === 1) {
                periodText = '1 month';
            } else if (totalMonths < 12) {
                periodText = totalMonths + ' months';
            } else {
                const y  = Math.floor(totalMonths / 12);
                const rm = totalMonths % 12;
                if (rm === 0) {
                    periodText = y + ' year' + (y > 1 ? 's' : '');
                } else {
                    periodText = y + ' year' + (y > 1 ? 's' : '') + ' ' +
                                 rm + ' month' + (rm > 1 ? 's' : '');
                }
            }

            box.style.display = 'block';
            box.style.background = '#e8f5e8';
            box.style.borderColor = '#4caf50';
            result.textContent = '⏱️ ' + periodText;
        }

        // ---------------------------- AI Helper ----------------------------

        function askAI() {
            const input = document.getElementById('ai-question');
            const box   = document.getElementById('ai-response');
            if (!input || !box) return;

            const question = input.value.trim();
            box.style.display = 'block';

            if (!question) {
                box.textContent = 'Please type your question first.';
                return;
            }

            box.textContent = 'Thinking...';

            const formData = new FormData();
            formData.append('ai_assistant', '1');
            formData.append('context', 'project_setup');
            formData.append('user_input', question);

            fetch(window.location.href, {
                method: 'POST',
                body: formData,
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            })
            .then(res => res.json())
            .then(data => {
                if (!data) return;
                const title   = data.title   || 'Suggestion';
                const message = data.message || '';
                box.innerHTML = '<strong>' + title + ':</strong> ' + message;
            })
            .catch(() => {
                box.textContent = 'Sorry, something went wrong while getting a suggestion.';
            });
        }

        // ---------------------------- Notifications ----------------------------

        function initNotifications() {
            const container = document.querySelector('.notification-container');
            if (!container) return;
            const items = container.querySelectorAll('.notification');
            items.forEach((item, index) => {
                setTimeout(() => {
                    item.style.transition = 'opacity 0.5s';
                    item.style.opacity = '0';
                    setTimeout(() => {
                        if (item.parentNode) item.parentNode.removeChild(item);
                    }, 700);
                }, 4000 + index * 1000);
            });
        }

        // ---------------------------- Locations (Region → Zone → Woreda) ----------------------------

        function resetSelect(selectEl, includeOther = true) {
            if (!selectEl) return;
            selectEl.innerHTML = '';

            const optEmpty = document.createElement('option');
            optEmpty.value = '';
            optEmpty.textContent = '--';
            selectEl.appendChild(optEmpty);

            if (includeOther) {
                const optOther = document.createElement('option');
                optOther.value = 'other';
                optOther.textContent = 'Other (specify)';
                selectEl.appendChild(optOther);
            }
        }

        function populateRegions(selectEl) {
            if (!selectEl) return;
            resetSelect(selectEl, true);

            const beforeOther = selectEl.querySelector('option[value="other"]');

            Object.keys(LOCATIONS).forEach(regionName => {
                const opt = document.createElement('option');
                opt.value = regionName;
                opt.textContent = regionName;
                if (beforeOther) {
                    selectEl.insertBefore(opt, beforeOther);
                } else {
                    selectEl.appendChild(opt);
                }
            });
        }

        function populateZones(regionName, zoneSelect) {
            if (!zoneSelect) return;
            resetSelect(zoneSelect, true);

            if (!regionName || !LOCATIONS[regionName]) return;

            const zonesObj = LOCATIONS[regionName];
            const beforeOther = zoneSelect.querySelector('option[value="other"]');

            Object.keys(zonesObj).forEach(zoneName => {
                const opt = document.createElement('option');
                opt.value = zoneName;
                opt.textContent = zoneName;
                if (beforeOther) {
                    zoneSelect.insertBefore(opt, beforeOther);
                } else {
                    zoneSelect.appendChild(opt);
                }
            });
        }

        function populateWoredas(regionName, zoneName, woredaSelect) {
            if (!woredaSelect) return;
            resetSelect(woredaSelect, true);

            if (!regionName || !zoneName ||
                !LOCATIONS[regionName] ||
                !LOCATIONS[regionName][zoneName]) {
                return;
            }

            const woredasArr = LOCATIONS[regionName][zoneName];
            const beforeOther = woredaSelect.querySelector('option[value="other"]');

            woredasArr.forEach(wName => {
                const opt = document.createElement('option');
                opt.value = wName;
                opt.textContent = wName;
                if (beforeOther) {
                    woredaSelect.insertBefore(opt, beforeOther);
                } else {
                    woredaSelect.appendChild(opt);
                }
            });
        }

        function wireLocationRow(regionSelect, zoneSelect, woredaSelect,
                                 regionOtherInput, zoneOtherInput, woredaOtherInput) {

            if (!regionSelect || !zoneSelect || !woredaSelect) return;

            // Region change
            regionSelect.addEventListener('change', function () {
                const regionVal = regionSelect.value;

                if (regionVal === 'other') {
                    if (regionOtherInput) {
                        regionOtherInput.style.display = 'block';
                        regionOtherInput.value = '';
                    }
                    resetSelect(zoneSelect, true);
                    resetSelect(woredaSelect, true);
                    if (zoneOtherInput)  { zoneOtherInput.style.display = 'none'; zoneOtherInput.value = ''; }
                    if (woredaOtherInput){ woredaOtherInput.style.display = 'none'; woredaOtherInput.value = ''; }

                } else if (regionVal === '') {
                    if (regionOtherInput) { regionOtherInput.style.display = 'none'; regionOtherInput.value = ''; }
                    resetSelect(zoneSelect, true);
                    resetSelect(woredaSelect, true);
                    if (zoneOtherInput)  { zoneOtherInput.style.display = 'none'; zoneOtherInput.value = ''; }
                    if (woredaOtherInput){ woredaOtherInput.style.display = 'none'; woredaOtherInput.value = ''; }

                } else {
                    if (regionOtherInput) {
                        regionOtherInput.style.display = 'none';
                        regionOtherInput.value = regionVal; // store name for DB
                    }
                    populateZones(regionVal, zoneSelect);
                    resetSelect(woredaSelect, true);
                    if (zoneOtherInput)  { zoneOtherInput.style.display = 'none'; zoneOtherInput.value = ''; }
                    if (woredaOtherInput){ woredaOtherInput.style.display = 'none'; woredaOtherInput.value = ''; }
                }
            });

            // Zone change
            zoneSelect.addEventListener('change', function () {
                const regionVal = regionSelect.value;
                const zoneVal   = zoneSelect.value;

                if (zoneVal === 'other') {
                    if (zoneOtherInput) {
                        zoneOtherInput.style.display = 'block';
                        zoneOtherInput.value = '';
                    }
                    resetSelect(woredaSelect, true);
                    if (woredaOtherInput) { woredaOtherInput.style.display = 'none'; woredaOtherInput.value = ''; }

                } else if (zoneVal === '') {
                    if (zoneOtherInput) { zoneOtherInput.style.display = 'none'; zoneOtherInput.value = ''; }
                    resetSelect(woredaSelect, true);
                    if (woredaOtherInput) { woredaOtherInput.style.display = 'none'; woredaOtherInput.value = ''; }

                } else {
                    if (zoneOtherInput) {
                        zoneOtherInput.style.display = 'none';
                        zoneOtherInput.value = zoneVal;
                    }
                    populateWoredas(regionVal, zoneVal, woredaSelect);
                    if (woredaOtherInput) { woredaOtherInput.style.display = 'none'; woredaOtherInput.value = ''; }
                }
            });

            // Woreda change
            woredaSelect.addEventListener('change', function () {
                const woredaVal = woredaSelect.value;

                if (woredaVal === 'other') {
                    if (woredaOtherInput) {
                        woredaOtherInput.style.display = 'block';
                        woredaOtherInput.value = '';
                    }
                } else {
                    if (woredaOtherInput) {
                        woredaOtherInput.style.display = 'none';
                        woredaOtherInput.value = (woredaVal === '') ? '' : woredaVal;
                    }
                }
            });
        }

        function addLocationRow() {
            const container = document.getElementById('additional-locations');
            if (!container) return;

            const index = container.querySelectorAll('.location-row-extra').length;

            const row = document.createElement('div');
            row.className = 'form-row location-row location-row-extra';
            row.style.marginTop = '10px';

            row.innerHTML = `
                <label>Region
                    <select name="locations[${index}][region_id]" class="region-select">
                        <option value="">--</option>
                        <option value="other">Other (specify)</option>
                    </select>
                    <input type="text"
                           name="locations[${index}][region_other]"
                           class="other-input region-other-input"
                           style="display:none;margin-top:4px;"
                           placeholder="Specify region">
                </label>

                <label>Zone
                    <select name="locations[${index}][zone_id]" class="zone-select">
                        <option value="">--</option>
                        <option value="other">Other (specify)</option>
                    </select>
                    <input type="text"
                           name="locations[${index}][zone_other]"
                           class="other-input zone-other-input"
                           style="display:none;margin-top:4px;"
                           placeholder="Specify zone">
                </label>

                <label>Woreda
                    <select name="locations[${index}][woreda_id]" class="woreda-select">
                        <option value="">--</option>
                        <option value="other">Other (specify)</option>
                    </select>
                    <input type="text"
                           name="locations[${index}][woreda_other]"
                           class="other-input woreda-other-input"
                           style="display:none;margin-top:4px;"
                           placeholder="Specify woreda">
                </label>

                <button type="button"
                        class="btn-sm btn-danger no-print"
                        style="align-self:flex-end;margin-left:10px;"
                        onclick="this.closest('.location-row-extra').remove();">
                    ✖ Remove
                </button>
            `;

            container.appendChild(row);

            const regionSelect = row.querySelector('.region-select');
            const zoneSelect   = row.querySelector('.zone-select');
            const woredaSelect = row.querySelector('.woreda-select');

            const regionOtherInput = row.querySelector('.region-other-input');
            const zoneOtherInput   = row.querySelector('.zone-other-input');
            const woredaOtherInput = row.querySelector('.woreda-other-input');

            populateRegions(regionSelect);
            resetSelect(zoneSelect, true);
            resetSelect(woredaSelect, true);

            wireLocationRow(regionSelect, zoneSelect, woredaSelect,
                            regionOtherInput, zoneOtherInput, woredaOtherInput);
        }

        // ---------------------------- Charts ----------------------------

        function initSummaryCharts() {
            if (typeof Chart === 'undefined') return;

            // Top summary card charts
            const statusCanvas = document.getElementById('chartStatus');
            if (statusCanvas && Array.isArray(analyticsData.status)) {
                const labels = analyticsData.status.map(r => r.status || 'Not specified');
                const data   = analyticsData.status.map(r => parseInt(r.count || 0, 10));

                if (labels.length) {
                    new Chart(statusCanvas.getContext('2d'), {
                        type: 'bar',
                        data: {
                            labels,
                            datasets: [{ label: 'Projects', data }]
                        },
                        options: {
                            responsive: true,
                            plugins: { legend: { display: false } },
                            scales: { y: { beginAtZero: true } }
                        }
                    });
                }
            }

            const ptCanvas = document.getElementById('chartProjectType');
            if (ptCanvas && Array.isArray(analyticsData.funding_by_type)) {
                const labels = analyticsData.funding_by_type.map(row => row.project_type || 'N/A');
                const data   = analyticsData.funding_by_type.map(row => parseFloat(row.total_funding || 0));

                if (labels.length) {
                    new Chart(ptCanvas.getContext('2d'), {
                        type: 'bar',
                        data: {
                            labels,
                            datasets: [{ label: 'Funding (USD)', data }]
                        },
                        options: {
                            indexAxis: 'y',
                            responsive: true,
                            plugins: { legend: { display: false } },
                            scales: { x: { beginAtZero: true } }
                        }
                    });
                }
            }
        }

        function initAdvancedCharts() {
            if (typeof Chart === 'undefined') return;

            // Projects by Status (pie)
            const statusChartEl = document.getElementById('statusChart');
            if (statusChartEl && analyticsData.status) {
                new Chart(statusChartEl, {
                    type: 'pie',
                    data: {
                        labels: analyticsData.status.map(item => item.status),
                        datasets: [{
                            data: analyticsData.status.map(item => item.count),
                            backgroundColor: ['#4CAF50', '#FFC107', '#F44336', '#2196F3', '#9C27B0']
                        }]
                    },
                    options: {
                        responsive: true,
                        plugins: { legend: { position: 'bottom' } }
                    }
                });
            }

            // Projects by Type (doughnut)
            const projectTypeChartEl = document.getElementById('projectTypeChart');
            if (projectTypeChartEl && analyticsData.project_type) {
                new Chart(projectTypeChartEl, {
                    type: 'doughnut',
                    data: {
                        labels: analyticsData.project_type.map(item => item.project_type),
                        datasets: [{
                            data: analyticsData.project_type.map(item => item.count),
                            backgroundColor: ['#4CAF50', '#2196F3', '#FF9800', '#9C27B0', '#607D8B']
                        }]
                    },
                    options: {
                        responsive: true,
                        plugins: { legend: { position: 'bottom' } }
                    }
                });
            }

            // Projects by Region (bar)
            const regionChartEl = document.getElementById('regionChart');
            if (regionChartEl && analyticsData.region) {
                new Chart(regionChartEl, {
                    type: 'bar',
                    data: {
                        labels: analyticsData.region.map(item => item.region),
                        datasets: [{
                            label: 'Number of Projects',
                            data: analyticsData.region.map(item => item.count)
                        }]
                    },
                    options: {
                        responsive: true,
                        scales: { y: { beginAtZero: true } }
                    }
                });
            }

            // Donors by Funding (bar)
            const donorChartEl = document.getElementById('donorChart');
            if (donorChartEl && analyticsData.donor) {
                new Chart(donorChartEl, {
                    type: 'bar',
                    data: {
                        labels: analyticsData.donor.map(item => item.donor),
                        datasets: [{
                            label: 'Total Funding (USD)',
                            data: analyticsData.donor.map(item => item.total_funding)
                        }]
                    },
                    options: {
                        responsive: true,
                        scales: {
                            y: {
                                beginAtZero: true,
                                ticks: {
                                    callback: function(value) {
                                        return '$' + value.toLocaleString();
                                    }
                                }
                            }
                        }
                    }
                });
            }

            // Projects by Period (doughnut)
            const periodChartEl = document.getElementById('periodChart');
            if (periodChartEl && analyticsData.period) {
                new Chart(periodChartEl, {
                    type: 'doughnut',
                    data: {
                        labels: Object.keys(analyticsData.period),
                        datasets: [{
                            data: Object.values(analyticsData.period),
                            backgroundColor: ['#FF6384', '#36A2EB', '#FFCE56', '#4BC0C0']
                        }]
                    },
                    options: {
                        responsive: true,
                        plugins: { legend: { position: 'bottom' } }
                    }
                });
            }

            // Funding by Project Type (bar)
            const fundingByTypeChartEl = document.getElementById('fundingByTypeChart');
            if (fundingByTypeChartEl && analyticsData.funding_by_type) {
                new Chart(fundingByTypeChartEl, {
                    type: 'bar',
                    data: {
                        labels: analyticsData.funding_by_type.map(item => item.project_type),
                        datasets: [{
                            label: 'Total Funding (USD)',
                            data: analyticsData.funding_by_type.map(item => item.total_funding)
                        }]
                    },
                    options: {
                        responsive: true,
                        scales: {
                            y: {
                                beginAtZero: true,
                                ticks: {
                                    callback: function(value) {
                                        return '$' + value.toLocaleString();
                                    }
                                }
                            }
                        }
                    }
                });
            }

            // Implementing Partners (horizontal bar)
            const ipChartEl = document.getElementById('ipChart');
            if (ipChartEl && analyticsData.ip) {
                new Chart(ipChartEl, {
                    type: 'bar',
                    data: {
                        labels: analyticsData.ip.map(item => item.ip),
                        datasets: [{
                            label: 'Number of Projects',
                            data: analyticsData.ip.map(item => item.count)
                        }]
                    },
                    options: {
                        responsive: true,
                        indexAxis: 'y',
                        scales: { x: { beginAtZero: true } }
                    }
                });
            }

            // Funding by Sector (horizontal bar)
            const sectorChartEl = document.getElementById('sectorChart');
            if (sectorChartEl && analyticsData.sector) {
                new Chart(sectorChartEl, {
                    type: 'bar',
                    data: {
                        labels: analyticsData.sector.map(item => {
                            try {
                                const sectors = JSON.parse(item.main_sectors || '[]');
                                return sectors.length > 0 ? sectors[0] : 'Unknown';
                            } catch (e) {
                                return 'Unknown';
                            }
                        }),
                        datasets: [{
                            label: 'Funding (USD)',
                            data: analyticsData.sector.map(item => item.total_funding)
                        }]
                    },
                    options: {
                        responsive: true,
                        indexAxis: 'y',
                        scales: {
                            x: {
                                beginAtZero: true,
                                ticks: {
                                    callback: function(value) {
                                        return '$' + value.toLocaleString();
                                    }
                                }
                            }
                        }
                    }
                });
            }
        }

        // ---------------------------- Misc helpers ----------------------------

        function editProject(projectId) {
            const hidden = document.querySelector(`form input[name="id"][value="${projectId}"]`);
            if (hidden) {
                const row = hidden.closest('tr');
                if (row) {
                    row.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    row.style.backgroundColor = '#fff3cd';
                    setTimeout(() => { row.style.backgroundColor = ''; }, 2000);
                }
            }
        }

        function printProject(projectId) {
            const originalTitle = document.title;
            document.title = "Project Details - " + projectId;
            window.print();
            document.title = originalTitle;
        }

        // ---------------------------- DOMContentLoaded ----------------------------

        document.addEventListener('DOMContentLoaded', function () {
            initNotifications();
            calculateProjectPeriod();

            // Primary location row
            const primaryRegion = document.getElementById('primary_region');
            const primaryZone   = document.getElementById('primary_zone');
            const primaryWoreda = document.getElementById('primary_woreda');

            const primaryRegionOther = document.querySelector('input[name="region_other"]');
            const primaryZoneOther   = document.querySelector('input[name="zone_other"]');
            const primaryWoredaOther = document.querySelector('input[name="woreda_other"]');

            if (primaryRegion && primaryZone && primaryWoreda) {
                populateRegions(primaryRegion);
                resetSelect(primaryZone, true);
                resetSelect(primaryWoreda, true);

                // If edit mode and *_other values exist, pre-select them
                const savedRegion = primaryRegionOther && primaryRegionOther.value ? primaryRegionOther.value.trim() : '';
                const savedZone   = primaryZoneOther   && primaryZoneOther.value   ? primaryZoneOther.value.trim()   : '';
                const savedWoreda = primaryWoredaOther && primaryWoredaOther.value ? primaryWoredaOther.value.trim() : '';

                if (savedRegion && LOCATIONS[savedRegion]) {
                    primaryRegion.value = savedRegion;
                    populateZones(savedRegion, primaryZone);

                    if (savedZone && LOCATIONS[savedRegion][savedZone]) {
                        primaryZone.value = savedZone;
                        populateWoredas(savedRegion, savedZone, primaryWoreda);

                        if (savedWoreda) {
                            primaryWoreda.value = savedWoreda;
                        }
                    }
                }

                wireLocationRow(primaryRegion, primaryZone, primaryWoreda,
                                primaryRegionOther, primaryZoneOther, primaryWoredaOther);
            }

            // Any existing extra rows (if rendered by PHP)
            document.querySelectorAll('#additional-locations .location-row-extra').forEach(row => {
                const regionSelect = row.querySelector('.region-select');
                const zoneSelect   = row.querySelector('.zone-select');
                const woredaSelect = row.querySelector('.woreda-select');

                const regionOtherInput = row.querySelector('.region-other-input');
                const zoneOtherInput   = row.querySelector('.zone-other-input');
                const woredaOtherInput = row.querySelector('.woreda-other-input');

                populateRegions(regionSelect);

                const savedRegion = regionOtherInput && regionOtherInput.value ? regionOtherInput.value.trim() : '';
                const savedZone   = zoneOtherInput   && zoneOtherInput.value   ? zoneOtherInput.value.trim()   : '';
                const savedWoreda = woredaOtherInput && woredaOtherInput.value ? woredaOtherInput.value.trim() : '';

                if (savedRegion && LOCATIONS[savedRegion]) {
                    regionSelect.value = savedRegion;
                    populateZones(savedRegion, zoneSelect);

                    if (savedZone && LOCATIONS[savedRegion][savedZone]) {
                        zoneSelect.value = savedZone;
                        populateWoredas(savedRegion, savedZone, woredaSelect);

                        if (savedWoreda) {
                            woredaSelect.value = savedWoreda;
                        }
                    }
                }

                wireLocationRow(regionSelect, zoneSelect, woredaSelect,
                                regionOtherInput, zoneOtherInput, woredaOtherInput);
            });

            // Init charts
            initSummaryCharts();
            initAdvancedCharts();

            // Dropdowns in card Download
            document.querySelectorAll('.dropdown').forEach(dropdown => {
                const button = dropdown.querySelector('.dropdown-toggle') || dropdown.querySelector('button');
                const content = dropdown.querySelector('.dropdown-content');
                if (!button || !content) return;

                button.addEventListener('click', function(e) {
                    e.stopPropagation();
                    content.style.display = content.style.display === 'block' ? 'none' : 'block';
                });
                document.addEventListener('click', function() {
                    content.style.display = 'none';
                });
            });

            // Form validation only for the main "Create New Project" form
            const createForm = document.getElementById('projectForm');
            if (createForm) {
                createForm.addEventListener('submit', function(e) {
                    const title     = createForm.querySelector('input[name="title"]');
                    const pmEmail   = createForm.querySelector('input[name="pm_email"]');
                    const startDate = createForm.querySelector('input[name="start_date"]');
                    const endDate   = createForm.querySelector('input[name="end_date"]');
                    const actionInput = createForm.querySelector('input[name="action"]');
                    const actionValue = actionInput ? actionInput.value : '';

                    if (title && !title.value.trim()) {
                        e.preventDefault();
                        alert('Project title is required.');
                        title.focus();
                        return;
                    }

                    if (pmEmail && !pmEmail.value.trim() && actionValue === 'create') {
                        e.preventDefault();
                        alert('Program Manager email is required for new projects.');
                        pmEmail.focus();
                        return;
                    }

                    if (startDate && endDate && startDate.value && endDate.value) {
                        const s = new Date(startDate.value);
                        const d = new Date(endDate.value);
                        if (d < s) {
                            e.preventDefault();
                            alert('End date must be after start date.');
                            endDate.focus();
                            return;
                        }
                    }
                });
            }
        });

        // Expose functions for inline HTML handlers
        window.calculateETB = calculateETB;
        window.calculateProjectPeriod = calculateProjectPeriod;
        window.addLocationRow = addLocationRow;
        window.askAI = askAI;
        window.editProject = editProject;
        window.printProject = printProject;
    })();
    </script>

</body>
</html>
<script>
(function () {
  // Regions list from PHP (id + name) for extra rows
  const REGION_OPTS = <?php echo json_encode(
      array_map(
        fn($r) => ['id' => (int)$r['id'], 'name' => (string)$r['name']],
        $regions ?? []
      ),
      JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
  ); ?>;

  // Simple caches to avoid repeat requests
  const ZONES_CACHE   = Object.create(null); // key: regionId -> [{id,name}]
  const WOREDA_CACHE  = Object.create(null); // key: zoneId   -> [{id,name}]

  // -------------- tiny helpers --------------
  const addOpt = (sel, val, text) => {
    const o = document.createElement('option');
    o.value = String(val);
    o.textContent = text;
    sel.appendChild(o);
  };
  const resetSel = (sel, withOther = true) => {
    sel.innerHTML = '';
    addOpt(sel, '', '--');
    if (withOther) addOpt(sel, 'other', 'Other (specify)');
  };
  const show = el => { if (el) el.style.display = 'block'; };
  const hide = el => { if (el) { el.style.display = 'none'; el.value = ''; } };

  // -------------- AJAX loaders (ID based) --------------
  async function loadZones(regionId) {
    if (!regionId) return [];
    if (ZONES_CACHE[regionId]) return ZONES_CACHE[regionId];
    const res  = await fetch(`?ajax=zones&region_id=${encodeURIComponent(regionId)}`, {
      headers: { 'X-Requested-With': 'XMLHttpRequest' }
    });
    const json = await res.json().catch(() => ({}));
    const rows = Array.isArray(json?.data) ? json.data : (Array.isArray(json) ? json : []);
    const clean = rows.map(z => ({ id: parseInt(z.id, 10), name: String(z.name) }));
    ZONES_CACHE[regionId] = clean;
    return clean;
  }

  async function loadWoredas(zoneId) {
    if (!zoneId) return [];
    if (WOREDA_CACHE[zoneId]) return WOREDA_CACHE[zoneId];
    const res  = await fetch(`?ajax=woredas&zone_id=${encodeURIComponent(zoneId)}`, {
      headers: { 'X-Requested-With': 'XMLHttpRequest' }
    });
    const json = await res.json().catch(() => ({}));
    const rows = Array.isArray(json?.data) ? json.data : (Array.isArray(json) ? json : []);
    const clean = rows.map(w => ({ id: parseInt(w.id, 10), name: String(w.name) }));
    WOREDA_CACHE[zoneId] = clean;
    return clean;
  }

  // -------------- wire a single row (primary or extra) --------------
  function wireRow(scope) {
    const regionSel = scope.querySelector('.region-select, #primary_region');
    const zoneSel   = scope.querySelector('.zone-select, #primary_zone');
    const wrdSel    = scope.querySelector('.woreda-select, #primary_woreda');

    const rOther = scope.querySelector('.region-other-input');
    const zOther = scope.querySelector('.zone-other-input');
    const wOther = scope.querySelector('.woreda-other-input');

    if (!regionSel || !zoneSel || !wrdSel) return;

    // Region change
    regionSel.addEventListener('change', async () => {
      const val = regionSel.value;
      resetSel(zoneSel, true);
      resetSel(wrdSel, true);
      hide(zOther); hide(wOther);

      if (val === 'other') { show(rOther); return; }
      hide(rOther);
      if (!val) return;

      const zones = await loadZones(val);
      zones.forEach(z => addOpt(zoneSel, z.id, z.name));
    });

    // Zone change
    zoneSel.addEventListener('change', async () => {
      const val = zoneSel.value;
      resetSel(wrdSel, true);
      hide(wOther);

      if (val === 'other') { show(zOther); return; }
      hide(zOther);
      if (!val) return;

      const woredas = await loadWoredas(val);
      woredas.forEach(w => addOpt(wrdSel, w.id, w.name));
    });

    // Woreda change
    wrdSel.addEventListener('change', () => {
      const val = wrdSel.value;
      if (val === 'other') show(wOther); else hide(wOther);
    });
  }

  // -------------- PRIMARY ROW (uses server-provided region options with IDs) --------------
  (function initPrimary() {
    const primary = document.querySelector('.primary-location-row');
    if (!primary) return;
    wireRow(primary);

    // If user preselected region before reload, re-populate zones/woredas:
    const regionSel = primary.querySelector('#primary_region');
    if (regionSel && regionSel.value) {
      regionSel.dispatchEvent(new Event('change'));
    }
  })();

  // -------------- EXTRA ROWS: override global addLocationRow() to be ID-based --------------
  window.addLocationRow = function addLocationRow() {
    const container = document.getElementById('additional-locations');
    if (!container) return;

    const idx = container.querySelectorAll('.location-row-extra').length;
    const row = document.createElement('div');
    row.className = 'form-row location-row location-row-extra';
    row.innerHTML = `
      <label>Region
        <select name="locations[${idx}][region_id]" class="region-select">
          <option value="">--</option>
          ${REGION_OPTS.map(r => `<option value="${r.id}">${r.name.replace(/"/g,'&quot;')}</option>`).join('')}
          <option value="other">Other (specify)</option>
        </select>
        <input type="text" name="locations[${idx}][region_other]"
               class="other-input region-other-input" style="display:none;margin-top:4px;"
               placeholder="Specify region">
      </label>

      <label>Zone
        <select name="locations[${idx}][zone_id]" class="zone-select">
          <option value="">--</option>
          <option value="other">Other (specify)</option>
        </select>
        <input type="text" name="locations[${idx}][zone_other]"
               class="other-input zone-other-input" style="display:none;margin-top:4px;"
               placeholder="Specify zone">
      </label>

      <label>Woreda
        <select name="locations[${idx}][woreda_id]" class="woreda-select">
          <option value="">--</option>
          <option value="other">Other (specify)</option>
        </select>
        <input type="text" name="locations[${idx}][woreda_other]"
               class="other-input woreda-other-input" style="display:none;margin-top:4px;"
               placeholder="Specify woreda">
      </label>

      <button type="button" class="btn-sm btn-danger no-print"
              style="align-self:flex-end;margin-left:10px;"
              onclick="this.closest('.location-row-extra').remove();">✖ Remove</button>
    `;

    container.appendChild(row);
    wireRow(row);
  };

})();
<script>
// Analytics data from PHP
const analyticsData = {
    status: <?php echo json_encode($analytics['by_status'] ?? [], JSON_UNESCAPED_UNICODE); ?>,
    project_type: <?php echo json_encode($analytics['by_project_type'] ?? [], JSON_UNESCAPED_UNICODE); ?>,
    region: <?php echo json_encode(array_slice($analytics['by_region'] ?? [], 0, 10), JSON_UNESCAPED_UNICODE); ?>,
    donor: <?php echo json_encode(array_slice($analytics['by_donor'] ?? [], 0, 10), JSON_UNESCAPED_UNICODE); ?>,
    period: <?php echo json_encode($analytics['by_period'] ?? [], JSON_UNESCAPED_UNICODE); ?>,
    ip: <?php echo json_encode(array_slice($analytics['by_ip'] ?? [], 0, 10), JSON_UNESCAPED_UNICODE); ?>,
    sector: <?php echo json_encode(array_slice($analytics['funding_by_sector'] ?? [], 0, 10), JSON_UNESCAPED_UNICODE); ?>,
    funding_by_type: <?php echo json_encode($analytics['funding_by_project_type'] ?? [], JSON_UNESCAPED_UNICODE); ?>
};

// Region <option> HTML for extra location rows (built in PHP)
const REGION_OPTIONS_HTML = <?php echo json_encode(isset($regionOptionsHtml) ? $regionOptionsHtml : '', JSON_UNESCAPED_UNICODE); ?>;

// ---------------------------- Helpers: ETB & Period ----------------------------

// Handle donor selection (create form)
function handleDonorSelection() {
    const donorSelect = document.getElementById('donor_select');
    const donorOtherSection = document.getElementById('donor_other_section');
    if (donorSelect && donorOtherSection) {
        if (donorSelect.value === 'OTHER') {
            donorOtherSection.style.display = 'block';
        } else {
            donorOtherSection.style.display = 'none';
        }
    }
}

// Handle donor selection (inline edit)
function handleDonorSelectionInline(projectId) {
    const donorSelect = document.getElementById('donor_select_' + projectId);
    const donorOtherSection = document.getElementById('donor_other_section_' + projectId);
    if (donorSelect && donorOtherSection) {
        if (donorSelect.value === 'OTHER') {
            donorOtherSection.style.display = 'block';
        } else {
            donorOtherSection.style.display = 'none';
        }
    }
}

// Handle currency selection
function handleCurrencySelection() {
    const currencySelect = document.getElementById('currency_select');
    const currencyOtherSection = document.getElementById('currency_other_section');
    if (currencySelect && currencyOtherSection) {
        if (currencySelect.value === 'OTHER') {
            currencyOtherSection.style.display = 'block';
        } else {
            currencyOtherSection.style.display = 'none';
        }
    }
    updateCurrencyRate();
}

// Update currency rate based on selected currency
function updateCurrencyRate() {
    const currencySelect = document.getElementById('currency_select');
    const rateInput = document.getElementById('currency_rate');
    if (!currencySelect || !rateInput) return;
    
    const currency = currencySelect.value;
    if (currency === 'OTHER' || currency === '') return;
    
    // Try to get rate from option data attribute
    const selectedOption = currencySelect.options[currencySelect.selectedIndex];
    const dataRate = selectedOption ? selectedOption.getAttribute('data-rate') : null;
    
    if (dataRate && parseFloat(dataRate) > 0) {
        rateInput.value = parseFloat(dataRate);
        calculateETB();
    } else {
        // Fetch rate from server
        fetch('?action=get_currency_rate&currency=' + encodeURIComponent(currency))
            .then(response => response.json())
            .then(data => {
                if (data.success && data.rate > 0) {
                    rateInput.value = data.rate;
                    calculateETB();
                }
            })
            .catch(err => console.error('Error fetching currency rate:', err));
    }
}

function calculateETB() {
    const usdField  = document.getElementById('total_fund_usd') || document.querySelector('input[name="total_fund_usd"]');
    const rateField = document.getElementById('currency_rate') || document.querySelector('input[name="currency_rate"]');
    const etbField  = document.getElementById('total_fund_etb');
    if (!usdField || !rateField || !etbField) return;

    const usd  = parseFloat((usdField.value || '').replace(',', '.')) || 0;
    const rate = parseFloat((rateField.value || '').replace(',', '.')) || 0;

    if (usd > 0 && rate > 0) {
        etbField.value = (usd * rate).toFixed(2);
    }
}

function calculateProjectPeriod() {
    const startDate = document.getElementById('start_date');
    const endDate   = document.getElementById('end_date');
    const box       = document.getElementById('periodCalculation');
    const result    = document.getElementById('periodResult');

    if (!startDate || !endDate || !box || !result) return;

    const sVal = startDate.value;
    const eVal = endDate.value;

    if (!sVal || !eVal) {
        box.style.display = 'none';
        result.textContent = '';
        return;
    }

    const start = new Date(sVal);
    const end   = new Date(eVal);

    if (isNaN(start.getTime()) || isNaN(end.getTime()) || end < start) {
        box.style.display = 'block';
        box.style.background = '#ffebee';
        box.style.borderColor = '#f44336';
        result.textContent = '❌ End date must be after start date.';
        return;
    }

    let years  = end.getFullYear() - start.getFullYear();
    let months = end.getMonth() - start.getMonth();

    if (end.getDate() > start.getDate()) {
        months += 1;
    }
    if (months < 0) {
        years--;
        months += 12;
    }

    let totalMonths = years * 12 + months;
    if (totalMonths <= 0) totalMonths = 1;

    let periodText;
    if (totalMonths === 1) {
        periodText = '1 month';
    } else if (totalMonths < 12) {
        periodText = totalMonths + ' months';
    } else {
        const y  = Math.floor(totalMonths / 12);
        const rm = totalMonths % 12;
        if (rm === 0) {
            periodText = y + ' year' + (y > 1 ? 's' : '');
        } else {
            periodText = y + ' year' + (y > 1 ? 's' : '') + ' ' +
                         rm + ' month' + (rm > 1 ? 's' : '');
        }
    }

    box.style.display = 'block';
    box.style.background = '#e8f5e8';
    box.style.borderColor = '#4caf50';
    result.textContent = '⏱️ ' + periodText;
}

// ---------------------------- AI Helper ----------------------------

function askAI() {
    const input = document.getElementById('ai-question');
    const box   = document.getElementById('ai-response');
    if (!input || !box) return;

    const question = input.value.trim();
    box.style.display = 'block';

    if (!question) {
        box.textContent = 'Please type your question first.';
        return;
    }

    box.textContent = 'Thinking...';

    const formData = new FormData();
    formData.append('ai_assistant', '1');
    formData.append('context', 'project_setup');
    formData.append('user_input', question);

    fetch(window.location.href, {
        method: 'POST',
        body: formData,
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
    .then(res => res.json())
    .then(data => {
        if (!data) return;
        const title   = data.title   || 'Suggestion';
        const message = data.message || '';
        box.innerHTML = '<strong>' + title + ':</strong> ' + message;
    })
    .catch(() => {
        box.textContent = 'Sorry, something went wrong while getting a suggestion.';
    });
}

// ---------------------------- Notifications ----------------------------

function initNotifications() {
    const container = document.querySelector('.notification-container');
    if (!container) return;
    const items = container.querySelectorAll('.notification');
    items.forEach((item, index) => {
        setTimeout(() => {
            item.style.transition = 'opacity 0.5s';
            item.style.opacity = '0';
            setTimeout(() => {
                if (item.parentNode) item.parentNode.removeChild(item);
            }, 700);
        }, 4000 + index * 1000);
    });
}

// ---------------------------- Locations (Region → Zone → Woreda) ----------------------------

function resetSelect(selectEl, includeOther = true) {
    if (!selectEl) return;
    selectEl.innerHTML = '';

    const optEmpty = document.createElement('option');
    optEmpty.value = '';
    optEmpty.textContent = '--';
    selectEl.appendChild(optEmpty);

    if (includeOther) {
        const optOther = document.createElement('option');
        optOther.value = 'other';
        optOther.textContent = 'Other (specify)';
        selectEl.appendChild(optOther);
    }
}

function loadZones(regionId, zoneSelect, woredaSelect) {
    if (!zoneSelect) return;
    resetSelect(zoneSelect, true);
    if (woredaSelect) resetSelect(woredaSelect, true);

    if (!regionId || regionId === 'other') return;

    fetch('?ajax=zones&region_id=' + encodeURIComponent(regionId), {
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
        .then(r => r.json())
        .then(json => {
            if (!json || !json.success || !Array.isArray(json.data)) return;
            const beforeOther = zoneSelect.querySelector('option[value="other"]');
            json.data.forEach(z => {
                const opt = document.createElement('option');
                opt.value = z.id;
                opt.textContent = z.name;
                if (beforeOther) {
                    zoneSelect.insertBefore(opt, beforeOther);
                } else {
                    zoneSelect.appendChild(opt);
                }
            });
        })
        .catch(() => {});
}

function loadWoredas(zoneId, woredaSelect) {
    if (!woredaSelect) return;
    resetSelect(woredaSelect, true);

    if (!zoneId || zoneId === 'other') return;

    fetch('?ajax=woredas&zone_id=' + encodeURIComponent(zoneId), {
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
        .then(r => r.json())
        .then(json => {
            if (!json || !json.success || !Array.isArray(json.data)) return;
            const beforeOther = woredaSelect.querySelector('option[value="other"]');
            json.data.forEach(w => {
                const opt = document.createElement('option');
                opt.value = w.id;
                opt.textContent = w.name;
                if (beforeOther) {
                    woredaSelect.insertBefore(opt, beforeOther);
                } else {
                    woredaSelect.appendChild(opt);
                }
            });
        })
        .catch(() => {});
}

function wireLocationRow(rowEl) {
    if (!rowEl) return;

    const regionSelect = rowEl.querySelector('.region-select');
    const zoneSelect   = rowEl.querySelector('.zone-select');
    const woredaSelect = rowEl.querySelector('.woreda-select');

    const regionOther  = rowEl.querySelector('.region-other-input');
    const zoneOther    = rowEl.querySelector('.zone-other-input');
    const woredaOther  = rowEl.querySelector('.woreda-other-input');

    if (!regionSelect || !zoneSelect || !woredaSelect) return;

    regionSelect.addEventListener('change', function () {
        const val = this.value;

        if (val === 'other') {
            if (regionOther) {
                regionOther.style.display = 'block';
                regionOther.value = '';
            }
            resetSelect(zoneSelect, true);
            resetSelect(woredaSelect, true);
            if (zoneOther)   { zoneOther.style.display = 'none';   zoneOther.value   = ''; }
            if (woredaOther) { woredaOther.style.display = 'none'; woredaOther.value = ''; }
        } else if (!val) {
            if (regionOther) { regionOther.style.display = 'none'; regionOther.value = ''; }
            resetSelect(zoneSelect, true);
            resetSelect(woredaSelect, true);
            if (zoneOther)   { zoneOther.style.display = 'none';   zoneOther.value   = ''; }
            if (woredaOther) { woredaOther.style.display = 'none'; woredaOther.value = ''; }
        } else {
            if (regionOther) { regionOther.style.display = 'none'; }
            loadZones(val, zoneSelect, woredaSelect);
        }
    });

    zoneSelect.addEventListener('change', function () {
        const val = this.value;

        if (val === 'other') {
            if (zoneOther) {
                zoneOther.style.display = 'block';
                zoneOther.value = '';
            }
            resetSelect(woredaSelect, true);
            if (woredaOther) { woredaOther.style.display = 'none'; woredaOther.value = ''; }
        } else if (!val) {
            if (zoneOther)   { zoneOther.style.display = 'none';   zoneOther.value   = ''; }
            resetSelect(woredaSelect, true);
            if (woredaOther) { woredaOther.style.display = 'none'; woredaOther.value = ''; }
        } else {
            if (zoneOther) { zoneOther.style.display = 'none'; }
            loadWoredas(val, woredaSelect);
        }
    });

    woredaSelect.addEventListener('change', function () {
        const val = this.value;
        if (val === 'other') {
            if (woredaOther) {
                woredaOther.style.display = 'block';
                woredaOther.value = '';
            }
        } else {
            if (woredaOther) {
                woredaOther.style.display = 'none';
                woredaOther.value = '';
            }
        }
    });
}

function addLocationRow() {
    const container = document.getElementById('additional-locations');
    if (!container) return;

    const index = container.querySelectorAll('.location-row-extra').length;

    const row = document.createElement('div');
    row.className = 'form-row location-row location-row-extra';
    row.style.marginTop = '10px';

    row.innerHTML = `
        <label>Region
            <select name="locations[${index}][region_id]" class="region-select">
                <option value="">--</option>
                ${REGION_OPTIONS_HTML}
            </select>
            <input type="text"
                   name="locations[${index}][region_other]"
                   class="other-input region-other-input"
                   style="display:none;margin-top:4px;"
                   placeholder="Specify region">
        </label>

        <label>Zone
            <select name="locations[${index}][zone_id]" class="zone-select">
                <option value="">--</option>
                <option value="other">Other (specify)</option>
            </select>
            <input type="text"
                   name="locations[${index}][zone_other]"
                   class="other-input zone-other-input"
                   style="display:none;margin-top:4px;"
                   placeholder="Specify zone">
        </label>

        <label>Woreda
            <select name="locations[${index}][woreda_id]" class="woreda-select">
                <option value="">--</option>
                <option value="other">Other (specify)</option>
            </select>
            <input type="text"
                   name="locations[${index}][woreda_other]"
                   class="other-input woreda-other-input"
                   style="display:none;margin-top:4px;"
                   placeholder="Specify woreda">
        </label>
    `;

    container.appendChild(row);
    wireLocationRow(row);
}

// ---------------------------- Dropdown (Download) ----------------------------

function initDropdowns() {
    const dropdowns = document.querySelectorAll('.dropdown');
    dropdowns.forEach(drop => {
        const btn = drop.querySelector('.dropdown-toggle');
        const content = drop.querySelector('.dropdown-content');
        if (!btn || !content) return;

        btn.addEventListener('click', function (e) {
            e.stopPropagation();
            const isVisible = content.style.display === 'block';
            document.querySelectorAll('.dropdown-content').forEach(c => c.style.display = 'none');
            content.style.display = isVisible ? 'none' : 'block';
        });
    });

    document.addEventListener('click', function (e) {
        if (!e.target.closest('.dropdown')) {
            document.querySelectorAll('.dropdown-content').forEach(c => c.style.display = 'none');
        }
    });
}

// ---------------------------- Quick-edit helper (Edit button) ----------------------------

function initQuickEditMapping() {
    const forms = document.querySelectorAll('form[id^="project-inline-form-"]');
    forms.forEach(form => {
        const id = form.id.replace('project-inline-form-', '');
        const inputInRow = document.querySelector('tr input[form="' + form.id + '"]');
        if (inputInRow) {
            const tr = inputInRow.closest('tr');
            if (tr) {
                tr.dataset.projectId = id;
            }
        }
    });
}

function editProject(projectId) {
    const row = document.querySelector('tr[data-project-id="' + projectId + '"]');
    if (row) {
        row.scrollIntoView({ behavior: 'smooth', block: 'center' });
        row.style.outline = '2px solid #007bff';
        setTimeout(() => { row.style.outline = 'none'; }, 2000);
    } else {
        // Fallback: scroll to quick-edit table section
        const tableHeader = document.querySelector('h2:nth-of-type(3)');
        if (tableHeader) {
            tableHeader.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
    }
}

function printProject(projectId) {
    window.open('?action=export_single_project_pdf&project_id=' + encodeURIComponent(projectId), '_blank');
}

// ---------------------------- Charts ----------------------------

function createChart(ctxId, type, labels, data, label) {
    const ctx = document.getElementById(ctxId);
    if (!ctx || typeof Chart === 'undefined') return;
    new Chart(ctx, {
        type: type,
        data: {
            labels: labels,
            datasets: [{
                label: label,
                data: data
            }]
        },
        options: {
            responsive: true,
            plugins: {
                legend: { display: type !== 'bar' || labels.length <= 8 }
            },
            scales: (type === 'bar' || type === 'line') ? {
                y: { beginAtZero: true }
            } : {}
        }
    });
}

function initCharts() {
    if (typeof Chart === 'undefined') return;

    // By status
    const s = analyticsData.status || [];
    const statusLabels = s.map(x => x.status || 'Unknown');
    const statusCounts = s.map(x => Number(x.count || 0));

    createChart('chartStatus', 'pie', statusLabels, statusCounts, 'Projects by status');
    createChart('statusChart', 'bar', statusLabels, statusCounts, 'Projects by status');

    // By project type (counts)
    const pt = analyticsData.project_type || [];
    const typeLabels = pt.map(x => x.project_type || 'N/A');
    const typeCounts = pt.map(x => Number(x.count || 0));

    createChart('chartProjectType', 'pie', typeLabels, typeCounts, 'Projects by type');
    createChart('projectTypeChart', 'bar', typeLabels, typeCounts, 'Projects by type');

    // Projects by region
    const r = analyticsData.region || [];
    const regionLabels = r.map(x => x.region || 'N/A');
    const regionCounts = r.map(x => Number(x.count || 0));
    createChart('regionChart', 'bar', regionLabels, regionCounts, 'Projects by region');

    // Donors by funding
    const d = analyticsData.donor || [];
    const donorLabels = d.map(x => x.donor || 'N/A');
    const donorFunding = d.map(x => Number(x.total_funding || 0));
    createChart('donorChart', 'bar', donorLabels, donorFunding, 'Total funding (USD)');

    // Projects by period (duration)
    const p = analyticsData.period || {};
    const periodLabels = Object.keys(p);
    const periodCounts = periodLabels.map(k => Number(p[k] || 0));
    createChart('periodChart', 'bar', periodLabels, periodCounts, 'Projects by duration');

    // Funding by project type (USD)
    const fbt = analyticsData.funding_by_type || [];
    const fbtLabels = fbt.map(x => x.project_type || 'N/A');
    const fbtFunding = fbt.map(x => Number(x.total_funding || 0));
    createChart('fundingByTypeChart', 'bar', fbtLabels, fbtFunding, 'Funding by project type (USD)');

    // Projects by implementing partner (count)
    const ip = analyticsData.ip || [];
    const ipLabels = ip.map(x => x.ip || 'N/A');
    const ipCounts = ip.map(x => Number(x.count || 0));
    createChart('ipChart', 'bar', ipLabels, ipCounts, 'Projects by IP');

    // Funding by sector
    const sec = analyticsData.sector || [];
    const sectorLabels = sec.map(x => x.main_sectors || 'N/A');
    const sectorFunding = sec.map(x => Number(x.total_funding || 0));
    createChart('sectorChart', 'bar', sectorLabels, sectorFunding, 'Funding by main sector');
}

// ---------------------------- Init on DOM Ready ----------------------------

document.addEventListener('DOMContentLoaded', function () {
    initNotifications();
    initDropdowns();
    initQuickEditMapping();
    initCharts();
    
    // Initialize donor and currency handlers
    handleDonorSelection();
    handleCurrencySelection();

    // Wire primary location row
    const primaryRow = document.querySelector('.primary-location-row');
    if (primaryRow) {
        wireLocationRow(primaryRow);
    }
});
</script>

</script>

<?php require_once __DIR__ . '/../footer.php'; ?>