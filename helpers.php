<?php 
/**
 * helpers.php - ENHANCED & UPGRADED Common Helper Functions for SMART Nexus Platform
 * Cross-App Communication, Enhanced Printing, Profile Management, and More
 * VERSION: 2.6 - SMART Nexus Enhanced (safer with header.php & new apps, APP_* constants)
 */

// Start session once, globally with proper persistence settings
if (session_status() === PHP_SESSION_NONE) {
    // Configure session for persistence (8 hours)
    ini_set('session.cookie_lifetime', 28800); // 8 hours in seconds
    ini_set('session.gc_maxlifetime', 28800); // 8 hours
    ini_set('session.cookie_httponly', 1);
    ini_set('session.cookie_samesite', 'Lax');
    
    // Set session cookie parameters for persistence
    session_set_cookie_params([
        'lifetime' => 28800, // 8 hours
        'path' => '/',
        'domain' => '',
        'secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on',
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
    
    session_start();
    
    // Regenerate session ID periodically for security (every 30 minutes)
    if (!isset($_SESSION['last_regeneration'])) {
        $_SESSION['last_regeneration'] = time();
    } elseif (time() - $_SESSION['last_regeneration'] > 1800) {
        session_regenerate_id(true);
        $_SESSION['last_regeneration'] = time();
    }
}

// Load centralized configuration (DB/Base URL/Cross-App settings) once
if (file_exists(__DIR__ . '/config.php')) {
    require_once __DIR__ . '/config.php';
}

/**
 * Global app metadata constants – used by footer, header, system status, etc.
 * Update APP_VERSION when you upgrade (e.g. '2.5', '3.0') and the footer will auto-update.
 */
if (!defined('APP_NAME')) {
    // You can change this to any global title you like
    define('APP_NAME', 'Nexus Ethiopia – Project Performance Report');
}
if (!defined('APP_VERSION')) {
    define('APP_VERSION', '3.1');
}
if (!defined('APP_ENV')) {
    // You can override via server environment variable APP_ENV if needed
    $env = getenv('APP_ENV');
    define('APP_ENV', $env ?: 'production'); // e.g. 'production', 'staging', 'local'
}

// Define BASE_URL if not defined
if (!defined('BASE_URL')) {
    $protocol    = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
    $host        = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $scriptPath  = dirname($_SERVER['SCRIPT_NAME'] ?? '');
    define('BASE_URL', $protocol . '://' . $host . $scriptPath);
}

require_once __DIR__ . '/db.php';

// NEW: Enhanced Cross-App Communication Constants
if (!defined('CROSS_APP_SESSION_KEY')) {
    define('CROSS_APP_SESSION_KEY', 'cross_app_data_v2');
}
if (!defined('PRINT_CONFIG_KEY')) {
    define('PRINT_CONFIG_KEY', 'print_export_config_v2');
}
if (!defined('VISIBILITY_CONFIG_KEY')) {
    define('VISIBILITY_CONFIG_KEY', 'column_visibility_v2');
}
if (!defined('PROFILE_PHOTO_KEY')) {
    define('PROFILE_PHOTO_KEY', 'profile_photo_cache');
}

/**
 * Enhanced Cross-App Communication System
 */
if (!class_exists('CrossAppCommunicator')) {
    class CrossAppCommunicator {
        private static $instance = null;
        private $sharedData = [];
        private $eventListeners = [];
        
        public static function getInstance() {
            if (self::$instance === null) {
                self::$instance = new self();
                self::$instance->loadFromSession();
            }
            return self::$instance;
        }
        
        private function loadFromSession() {
            $this->sharedData = $_SESSION[CROSS_APP_SESSION_KEY] ?? [];
            
            // Initialize default structure if empty
            if (empty($this->sharedData)) {
                $this->sharedData = [
                    'projects'     => [],
                    'session'      => [],
                    'cfm'          => [],
                    'ui_settings'  => [],
                    'ai'           => [],
                    'debug'        => [],
                    'global'       => [],
                    'messages'     => [],
                    'shared_data'  => [],
                    'system'       => [],
                    'geography'    => [],
                ];
            }
        }
        
        private function saveToSession() {
            $_SESSION[CROSS_APP_SESSION_KEY] = $this->sharedData;
        }
        
        public function setData($key, $value, $app = 'global') {
            if (!isset($this->sharedData[$app])) {
                $this->sharedData[$app] = [];
            }
            $this->sharedData[$app][$key] = $value;
            $this->saveToSession();
            
            // Trigger event
            $this->emit('dataChanged', [
                'app'       => $app,
                'key'       => $key,
                'value'     => $value,
                'timestamp' => date('Y-m-d H:i:s')
            ]);
            
            return true;
        }
        
        public function getData($key, $app = 'global', $default = null) {
            return $this->sharedData[$app][$key] ?? $default;
        }
        
        public function getAllAppData($app = 'global') {
            return $this->sharedData[$app] ?? [];
        }
        
        public function on($event, callable $callback) {
            if (!isset($this->eventListeners[$event])) {
                $this->eventListeners[$event] = [];
            }
            $this->eventListeners[$event][] = $callback;
        }
        
        public function emit($event, $data = null) {
            if (isset($this->eventListeners[$event])) {
                foreach ($this->eventListeners[$event] as $callback) {
                    call_user_func($callback, $data);
                }
            }
        }
        
        // NEW: Enhanced Project synchronization
        public function syncProject($projectId, $projectData) {
            $this->setData('current_project', $projectData, 'projects');
            $this->setData('last_sync', date('Y-m-d H:i:s'), 'projects');
            $this->setData('active_project_id', $projectId, 'global');
            
            // Notify all apps about project change
            $this->emit('projectChanged', [
                'projectId'   => $projectId,
                'projectData' => $projectData,
                'timestamp'   => date('Y-m-d H:i:s')
            ]);
            
            return true;
        }
        
        // NEW: Enhanced User session synchronization
        public function syncUserSession($userData) {
            $this->setData('current_user', $userData, 'session');
            $this->setData('last_activity', time(), 'session');
            $this->setData('user_role', $userData['role'] ?? 'user', 'global');
            
            // Sync profile photo
            if (!empty($userData['profile_photo']) || !empty($userData['photo_path'])) {
                $this->setData(
                    'profile_photo',
                    $userData['profile_photo'] ?? $userData['photo_path'] ?? '',
                    'user_' . ($userData['id'] ?? '0')
                );
            }
        }
        
        // NEW: App-to-app direct messaging
        public function sendMessage($fromApp, $toApp, $message, $data = []) {
            $messageId   = uniqid('msg_');
            $messageData = [
                'id'        => $messageId,
                'from'      => $fromApp,
                'to'        => $toApp,
                'message'   => $message,
                'data'      => $data,
                'timestamp' => date('Y-m-d H:i:s'),
                'read'      => false
            ];
            
            $this->setData($messageId, $messageData, 'messages');
            $this->emit('messageSent', $messageData);
            
            return $messageId;
        }
        
        // NEW: Get unread messages for app
        public function getUnreadMessages($app) {
            $allMessages = $this->getAllAppData('messages');
            $unread      = [];
            
            foreach ($allMessages as $messageId => $message) {
                if (
                    is_array($message) &&
                    isset($message['to']) &&
                    $message['to'] === $app &&
                    empty($message['read'])
                ) {
                    $unread[$messageId] = $message;
                }
            }
            
            return $unread;
        }
        
        // NEW: Enhanced data persistence to database
        public function persistToDatabase($key, $value, $app = 'global') {
            try {
                $pdo = getPDO();
                
                // Ensure cross_app_data table exists
                $pdo->exec("
                    CREATE TABLE IF NOT EXISTS cross_app_data (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        app_name VARCHAR(100) NOT NULL,
                        data_key VARCHAR(255) NOT NULL,
                        data_value TEXT,
                        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                        UNIQUE KEY unique_app_key (app_name, data_key),
                        INDEX idx_app (app_name),
                        INDEX idx_key (data_key)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
                ");
                
                // Store in database
                $stmt = $pdo->prepare("
                    INSERT INTO cross_app_data (app_name, data_key, data_value)
                    VALUES (?, ?, ?)
                    ON DUPLICATE KEY UPDATE
                        data_value = VALUES(data_value),
                        updated_at = CURRENT_TIMESTAMP
                ");
                
                $jsonValue = is_array($value) || is_object($value) ? json_encode($value) : $value;
                $stmt->execute([$app, $key, $jsonValue]);
                
                return true;
            } catch (Exception $e) {
                error_log("CrossApp persist error: " . $e->getMessage());
                return false;
            }
        }
        
        // NEW: Load data from database
        public function loadFromDatabase($app = 'global', $key = null) {
            try {
                $pdo = getPDO();
                
                if ($key) {
                    $stmt = $pdo->prepare("
                        SELECT data_value FROM cross_app_data
                        WHERE app_name = ? AND data_key = ?
                        ORDER BY updated_at DESC LIMIT 1
                    ");
                    if ($stmt && $stmt->execute([$app, $key])) {
                        $row = $stmt->fetch(PDO::FETCH_ASSOC);
                        if ($row) {
                            $value = json_decode($row['data_value'], true);
                            return $value !== null ? $value : $row['data_value'];
                        }
                    }
                } else {
                    $stmt = $pdo->prepare("
                        SELECT data_key, data_value FROM cross_app_data
                        WHERE app_name = ?
                        ORDER BY updated_at DESC
                    ");
                    if ($stmt && $stmt->execute([$app])) {
                        $data = [];
                        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                            $value = json_decode($row['data_value'], true);
                            $data[$row['data_key']] = $value !== null ? $value : $row['data_value'];
                        }
                        return $data;
                    }
                }
            } catch (Exception $e) {
                error_log("CrossApp load error: " . $e->getMessage());
            }
            return null;
        }
        
        // NEW: Auto-sync with database
        public function setDataWithSync($key, $value, $app = 'global', $persist = true) {
            $this->setData($key, $value, $app);
            
            if ($persist) {
                $this->persistToDatabase($key, $value, $app);
            }
            
            return true;
        }
        
        // NEW: Real-time sync trigger
        public function triggerSync($app = 'global') {
            $this->emit('syncTriggered', [
                'app' => $app,
                'timestamp' => date('Y-m-d H:i:s')
            ]);
            
            // Broadcast to all connected clients via events
            $events = $this->getData('events', 'system', []);
            $eventId = time();
            $events[$eventId] = [
                'type' => 'sync',
                'app' => $app,
                'timestamp' => date('Y-m-d H:i:s')
            ];
            $this->setData('events', $events, 'system');
            
            return true;
        }
        
        // NEW: Bulk data sync
        public function bulkSync($data, $app = 'global') {
            foreach ($data as $key => $value) {
                $this->setDataWithSync($key, $value, $app);
            }
            
            $this->triggerSync($app);
            return true;
        }
        
        // NEW: Get sync status
        public function getSyncStatus() {
            return [
                'last_sync' => $this->getData('last_sync', 'global'),
                'active_apps' => array_keys($this->sharedData),
                'total_keys' => array_sum(array_map('count', $this->sharedData)),
                'database_sync' => 'enabled'
            ];
        }
    }
}

/**
 * Enhanced Print & Export Management System
 */
if (!class_exists('PrintExportManager')) {
    class PrintExportManager {
        private static $instance = null;
        private $config = [];
        
        public static function getInstance() {
            if (self::$instance === null) {
                self::$instance = new self();
                self::$instance->loadConfig();
            }
            return self::$instance;
        }
        
        private function loadConfig() {
            $this->config = $_SESSION[PRINT_CONFIG_KEY] ?? [
                'default_title'       => 'SMART Nexus Report',
                'default_subtitle'    => 'Generated on ' . date('Y-m-d H:i:s'),
                'include_header'      => true,
                'include_project_info'=> true,
                'orientation'         => 'portrait',
                'paper_size'          => 'A4',
                'show_page_numbers'   => true,
                'include_footer'      => true,
                'current_print'       => null
            ];
        }
        
        private function saveConfig() {
            $_SESSION[PRINT_CONFIG_KEY] = $this->config;
        }
        
        private function getCurrentAppName() {
            $scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
            $appName    = pathinfo($scriptName, PATHINFO_FILENAME);
            $appNames   = [
                'index'           => 'Dashboard',
                'projects'        => 'Projects',
                'planning'        => 'Planning',
                'budget'          => 'Budget',
                'geography'       => 'Geography',
                'indicators'      => 'Indicators',
                'enter_data'      => 'Data Entry',
                'view_reports'    => 'Reports',
                'custom_report'   => 'Custom Reports',
                'aggregation'     => 'Aggregation',
                'pivot'           => 'Pivot Tables',
                'progress'        => 'Progress Dashboard',
                'cfm'             => 'Complaint Feedback',
                'messages'        => 'Messaging',
                'users'           => 'User Management',
                'nexus_ai'        => 'Nexus AI',
                'data_quality'    => 'Data Quality'
            ];
            
            return $appNames[$appName] ?? ucfirst(str_replace('_', ' ', $appName));
        }
        
        private function generatePageTitle($title) {
            $crossApp       = CrossAppCommunicator::getInstance();
            $currentProject = $crossApp->getData('current_project', 'projects');
            $projectTitle   = $currentProject['title'] ?? 'All Projects';
            
            return "{$title} - {$projectTitle} - SMART Nexus";
        }
        
        public function setupPrint($title, $subtitle = null, $projectInfo = null, $customHeaders = []) {
            $crossApp       = CrossAppCommunicator::getInstance();
            $currentProject = $crossApp->getData('current_project', 'projects');
            
            $this->config['current_print'] = [
                'title'         => $title,
                'subtitle'      => $subtitle ?? 'Generated on ' . date('Y-m-d H:i:s'),
                'project_info'  => $projectInfo ?? $currentProject,
                'custom_headers'=> $customHeaders,
                'generated_at'  => date('Y-m-d H:i:s'),
                'generated_by'  => $_SESSION['user_id'] ?? 'system',
                'app_name'      => $this->getCurrentAppName(),
                'page_title'    => $this->generatePageTitle($title)
            ];
            
            $this->saveConfig();
            
            // Share with cross-app system
            $crossApp->setData('print_config', $this->config['current_print'], 'printing');
            
            return $this->config['current_print'];
        }
        
        public function getPrintConfig() {
            return $this->config['current_print'] ?? $this->config;
        }
        
        public function generatePrintHeader() {
            $config         = $this->getPrintConfig();
            $crossApp       = CrossAppCommunicator::getInstance();
            $currentProject = $crossApp->getData('current_project', 'projects');
            
            $header = "
            <div class='print-header' style='text-align: center; margin-bottom: 20px; padding: 15px; border-bottom: 2px solid #333; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; border-radius: 8px;'>
                <h1 style='font-size: 24px; font-weight: bold; margin-bottom: 5px; color: white;'>" . h($config['title'] ?? 'SMART Nexus Report') . "</h1>
                <p style='font-size: 16px; margin-bottom: 10px; opacity: 0.9;'>" . h($config['subtitle'] ?? '') . "</p>";
            
            if ($currentProject && ($this->config['include_project_info'] ?? true)) {
                $header .= "
                <div style='background: rgba(255,255,255,0.2); padding: 10px; border-radius: 4px; margin: 10px 0; text-align: left;'>
                    <strong>Project:</strong> " . h($currentProject['title'] ?? 'All Projects') . "
                    " . (isset($currentProject['description']) ? "<br><strong>Description:</strong> " . h($currentProject['description']) : "") . "
                    " . (isset($currentProject['code']) ? "<br><strong>Code:</strong> " . h($currentProject['code']) : "") . "
                    " . (isset($currentProject['location']) ? "<br><strong>Location:</strong> " . h($currentProject['location']) : "") . "
                </div>";
            }
            
            $header .= "
                <div style='font-size: 12px; margin-top: 10px; padding-top: 10px; border-top: 1px solid rgba(255,255,255,0.3);'>
                    <strong>App:</strong> " . h($config['app_name'] ?? 'SMART Nexus') . " | 
                    <strong>Generated By:</strong> " . h($config['generated_by'] ?? 'System') . "
                </div>
            </div>";
            
            return $header;
        }
        
        public function generatePrintFooter() {
            $config = $this->getPrintConfig();
            
            return "
            <div class='print-footer' style='text-align: center; margin-top: 30px; padding: 15px; border-top: 1px solid #ddd; font-size: 12px; color: #666;'>
                <p>SMART Nexus Platform - " . h($config['app_name'] ?? 'Report') . " | Generated on " . h($config['generated_at'] ?? date('Y-m-d H:i:s')) . "</p>
                <p>Page <span class='page-number'></span> of <span class='total-pages'></span></p>
            </div>";
        }
        
        public function exportToExcel($data, $filename = 'export.xlsx', $headers = []) {
            $printConfig = $this->getPrintConfig();
            
            // Set headers for Excel
            if (!headers_sent()) {
                header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
                header('Content-Disposition: attachment; filename="' . $filename . '"');
                header('Cache-Control: max-age=0');
            }
            
            // Simple CSV-style output as fallback (Excel will open it)
            $output = fopen('php://output', 'w');
            
            // Add header information as comments
            fputcsv($output, ['# ' . ($printConfig['title'] ?? 'SMART Nexus Report')]);
            fputcsv($output, ['# ' . ($printConfig['subtitle'] ?? 'Generated on ' . date('Y-m-d H:i:s'))]);
            if (isset($printConfig['project_info']['title'])) {
                fputcsv($output, ['# Project: ' . $printConfig['project_info']['title']]);
            }
            fputcsv($output, ['# Generated: ' . ($printConfig['generated_at'] ?? date('Y-m-d H:i:s'))]);
            fputcsv($output, ['#']);
            
            // Add data headers
            if (!empty($headers)) {
                fputcsv($output, $headers);
            } elseif (!empty($data) && is_array($data[0] ?? null)) {
                fputcsv($output, array_keys($data[0]));
            }
            
            // Add data rows
            foreach ($data as $row) {
                fputcsv($output, $row);
            }
            
            fclose($output);
            exit;
        }
        
        public function exportToCSV($data, $filename = 'export.csv', $headers = []) {
            $printConfig = $this->getPrintConfig();
            
            if (!headers_sent()) {
                header('Content-Type: text/csv; charset=utf-8');
                header('Content-Disposition: attachment; filename="' . $filename . '"');
            }
            
            $output = fopen('php://output', 'w');
            
            // Add BOM for UTF-8
            fputs($output, "\xEF\xBB\xBF");
            
            // Add header comment
            fputcsv($output, ['# ' . ($printConfig['title'] ?? 'SMART Nexus Report')]);
            fputcsv($output, ['# ' . ($printConfig['subtitle'] ?? 'Generated on ' . date('Y-m-d H:i:s'))]);
            if (isset($printConfig['project_info']['title'])) {
                fputcsv($output, ['# Project: ' . $printConfig['project_info']['title']]);
            }
            fputcsv($output, ['# Generated: ' . ($printConfig['generated_at'] ?? date('Y-m-d H:i:s'))]);
            fputcsv($output, ['#']); // Empty line
            
            // Add data headers
            if (!empty($headers)) {
                fputcsv($output, $headers);
            } elseif (!empty($data) && is_array($data[0] ?? null)) {
                fputcsv($output, array_keys($data[0]));
            }
            
            // Add data rows
            foreach ($data as $row) {
                fputcsv($output, $row);
            }
            
            fclose($output);
            exit;
        }
        
        public function exportToPDF($htmlContent, $filename = 'export.pdf') {
            // This would integrate with a PDF library like TCPDF or Dompdf
            // For now, we'll return the HTML with print styles
            $printConfig = $this->getPrintConfig();
            
            $fullHtml = "
            <!DOCTYPE html>
            <html>
            <head>
                <title>" . h($printConfig['page_title'] ?? 'SMART Nexus Export') . "</title>
                <style>
                    body { font-family: Arial, sans-serif; margin: 20px; }
                    .print-header { text-align: center; margin-bottom: 30px; padding: 20px; border-bottom: 2px solid #333; }
                    .print-footer { text-align: center; margin-top: 30px; padding: 15px; border-top: 1px solid #ddd; font-size: 12px; color: #666; }
                    table { width: 100%; border-collapse: collapse; margin: 15px 0; }
                    table th, table td { border: 1px solid #ddd; padding: 8px; text-align: left; }
                    table th { background-color: #f8f9fa; font-weight: bold; }
                </style>
            </head>
            <body>
                " . $this->generatePrintHeader() . "
                " . $htmlContent . "
                " . $this->generatePrintFooter() . "
            </body>
            </html>";
            
            echo $fullHtml;
            exit;
        }
    }
}

/**
 * Enhanced Profile Photo Management with Caching
 */
if (!class_exists('ProfilePhotoManager')) {
    class ProfilePhotoManager {
        private static $instance = null;
        
        public static function getInstance() {
            if (self::$instance === null) {
                self::$instance = new self();
            }
            return self::$instance;
        }
        
        public function updateUserPhoto($userId, $photoPath) {
            $pdo = getPDO();
            
            // Update all possible photo columns
            $photoColumns = ['profile_photo', 'photo_path', 'photo', 'avatar', 'profile_picture'];
            $updated      = false;
            
            foreach ($photoColumns as $column) {
                try {
                    // Check if column exists
                    $stmt = $pdo->prepare("SHOW COLUMNS FROM users LIKE ?");
                    if ($stmt && $stmt->execute([$column]) && $stmt->fetch()) {
                        $updateStmt = $pdo->prepare("UPDATE users SET $column = ? WHERE id = ?");
                        if ($updateStmt) {
                            $updateStmt->execute([$photoPath, $userId]);
                            $updated = true;
                        }
                    }
                } catch (Exception $e) {
                    // Column might not exist, continue to next
                    error_log("Photo column $column update error: " . $e->getMessage());
                    continue;
                }
            }
            
            if ($updated) {
                // Update session with cache busting
                if (isset($_SESSION['user_id']) && $_SESSION['user_id'] == $userId) {
                    $_SESSION['user_photo']   = $photoPath;
                    $_SESSION['photo_updated']= time();
                    $_SESSION['photo_cache']  = $photoPath . '?t=' . time();
                }
                
                // Update cross-app data
                $crossApp = CrossAppCommunicator::getInstance();
                $crossApp->setData('profile_photo', $photoPath, 'user_' . $userId);
                $crossApp->setData('photo_updated', time(), 'user_' . $userId);
                
                // Update profile photo cache
                if (!isset($_SESSION[PROFILE_PHOTO_KEY])) {
                    $_SESSION[PROFILE_PHOTO_KEY] = [];
                }
                $_SESSION[PROFILE_PHOTO_KEY][$userId] = [
                    'path'      => $photoPath,
                    'timestamp' => time(),
                    'cache_url' => $photoPath . '?t=' . time()
                ];
                
                return true;
            }
            
            return false;
        }
        
        public function getPhotoUrl($userData, $baseUrl = '') {
            $userId = $userData['id'] ?? null;

            // Ensure cache array exists
            if (!isset($_SESSION[PROFILE_PHOTO_KEY])) {
                $_SESSION[PROFILE_PHOTO_KEY] = [];
            }

            // 1) Check strong cache first (per user)
            if ($userId && isset($_SESSION[PROFILE_PHOTO_KEY][$userId])) {
                $cache = $_SESSION[PROFILE_PHOTO_KEY][$userId];
                if (!empty($cache['path']) && isset($cache['timestamp']) && (time() - $cache['timestamp'] < 3600)) {
                    return $this->buildPhotoUrl($cache['path'], $baseUrl, $cache['timestamp']);
                }
            }

            // 2) Check session-level fallback (fixes "photo lost on reload" issue)
            if ($userId && !empty($_SESSION['user_photo'])) {
                $raw       = $_SESSION['user_photo'];
                $timestamp = $_SESSION['photo_updated'] ?? time();

                $_SESSION[PROFILE_PHOTO_KEY][$userId] = [
                    'path'      => $raw,
                    'timestamp' => $timestamp,
                    'cache_url' => $this->buildPhotoUrl($raw, $baseUrl, $timestamp)
                ];

                return $this->buildPhotoUrl($raw, $baseUrl, $timestamp);
            }

            // 3) Check cross-app cache (if header / other apps updated it)
            if ($userId) {
                $crossApp   = CrossAppCommunicator::getInstance();
                $crossPhoto = $crossApp->getData('profile_photo', 'user_' . $userId);
                if (!empty($crossPhoto)) {
                    $timestamp = $crossApp->getData('photo_updated', 'user_' . $userId) ?? time();
                    $_SESSION[PROFILE_PHOTO_KEY][$userId] = [
                        'path'      => $crossPhoto,
                        'timestamp' => $timestamp,
                        'cache_url' => $this->buildPhotoUrl($crossPhoto, $baseUrl, $timestamp)
                    ];
                    return $this->buildPhotoUrl($crossPhoto, $baseUrl, $timestamp);
                }
            }
            
            // 4) Fallback: read from user record columns
            $possibleColumns = ['profile_photo', 'photo_path', 'photo', 'avatar', 'profile_picture'];
            
            foreach ($possibleColumns as $column) {
                if (!empty($userData[$column])) {
                    $raw       = $userData[$column];
                    $timestamp = $_SESSION['photo_updated'] ?? time();
                    
                    if ($userId) {
                        $_SESSION[PROFILE_PHOTO_KEY][$userId] = [
                            'path'      => $raw,
                            'timestamp' => $timestamp,
                            'cache_url' => $this->buildPhotoUrl($raw, $baseUrl, $timestamp)
                        ];
                    }
                    
                    return $this->buildPhotoUrl($raw, $baseUrl, $timestamp);
                }
            }
            
            return null;
        }
        
        private function buildPhotoUrl($rawPath, $baseUrl, $timestamp) {
            // If already absolute URL, return as is with cache busting
            if (preg_match('~^https?://~i', $rawPath) || (function_exists('str_starts_with') && str_starts_with($rawPath, '/'))) {
                return $rawPath . '?t=' . $timestamp;
            }
            
            // Relative path - construct full URL with cache busting
            $fullPath = rtrim($baseUrl ?: BASE_URL, '/') . '/' . ltrim($rawPath, '/');
            return $fullPath . '?t=' . $timestamp;
        }
        
        public function handlePhotoUpload($userId, $fileInputName = 'profile_photo') {
            if (empty($_FILES[$fileInputName]) || $_FILES[$fileInputName]['error'] !== UPLOAD_ERR_OK) {
                return ['success' => false, 'error' => 'No file uploaded or upload error'];
            }
            
            $file         = $_FILES[$fileInputName];
            $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
            $maxSize      = 5 * 1024 * 1024; // 5MB
            
            if (!in_array($file['type'], $allowedTypes)) {
                return ['success' => false, 'error' => 'Invalid file type. Only JPG, PNG, GIF, and WebP are allowed.'];
            }
            
            if ($file['size'] > $maxSize) {
                return ['success' => false, 'error' => 'File too large. Maximum size is 5MB.'];
            }
            
            // Validate image
            $imageInfo = getimagesize($file['tmp_name']);
            if (!$imageInfo) {
                return ['success' => false, 'error' => 'Invalid image file.'];
            }
            
            // Create uploads directory if not exists
            $uploadDir = $_SERVER['DOCUMENT_ROOT'] . '/uploads/profiles/';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }
            
            // Generate unique filename
            $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
            $filename  = 'profile_' . $userId . '_' . time() . '.' . $extension;
            $filePath  = $uploadDir . $filename;
            
            if (move_uploaded_file($file['tmp_name'], $filePath)) {
                // Update database
                $relativePath = 'uploads/profiles/' . $filename;
                if ($this->updateUserPhoto($userId, $relativePath)) {
                    return [
                        'success'  => true,
                        'path'     => $relativePath,
                        'full_url' => rtrim(BASE_URL, '/') . '/' . $relativePath . '?t=' . time(),
                        'filename' => $filename
                    ];
                }
            }
            
            return ['success' => false, 'error' => 'Failed to save uploaded file'];
        }
        
        public function getDefaultPhotoUrl($userData, $baseUrl = '') {
            $initials = $this->getUserInitials($userData);
            $colors   = ['#4361ee', '#3f37c9', '#4cc9f0', '#f72585', '#4895ef'];
            $key      = $userData['email'] ?? ($userData['id'] ?? '');
            $colorIdx = $key !== '' ? (crc32($key) % count($colors)) : 0;
            $color    = $colors[$colorIdx];
            
            // Return data URL for colored initial avatar
            $svg = '<svg width="100" height="100" xmlns="http://www.w3.org/2000/svg">
                <rect width="100" height="100" fill="' . $color . '" rx="50"/>
                <text x="50" y="50" text-anchor="middle" dy=".35em" fill="white" font-family="Arial, sans-serif" font-size="40">' . $initials . '</text>
            </svg>';
            
            return 'data:image/svg+xml;base64,' . base64_encode($svg);
        }
        
        private function getUserInitials($userData) {
            $name  = $userData['full_name'] ?? $userData['name'] ?? $userData['email'] ?? 'User';
            $names = explode(' ', $name);
            $initials = '';
            
            foreach ($names as $part) {
                if (!empty(trim($part))) {
                    $initials .= strtoupper(substr(trim($part), 0, 1));
                }
                if (strlen($initials) >= 2) break;
            }
            
            return $initials ?: 'U';
        }
    }
}

/**
 * Enhanced Column Visibility Management
 */
if (!class_exists('VisibilityManager')) {
    class VisibilityManager {
        private static $instance = null;
        
        public static function getInstance() {
            if (self::$instance === null) {
                self::$instance = new self();
            }
            return self::$instance;
        }
        
        public function saveVisibilitySettings($tableId, $settings, $userId = null) {
            if ($userId === null) {
                $userId = $_SESSION['user_id'] ?? 'global';
            }
            
            $key         = VISIBILITY_CONFIG_KEY . '_' . $userId;
            $allSettings = $_SESSION[$key] ?? [];
            $allSettings[$tableId] = $settings;
            $_SESSION[$key]        = $allSettings;
            
            // Also save to cross-app data for persistence
            $crossApp = CrossAppCommunicator::getInstance();
            $crossApp->setData('visibility_' . $tableId, $settings, 'ui_settings');
            
            return true;
        }
        
        public function getVisibilitySettings($tableId, $userId = null) {
            if ($userId === null) {
                $userId = $_SESSION['user_id'] ?? 'global';
            }
            
            // Try session first
            $key            = VISIBILITY_CONFIG_KEY . '_' . $userId;
            $sessionSetting = $_SESSION[$key][$tableId] ?? null;
            
            if ($sessionSetting !== null) {
                return $sessionSetting;
            }
            
            // Fallback to cross-app data
            $crossApp = CrossAppCommunicator::getInstance();
            return $crossApp->getData('visibility_' . $tableId, 'ui_settings', []);
        }
        
        public function generateVisibilityControls($tableId, $columns) {
            $settings = $this->getVisibilitySettings($tableId);
            $html     = '<div class="visibility-controls" style="background: #f8f9fa; padding: 12px; border-radius: 8px; margin: 15px 0; border: 1px solid #dee2e6; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
                            <strong style="color: #4361ee;">📊 Column Visibility Controls</strong>
                            <div>
                                <button type="button" class="btn-visibility-toggle" onclick="toggleAllColumns(\'' . h($tableId) . '\', true)" style="background: #4cc9f0; color: white; border: none; padding: 4px 8px; border-radius: 4px; font-size: 11px; margin-right: 5px;">Show All</button>
                                <button type="button" class="btn-visibility-toggle" onclick="toggleAllColumns(\'' . h($tableId) . '\', false)" style="background: #6c757d; color: white; border: none; padding: 4px 8px; border-radius: 4px; font-size: 11px;">Hide All</button>
                            </div>
                        </div>
                        <div class="visibility-options" style="display: flex; flex-wrap: wrap; gap: 12px; margin-top: 8px;">';
            
            foreach ($columns as $index => $column) {
                $columnId   = is_array($column) ? ($column['id'] ?? $index) : $column;
                $columnName = is_array($column) ? ($column['name'] ?? $columnId) : $column;
                $columnType = is_array($column) ? ($column['type'] ?? 'text') : 'text';
                $isVisible  = !isset($settings[$columnId]) || $settings[$columnId] !== false;
                
                $icon = $this->getColumnIcon($columnType);
                
                $html .= '<label class="visibility-option" style="display: flex; align-items: center; gap: 6px; font-size: 12px; background: white; padding: 6px 10px; border-radius: 6px; border: 1px solid #e9ecef; cursor: pointer; transition: all 0.2s;">
                            <input type="checkbox" data-column="' . h($columnId) . '" ' . ($isVisible ? 'checked' : '') . ' 
                                   onchange="toggleColumnVisibility(\'' . h($tableId) . '\', \'' . h($columnId) . '\', this.checked)"
                                   style="margin: 0;">
                            <span style="color: #666;">' . $icon . '</span>
                            ' . h($columnName) . '
                          </label>';
            }
            
            $html .= '</div></div>';
            
            return $html;
        }
        
        private function getColumnIcon($type) {
            $icons = [
                'text'       => '📝',
                'number'     => '🔢',
                'date'       => '📅',
                'email'      => '📧',
                'phone'      => '📞',
                'money'      => '💰',
                'percentage' => '📊',
                'boolean'    => '✅',
                'user'       => '👤',
                'location'   => '📍'
            ];
            
            return $icons[$type] ?? '📋';
        }
        
        public function generateRowVisibilityControls($tableId, $filters = []) {
            $html = '<div class="row-visibility-controls" style="background: #f8f9fa; padding: 12px; border-radius: 8px; margin: 15px 0; border: 1px solid #dee2e6;">
                        <strong style="color: #4361ee;">🔍 Row Filter Controls</strong>
                        <div style="display: flex; flex-wrap: wrap; gap: 10px; margin-top: 10px;">';
            
            foreach ($filters as $filter) {
                $html .= '<div class="filter-control">
                            <label style="font-size: 12px; font-weight: 500;">' . h($filter['label']) . '</label>
                            <select onchange="filterRows(\'' . h($tableId) . '\', \'' . h($filter['key']) . '\', this.value)" 
                                    style="font-size: 12px; padding: 4px 8px; border: 1px solid #ddd; border-radius: 4px; margin-left: 5px;">
                                <option value="">All</option>';
                
                foreach ($filter['options'] as $option) {
                    $html .= '<option value="' . h($option['value']) . '">' . h($option['label']) . '</option>';
                }
                
                $html .= '</select>
                         </div>';
            }
            
            $html .= '</div></div>';
            
            return $html;
        }
    }
}

/**
 * Enhanced Action Button System
 */
if (!class_exists('ActionButtonManager')) {
    class ActionButtonManager {
        public static function createButton($text, $type = 'primary', $icon = null, $attributes = []) {
            $class = 'btn btn-' . $type;
            $html  = '<button type="button" class="' . $class . '"';
            
            foreach ($attributes as $key => $value) {
                if ($key !== 'class') {
                    $html .= ' ' . $key . '="' . h($value) . '"';
                }
            }
            
            $html .= '>';
            
            if ($icon) {
                $html .= '<i class="fas fa-' . h($icon) . '"></i> ';
            }
            
            $html .= h($text) . '</button>';
            
            return $html;
        }
        
        public static function createActionBar($actions = [], $containerClass = 'action-buttons') {
            $html = '<div class="' . $containerClass . '" style="display: flex; gap: 10px; margin: 20px 0; flex-wrap: wrap; align-items: center;">';
            
            foreach ($actions as $action) {
                if ($action === 'separator') {
                    $html .= '<div style="width: 1px; background: #dee2e6; height: 30px; margin: 0 5px;"></div>';
                    continue;
                }
                
                $html .= self::createButton(
                    $action['text']       ?? '',
                    $action['type']       ?? 'primary',
                    $action['icon']       ?? null,
                    $action['attributes'] ?? []
                );
            }
            
            $html .= '</div>';
            return $html;
        }
        
        public static function getStandardActions($context = 'default') {
            $standardActions = [
                'edit' => [
                    'text' => 'Edit', 
                    'type' => 'warning', 
                    'icon' => 'edit', 
                    'attributes' => [
                        'data-action' => 'edit',
                        'class'       => 'btn-action-edit',
                        'onclick'     => 'handleEditAction(this)'
                    ]
                ],
                'delete' => [
                    'text' => 'Delete', 
                    'type' => 'danger', 
                    'icon' => 'trash', 
                    'attributes' => [
                        'data-action' => 'delete', 
                        'onclick'     => 'return confirm(\"Are you sure you want to delete this item?\")',
                        'class'       => 'btn-action-delete'
                    ]
                ],
                'update' => [
                    'text' => 'Update', 
                    'type' => 'success', 
                    'icon' => 'sync', 
                    'attributes' => [
                        'data-action' => 'update',
                        'class'       => 'btn-action-update'
                    ]
                ],
                'save' => [
                    'text' => 'Save', 
                    'type' => 'success', 
                    'icon' => 'save', 
                    'attributes' => [
                        'data-action' => 'save',
                        'class'       => 'btn-action-save'
                    ]
                ],
                'print' => [
                    'text' => 'Print', 
                    'type' => 'info', 
                    'icon' => 'print', 
                    'attributes' => [
                        'data-action' => 'print', 
                        'onclick'     => 'handlePrintAction()',
                        'class'       => 'btn-action-print'
                    ]
                ],
                'share' => [
                    'text' => 'Share', 
                    'type' => 'primary', 
                    'icon' => 'share', 
                    'attributes' => [
                        'data-action' => 'share',
                        'class'       => 'btn-action-share',
                        'onclick'     => 'handleShareAction()'
                    ]
                ],
                'export' => [
                    'text' => 'Export', 
                    'type' => 'secondary', 
                    'icon' => 'download', 
                    'attributes' => [
                        'data-action' => 'export',
                        'class'       => 'btn-action-export',
                        'onclick'     => 'handleExportAction()'
                    ]
                ],
                'view' => [
                    'text' => 'View', 
                    'type' => 'primary', 
                    'icon' => 'eye', 
                    'attributes' => [
                        'data-action' => 'view',
                        'class'       => 'btn-action-view'
                    ]
                ],
                'refresh' => [
                    'text' => 'Refresh', 
                    'type' => 'secondary', 
                    'icon' => 'redo', 
                    'attributes' => [
                        'data-action' => 'refresh',
                        'class'       => 'btn-action-refresh',
                        'onclick'     => 'location.reload()'
                    ]
                ]
            ];
            
            // Context-specific action sets
            $contextActions = [
                'crud'        => ['edit', 'delete', 'save', 'refresh'],
                'report'      => ['print', 'export', 'share', 'refresh'],
                'view_only'   => ['view', 'print', 'export', 'share'],
                'full'        => ['edit', 'delete', 'update', 'save', 'print', 'share', 'export', 'refresh'],
                'data_entry'  => ['save', 'refresh'],
                'admin'       => ['edit', 'delete', 'view', 'refresh']
            ];
            
            if (isset($contextActions[$context])) {
                $actions = [];
                foreach ($contextActions[$context] as $actionKey) {
                    if (isset($standardActions[$actionKey])) {
                        $actions[] = $standardActions[$actionKey];
                    }
                }
                return $actions;
            }
            
            return array_values($standardActions);
        }
        
        public static function createContextualActions($data, $context = 'default') {
            $actions = self::getStandardActions($context);
            
            // Add data-specific attributes
            foreach ($actions as &$action) {
                if (isset($data['id'])) {
                    $action['attributes']['data-id'] = $data['id'];
                }
                if (isset($data['type'])) {
                    $action['attributes']['data-type'] = $data['type'];
                }
            }
            
            return $actions;
        }
    }
}

// Initialize core systems
$crossApp          = CrossAppCommunicator::getInstance();
$printManager      = PrintExportManager::getInstance();
$photoManager      = ProfilePhotoManager::getInstance();
$visibilityManager = VisibilityManager::getInstance();

/**
 * Safe HTML escape
 */
if (!function_exists('h')) {
    function h(?string $v): string {
        return htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8');
    }
}

/**
 * Get the currently logged-in user (ENHANCED, SAFE, cross-app sync)
 */
if (!function_exists('current_user')) {
    function current_user(): ?array {
        static $cached = null;

        // Use cached if already loaded
        if ($cached !== null) {
            return $cached;
        }

        // If we already have full user array in session and it matches user_id, reuse it
        if (!empty($_SESSION['user']) && !empty($_SESSION['user_id'])) {
            if ((int)($_SESSION['user']['id'] ?? 0) === (int)$_SESSION['user_id']) {
                $cached = $_SESSION['user'];
                return $cached;
            }
        }

        // No user id => not logged in
        if (empty($_SESSION['user_id'])) {
            return null;
        }

        $userId = (int)$_SESSION['user_id'];

        try {
            $pdo = getPDO();
        } catch (Throwable $e) {
            error_log("current_user(): getPDO failed: " . $e->getMessage());
            // Fallback to whatever is in session (may be null)
            return $_SESSION['user'] ?? null;
        }

        try {
            $sql  = "SELECT * FROM users WHERE id = ? LIMIT 1";
            $stmt = $pdo->prepare($sql);

            if ($stmt === false) {
                error_log("current_user(): prepare() failed for SQL: " . $sql);
                return $_SESSION['user'] ?? null;
            }

            $stmt->execute([$userId]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($user) {
                $_SESSION['user'] = $user;
                $_SESSION['name'] = $user['full_name'] ?? $user['email'] ?? 'User';

                // Sync with cross-app system
                $crossApp = CrossAppCommunicator::getInstance();
                $crossApp->syncUserSession($user);

                $cached = $user;
                return $user;
            }

            // If DB has no row (user deleted), but session has something, return session
            if (!empty($_SESSION['user'])) {
                $cached = $_SESSION['user'];
                return $cached;
            }

            return null;
        } catch (Throwable $e) {
            error_log("current_user() error: " . $e->getMessage());
            return $_SESSION['user'] ?? null;
        }
    }
}

/**
 * Safe redirect (works even if some output already started)
 */
if (!function_exists('safe_redirect')) {
    function safe_redirect(string $path): void {
        if (!headers_sent()) {
            header('Location: ' . $path);
        } else {
            // Fallback JS + <noscript> meta refresh
            echo '<script>window.location.href=' . json_encode($path) . ';</script>';
            echo '<noscript><meta http-equiv="refresh" content="0;url='
                . htmlspecialchars($path, ENT_QUOTES, 'UTF-8')
                . '"></noscript>';
        }
        exit;
    }
}

/**
 * Require login; if not logged in, send to login.php
 */
if (!function_exists('require_login')) {
    function require_login(): void {
        if (empty($_SESSION['user_id'])) {
            safe_redirect('login.php');
        }
    }
}

/**
 * Centralized logout helper – updates DB, clears cross-app, and redirects to login
 */
if (!function_exists('logout_user')) {
    function logout_user(): void {
        $userId = $_SESSION['user_id'] ?? null;

        // Update DB last_logout_at & is_online if users table exists
        if ($userId) {
            try {
                $pdo  = getPDO();
                $sql  = "UPDATE users SET last_logout_at = NOW(), is_online = 0 WHERE id = ?";
                $stmt = $pdo->prepare($sql);
                if ($stmt) {
                    $stmt->execute([(int)$userId]);
                }
            } catch (Throwable $e) {
                error_log("logout_user() DB update failed: " . $e->getMessage());
            }
        }

        // Reset cross-app session data
        $crossApp = CrossAppCommunicator::getInstance();
        $crossApp->setData('current_user', null, 'session');

        // Destroy PHP session
        $_SESSION = [];
        if (ini_get("session.use_cookies")) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $params["path"], $params["domain"],
                $params["secure"], $params["httponly"]
            );
        }
        session_destroy();

        safe_redirect('login.php');
    }
}

/* ============================================================
 * ENHANCED PROJECT HELPERS – with cross-app communication
 * ============================================================ */

/**
 * Check if current user is allowed to access the given project.
 */
if (!function_exists('user_can_access_project')) {
    function user_can_access_project(?int $projectId): bool {
        // Null / 0 means "not linked to a project" – allow in general
        if (!$projectId) {
            return true;
        }

        $user = current_user();
        if (!$user) {
            return false;
        }

        // Admin: can access all projects
        if (($user['role'] ?? 'user') === 'admin') {
            return true;
        }

        // If access_all_projects is missing, treat as 1 (backwards compatible)
        $accessAll = isset($user['access_all_projects'])
            ? (int)$user['access_all_projects']
            : 1;

        if ($accessAll === 1) {
            return true;
        }

        // Otherwise check user_projects
        static $cache = null;
        if ($cache === null) {
            $cache = [];
            try {
                $pdo  = getPDO();
                $stmt = $pdo->prepare("SELECT project_id FROM user_projects WHERE user_id = ?");
                if ($stmt) {
                    $stmt->execute([(int)$user['id']]);
                    foreach ($stmt->fetchAll() as $row) {
                        $cache[(int)$row['project_id']] = true;
                    }
                }
            } catch (Throwable $e) {
                // If table missing for some reason, fall back to allowing access
                return true;
            }
        }

        return !empty($cache[(int)$projectId]);
    }
}

/**
 * Get all projects visible to the current user (ENHANCED with cross-app sync)
 */
if (!function_exists('get_projects')) {
    function get_projects(): array {
        ensure_user_schema_and_permissions();
        $pdo  = getPDO();
        $user = current_user();

        // Base query with geo-name enrichment (falls back to plain projects if geo tables are missing)
        $baseSql = "
            SELECT 
                p.*,
                COALESCE(r.name, p.region_other) AS region_name,
                COALESCE(z.name, p.zone_other)   AS zone_name,
                COALESCE(w.name, p.woreda_other) AS woreda_name
            FROM projects p
            LEFT JOIN regions r ON p.region_id = r.id
            LEFT JOIN zones   z ON p.zone_id   = z.id
            LEFT JOIN woredas w ON p.woreda_id = w.id
        ";

        // If no logged-in user, return all projects (legacy behavior)
        if (!$user) {
            try {
                $stmt = $pdo->query("SELECT * FROM projects ORDER BY id DESC");
                $projects = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
            } catch (Throwable $e) {
                $projects = [];
            }

            // Normalize for UI
            foreach ($projects as &$p) {
                if (!isset($p['owner'])) $p['owner'] = $p['contact_person'] ?? $p['project_manager'] ?? $p['owner_name'] ?? null;
                if (!isset($p['email'])) $p['email'] = $p['contact_email'] ?? $p['owner_email'] ?? null;
                if (!isset($p['implementing_partner'])) $p['implementing_partner'] = $p['ip_name'] ?? $p['implementing_partner_name'] ?? null;
                if (!isset($p['total_fund_usd']) && isset($p['total_budget_usd'])) $p['total_fund_usd'] = $p['total_budget_usd'];
            }
            unset($p);

            // Sync with cross-app system
            $crossApp = CrossAppCommunicator::getInstance();
            $crossApp->setData('available_projects', $projects, 'projects');
            return $projects;
        }

        // Detect if user has access_all_projects permission (optional feature)
        $accessAllProjects = false;
        try {
            $stmt = $pdo->prepare("SELECT permission FROM user_permissions WHERE user_id = ? AND permission = 'access_all_projects'");
            $stmt->execute([(int)$user['id']]);
            $accessAllProjects = (bool)$stmt->fetch();
        } catch (Throwable $e) {
            $accessAllProjects = false;
        }

        $projects = [];
        try {
            if (in_array(($user['role'] ?? ''), ['admin', 'manager'], true) || $accessAllProjects) {
                $stmt = $pdo->query($baseSql . " ORDER BY p.id DESC");
                $projects = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
            } else {
                $uid = (int)($user['id'] ?? 0);
                $sql = $baseSql . "
                    WHERE p.created_by_user_id = :uid
                       OR EXISTS (
                           SELECT 1 
                           FROM user_projects up
                           WHERE up.project_id = p.id AND up.user_id = :uid
                       )
                    ORDER BY p.id DESC
                ";
                $stmt = $pdo->prepare($sql);
                if ($stmt) {
                    $stmt->execute([':uid' => $uid]);
                    $projects = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
                }
            }
        } catch (Throwable $e) {
            // Fallback for older schemas (no geo tables / no user_projects table)
            try {
                if (in_array(($user['role'] ?? ''), ['admin', 'manager'], true) || $accessAllProjects) {
                    $stmt = $pdo->query("SELECT * FROM projects ORDER BY id DESC");
                    $projects = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
                } else {
                    $uid = (int)($user['id'] ?? 0);
                    $stmt = $pdo->prepare("SELECT * FROM projects WHERE created_by_user_id = :uid ORDER BY id DESC");
                    if ($stmt) {
                        $stmt->execute([':uid' => $uid]);
                        $projects = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
                    }
                }
            } catch (Throwable $e2) {
                $projects = [];
            }
        }

        // Normalize fields used across apps
        foreach ($projects as &$p) {
            if (!isset($p['owner'])) $p['owner'] = $p['contact_person'] ?? $p['project_manager'] ?? $p['owner_name'] ?? null;
            if (!isset($p['email'])) $p['email'] = $p['contact_email'] ?? $p['owner_email'] ?? null;
            if (!isset($p['implementing_partner'])) $p['implementing_partner'] = $p['ip_name'] ?? $p['implementing_partner_name'] ?? null;

            $parts = [];
            if (!empty($p['woreda_name'])) $parts[] = $p['woreda_name'];
            if (!empty($p['zone_name']))   $parts[] = $p['zone_name'];
            if (!empty($p['region_name'])) $parts[] = $p['region_name'];
            $p['location_display'] = trim(implode(', ', $parts));

            if (!isset($p['total_fund_usd']) && isset($p['total_budget_usd'])) $p['total_fund_usd'] = $p['total_budget_usd'];
        }
        unset($p);

        // Sync with cross-app system
        $crossApp = CrossAppCommunicator::getInstance();
        $crossApp->setData('available_projects', $projects, 'projects');

        return $projects;
    }
}

/**
 * Get current project from cross-app communication
 */
if (!function_exists('get_current_project')) {
    function get_current_project(): ?array {
        $crossApp = CrossAppCommunicator::getInstance();
        return $crossApp->getData('current_project', 'projects');
    }
}

/**
 * NEW: Get a project including all locations (region/zone/woreda) if available.
 * This helps other apps (beneficiary calc, reports, etc.) to see the full
 * geography for the selected project.
 */
if (!function_exists('get_project_with_locations')) {
    function get_project_with_locations($projectId): ?array {
        $pdo = getPDO();

        try {
            // Base project record
            $stmt = $pdo->prepare("SELECT * FROM projects WHERE id = ?");
            if (!$stmt) {
                return null;
            }
            $stmt->execute([$projectId]);
            $project = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$project) {
                return null;
            }

            // Try to read multi-location mapping from project_locations (if it exists)
            try {
                $locStmt = $pdo->prepare("
                    SELECT 
                        pl.region_id, r.name AS region_name,
                        pl.zone_id,   z.name AS zone_name,
                        pl.woreda_id, w.name AS woreda_name
                    FROM project_locations pl
                    LEFT JOIN regions r ON pl.region_id = r.id
                    LEFT JOIN zones   z ON pl.zone_id   = z.id
                    LEFT JOIN woredas w ON pl.woreda_id = w.id
                    WHERE pl.project_id = ?
                    ORDER BY pl.id
                ");
                if ($locStmt) {
                    $locStmt->execute([$projectId]);
                    $locations = $locStmt->fetchAll(PDO::FETCH_ASSOC);
                } else {
                    $locations = [];
                }
            } catch (Throwable $e) {
                // If project_locations or geo tables do not exist, just return base project
                $locations = [];
            }

            if (!empty($locations)) {
                $project['locations'] = $locations;

                $regionIds   = [];
                $zoneIds     = [];
                $woredaIds   = [];
                $regionNames = [];
                $zoneNames   = [];
                $woredaNames = [];

                foreach ($locations as $loc) {
                    if (!empty($loc['region_id'])) {
                        $regionIds[] = (int)$loc['region_id'];
                    }
                    if (!empty($loc['zone_id'])) {
                        $zoneIds[] = (int)$loc['zone_id'];
                    }
                    if (!empty($loc['woreda_id'])) {
                        $woredaIds[] = (int)$loc['woreda_id'];
                    }
                    if (!empty($loc['region_name'])) {
                        $regionNames[] = $loc['region_name'];
                    }
                    if (!empty($loc['zone_name'])) {
                        $zoneNames[] = $loc['zone_name'];
                    }
                    if (!empty($loc['woreda_name'])) {
                        $woredaNames[] = $loc['woreda_name'];
                    }
                }

                $project['region_ids']   = array_values(array_unique($regionIds));
                $project['zone_ids']     = array_values(array_unique($zoneIds));
                $project['woreda_ids']   = array_values(array_unique($woredaIds));
                $project['region_names'] = array_values(array_unique($regionNames));
                $project['zone_names']   = array_values(array_unique($zoneNames));
                $project['woreda_names'] = array_values(array_unique($woredaNames));
            }

        // Normalize commonly used fields across apps (projects/planning/dashboard/etc.)
if (!isset($project['owner'])) {
    $project['owner'] = $project['contact_person'] ?? $project['project_manager'] ?? $project['owner_name'] ?? null;
}
if (!isset($project['email'])) {
    $project['email'] = $project['contact_email'] ?? $project['owner_email'] ?? null;
}
if (!isset($project['implementing_partner'])) {
    $project['implementing_partner'] = $project['ip_name'] ?? $project['implementing_partner_name'] ?? null;
}

// Friendly location string (supports multi-location projects)
$locParts = [];
if (!empty($project['woreda_names'])) $locParts[] = implode(' / ', $project['woreda_names']);
if (!empty($project['zone_names']))   $locParts[] = implode(' / ', $project['zone_names']);
if (!empty($project['region_names'])) $locParts[] = implode(' / ', $project['region_names']);

if (!empty($locParts)) {
    $project['location_display'] = trim(implode(', ', $locParts));
    $project['woreda_name'] = $project['woreda_names'][0] ?? ($project['woreda_name'] ?? null);
    $project['zone_name']   = $project['zone_names'][0]   ?? ($project['zone_name'] ?? null);
    $project['region_name'] = $project['region_names'][0] ?? ($project['region_name'] ?? null);
} else {
    $parts = [];
    if (!empty($project['woreda_name'])) $parts[] = $project['woreda_name'];
    if (!empty($project['zone_name']))   $parts[] = $project['zone_name'];
    if (!empty($project['region_name'])) $parts[] = $project['region_name'];
    if (!empty($project['woreda_other']) && empty($project['woreda_name'])) $parts[] = $project['woreda_other'];
    if (!empty($project['zone_other']) && empty($project['zone_name']))     $parts[] = $project['zone_other'];
    if (!empty($project['region_other']) && empty($project['region_name'])) $parts[] = $project['region_other'];
    $project['location_display'] = trim(implode(', ', array_filter($parts)));
}

if (!isset($project['total_fund_usd']) && isset($project['total_budget_usd'])) {
    $project['total_fund_usd'] = $project['total_budget_usd'];
}

    return $project;
        } catch (Throwable $e) {
            error_log("get_project_with_locations error: " . $e->getMessage());
            return null;
        }
    }
}

/**
 * Set current project across all apps
 */
if (!function_exists('set_current_project')) {
    function set_current_project($projectId, $projectData = null) {
        $crossApp = CrossAppCommunicator::getInstance();
        
        if ($projectData === null) {
            // Use enhanced helper that also tries to include locations
            $projectData = get_project_with_locations($projectId);
        }
        
        if ($projectData) {
            $crossApp->syncProject($projectId, $projectData);
            return true;
        }
        
        return false;
    }
}

/**
 * Get indicators, with fallback if 'is_active' column does not exist.
 */
if (!function_exists('get_indicators')) {
    function get_indicators(): array {
        $pdo = getPDO();
        try {
            $stmt = $pdo->query("SELECT * FROM indicators WHERE is_active = 1 ORDER BY id");
            return $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
        } catch (\PDOException $e) {
            $stmt = $pdo->query("SELECT * FROM indicators ORDER BY id");
            return $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
        }
    }
}

/**
 * Enhanced CSV export helper with cross-app headers
 */
if (!function_exists('array_to_csv_download')) {
    function array_to_csv_download(array $rows, string $filename): void {
        $printManager = PrintExportManager::getInstance();
        $printConfig  = $printManager->getPrintConfig();
        
        if (!headers_sent()) {
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
        }

        $output = fopen('php://output', 'w');
        // Add BOM for UTF-8
        fputs($output, "\xEF\xBB\xBF");
        
        // Add header comments from cross-app data
        fputcsv($output, ['# ' . ($printConfig['title'] ?? 'SMART Nexus Export')]);
        fputcsv($output, ['# ' . ($printConfig['subtitle'] ?? 'Generated on ' . date('Y-m-d H:i:s'))]);
        
        $currentProject = get_current_project();
        if ($currentProject) {
            fputcsv($output, ['# Project: ' . ($currentProject['title'] ?? '')]);
        }
        
        fputcsv($output, ['#']); // Empty line
        
        foreach ($rows as $row) {
            fputcsv($output, $row);
        }
        fclose($output);
        exit;
    }
}

/* ============================================================
 * ENHANCED CFM (Complaint Feedback Mechanism) HELPERS
 * ============================================================ */

/**
 * Ensure CFM tables exist - Updated to use enhanced db.php structure
 */
if (!function_exists('ensure_cfm_tables')) {
    function ensure_cfm_tables(): void {
        // Tables are now created in db.php ensure_schema()
        // This function remains for backward compatibility
    }
}

/**
 * Get CFM reports with optional filters - Enhanced with cross-app data
 * Now SAFE even if get_cfm_reports_with_details() is not defined.
 */
if (!function_exists('get_cfm_reports')) {
    function get_cfm_reports(array $filters = []): array {
        try {
            if (!function_exists('get_cfm_reports_with_details')) {
                // Avoid fatal "undefined function" – log and return empty
                error_log('get_cfm_reports_with_details() not found; returning empty CFM list.');
                return [];
            }

            $reports = get_cfm_reports_with_details($filters);
            
            // Share with cross-app system
            $crossApp = CrossAppCommunicator::getInstance();
            $crossApp->setData('cfm_reports', $reports, 'cfm');
            $crossApp->setData('last_cfm_update', date('Y-m-d H:i:s'), 'cfm');
            
            return $reports;
        } catch (Throwable $e) {
            error_log("Error fetching CFM reports: " . $e->getMessage());
            return [];
        }
    }
}

/**
 * Get single CFM report by ID - Enhanced with cross-app data
 */
if (!function_exists('get_cfm_report')) {
    function get_cfm_report(int $id): ?array {
        try {
            $pdo = getPDO();
            $stmt = $pdo->prepare("
                SELECT cr.*, 
                       p.title as project_title, 
                       r.name as region_name,
                       z.name as zone_name,
                       w.name as woreda_name,
                       u.full_name as creator_name
                FROM cfm_reports cr
                LEFT JOIN projects p ON cr.project_id = p.id
                LEFT JOIN regions r ON cr.region_id = r.id
                LEFT JOIN zones z ON cr.zone_id = z.id
                LEFT JOIN woredas w ON cr.woreda_id = w.id
                LEFT JOIN users u ON cr.created_by = u.id
                WHERE cr.id = ?
            ");
            if (!$stmt) {
                return null;
            }
            $stmt->execute([$id]);
            $report = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
            
            if ($report) {
                // Share with cross-app system
                $crossApp = CrossAppCommunicator::getInstance();
                $crossApp->setData('current_cfm_report', $report, 'cfm');
            }
            
            return $report;
        } catch (Throwable $e) {
            error_log("Error fetching CFM report: " . $e->getMessage());
            return null;
        }
    }
}

/**
 * Enhanced CFM report addition with cross-app notification
 * Now SAFE even if add_cfm_report_db() is not defined.
 */
if (!function_exists('add_cfm_report')) {
    function add_cfm_report(array $data): array {
        try {
            if (!function_exists('add_cfm_report_db')) {
                $msg = 'add_cfm_report_db() not found; CFM report cannot be saved.';
                error_log($msg);
                return ['success' => false, 'error' => $msg];
            }

            $result = add_cfm_report_db($data);
            
            if (!empty($result['success'])) {
                // Notify cross-app system
                $crossApp = CrossAppCommunicator::getInstance();
                $crossApp->emit('cfmReportAdded', $data);
                
                // Update statistics
                $crossApp->setData('cfm_stats_updated', time(), 'cfm');
            }
            
            return $result;
        } catch (Throwable $e) {
            error_log("Error adding CFM report: " . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
}

/**
 * Format days for display
 */
if (!function_exists('format_days')) {
    function format_days(int $days): string {
        if ($days < 30) {
            return $days . " days";
        } else {
            $months        = floor($days / 30);
            $remainingDays = $days % 30;
            return $months . " month" . ($months > 1 ? "s" : "") . 
                   ($remainingDays > 0 ? " " . $remainingDays . " days" : "");
        }
    }
}

/**
 * Enhanced AI recommendation for CFM report with cross-app learning
 */
if (!function_exists('generate_ai_recommendation')) {
    function generate_ai_recommendation(array $data): string {
        $feedback        = strtolower($data['actual_feedback'] ?? '');
        $category        = $data['feedback_category'] ?? '';
        $status          = $data['feedback_status'] ?? '';
        $channel         = $data['feedback_channel'] ?? '';
        $vulnerability   = $data['vulnerability'] ?? '';
        $community_type  = $data['community_type'] ?? '';
        
        $recommendations = [];
        $urgency_level   = 'medium';
        
        // Enhanced sentiment and urgency analysis
        $urgent_keywords    = ['emergency', 'urgent', 'immediately', 'asap', 'critical', 'danger', 'safety', 'risk'];
        $positive_keywords  = ['thank', 'appreciate', 'good', 'excellent', 'great', 'helpful', 'satisfied'];
        $negative_keywords  = ['bad', 'poor', 'terrible', 'awful', 'horrible', 'disappointed', 'angry', 'frustrated'];
        
        // Urgency detection
        foreach ($urgent_keywords as $keyword) {
            if (strpos($feedback, $keyword) !== false) {
                $urgency_level   = 'high';
                $recommendations[] = "🚨 URGENT: Immediate attention required due to critical nature of feedback";
                break;
            }
        }
        
        // Sentiment-based recommendations
        $positive_count = 0;
        $negative_count = 0;
        
        foreach ($positive_keywords as $keyword) {
            if (strpos($feedback, $keyword) !== false) $positive_count++;
        }
        
        foreach ($negative_keywords as $keyword) {
            if (strpos($feedback, $keyword) !== false) $negative_count++;
        }
        
        if ($positive_count > $negative_count) {
            $recommendations[] = "Positive feedback detected - consider sharing with team for recognition";
        } elseif ($negative_count > $positive_count) {
            $recommendations[] = "Negative sentiment detected - prioritize empathetic response and resolution";
            $urgency_level = $urgency_level === 'medium' ? 'high' : $urgency_level;
        }
        
        // Vulnerability-specific recommendations
        if ($vulnerability !== 'None' && !empty($vulnerability)) {
            $recommendations[] = "Vulnerable group involved (" . $vulnerability . ") - ensure sensitive handling and follow-up";
            $urgency_level      = 'high';
        }
        
        // Community type considerations
        if ($community_type === 'IDP' || $community_type === 'Refugee') {
            $recommendations[] = "Special population group - coordinate with protection team if needed";
        }
        
        // Enhanced category-based recommendations
        switch ($category) {
            case 'Complaint':
                $recommendations[] = "Acknowledge complaint within 24 hours and provide timeline for resolution";
                $urgency_level = $urgency_level === 'medium' ? 'high' : $urgency_level;
                break;
            case 'Suggestion':
                $recommendations[] = "Evaluate suggestion for program improvement and provide feedback to complainant";
                break;
            case 'Appreciation':
                $recommendations[] = "Share positive feedback with relevant staff and management";
                break;
            case 'Question':
                $recommendations[] = "Provide clear, accurate response within 48 hours";
                break;
            case 'Report':
                $recommendations[] = "Document thoroughly and ensure proper follow-up procedures";
                break;
        }
        
        // Status-based actions
        switch ($status) {
            case 'New':
                $recommendations[] = "Assign to appropriate department within 24 hours";
                break;
            case 'Under Review':
                $recommendations[] = "Set clear timeline for resolution and communicate updates to complainant";
                break;
            case 'Action Taken':
                $recommendations[] = "Verify effectiveness of actions and conduct follow-up with complainant";
                break;
            case 'Resolved':
                $recommendations[] = "Conduct satisfaction follow-up and document lessons learned";
                break;
        }
        
        // Channel-specific recommendations
        switch ($channel) {
            case 'Hotline':
                $recommendations[] = "Ensure callback mechanism is functional for follow-up";
                break;
            case 'Community Meeting':
                $recommendations[] = "Document in meeting minutes and share action plan with community";
                break;
            case 'Suggestion Box':
                $recommendations[] = "Check suggestion box regularly and acknowledge all submissions";
                break;
        }
        
        // Default recommendation if none generated
        if (empty($recommendations)) {
            $recommendations[] = "Standard monitoring and evaluation process to be followed";
        }
        
        // Add urgency indicator
        $urgency_emoji = $urgency_level === 'high' ? '🚨' : ($urgency_level === 'medium' ? '⚠️' : 'ℹ️');
        
        $recommendation = $urgency_emoji . " AI Recommendation [" . strtoupper($urgency_level) . " priority]: " . 
               implode(". ", array_slice($recommendations, 0, 5));
        
        // Store recommendation in cross-app data for learning
        $crossApp = CrossAppCommunicator::getInstance();
        $crossApp->setData('last_ai_recommendation', $recommendation, 'ai');
        
        return $recommendation;
    }
}

/**
 * Enhanced CFM statistics with cross-app sharing
 * Now SAFE even if get_cfm_reports_with_details() is not defined.
 */
if (!function_exists('get_cfm_statistics')) {
    function get_cfm_statistics(): array {
        try {
            if (!function_exists('get_cfm_reports_with_details')) {
                error_log('get_cfm_reports_with_details() not found; CFM statistics will be empty.');
                return [
                    'total'               => 0,
                    'by_status'           => [],
                    'by_region'           => [],
                    'by_category'         => [],
                    'by_channel'          => [],
                    'by_gender'           => [],
                    'avg_resolution_time' => 0,
                    'pending_over_30_days'=> 0
                ];
            }

            $reports = get_cfm_reports_with_details();
            $stats   = [
                'total'               => count($reports),
                'by_status'           => [],
                'by_region'           => [],
                'by_category'         => [],
                'by_channel'          => [],
                'by_gender'           => [],
                'avg_resolution_time' => 0,
                'pending_over_30_days'=> 0
            ];
            
            $total_days       = 0;
            $count_with_dates = 0;
            
            foreach ($reports as $report) {
                // Status statistics
                $status = $report['feedback_status'] ?? 'Unknown';
                if (!isset($stats['by_status'][$status])) {
                    $stats['by_status'][$status] = 0;
                }
                $stats['by_status'][$status]++;
                
                // Region statistics
                $region = $report['region_name'] ?? 'Unknown';
                if (!isset($stats['by_region'][$region])) {
                    $stats['by_region'][$region] = 0;
                }
                $stats['by_region'][$region]++;
                
                // Category statistics
                $category = $report['feedback_category'] ?? 'Unknown';
                if (!isset($stats['by_category'][$category])) {
                    $stats['by_category'][$category] = 0;
                }
                $stats['by_category'][$category]++;
                
                // Channel statistics
                $channel = $report['feedback_channel'] ?? 'Unknown';
                if (!isset($stats['by_channel'][$channel])) {
                    $stats['by_channel'][$channel] = 0;
                }
                $stats['by_channel'][$channel]++;
                
                // Gender statistics
                $gender = $report['gender'] ?? 'Unknown';
                if (!isset($stats['by_gender'][$gender])) {
                    $stats['by_gender'][$gender] = 0;
                }
                $stats['by_gender'][$gender]++;
                
                // Calculate days open
                if (!empty($report['date_feedback_received'])) {
                    $days_open = calculate_days_open_helper($report['date_feedback_received']);
                    $total_days += $days_open;
                    $count_with_dates++;
                    
                    if ($days_open > 30 && in_array($status, ['New', 'Under Review', 'Action Taken'], true)) {
                        $stats['pending_over_30_days']++;
                    }
                }
            }
            
            if ($count_with_dates > 0) {
                $stats['avg_resolution_time'] = round($total_days / $count_with_dates, 1);
            }
            
            // Share with cross-app system
            $crossApp = CrossAppCommunicator::getInstance();
            $crossApp->setData('cfm_statistics', $stats, 'cfm');
            
            return $stats;
        } catch (Throwable $e) {
            error_log("Error calculating CFM statistics: " . $e->getMessage());
            return [
                'total'               => 0,
                'by_status'           => [],
                'by_region'           => [],
                'by_category'         => [],
                'by_channel'          => [],
                'by_gender'           => [],
                'avg_resolution_time' => 0,
                'pending_over_30_days'=> 0
            ];
        }
    }
}

// Helper function for calculate_days_open to avoid conflict
if (!function_exists('calculate_days_open_helper')) {
    function calculate_days_open_helper(string $dateReceived): int {
        try {
            $received = new DateTime($dateReceived);
            $today    = new DateTime();
            return $today->diff($received)->days;
        } catch (Throwable $e) {
            return 0;
        }
    }
}

/* ============================================================
 * ENHANCED GEOGRAPHICAL DATA MANAGEMENT
 * ============================================================ */

/**
 * Import comprehensive geographical data - Enhanced with cross-app sync
 * Now SAFE even if import_comprehensive_geographical_data() is not defined.
 */
if (!function_exists('import_geographical_data')) {
    function import_geographical_data(): array {
        try {
            if (!function_exists('import_comprehensive_geographical_data')) {
                $msg = 'import_comprehensive_geographical_data() not found; cannot import geo data.';
                error_log($msg);
                return ['success' => false, 'error' => $msg];
            }

            $result = import_comprehensive_geographical_data();
            
            if (!empty($result['success'])) {
                // Notify cross-app system
                $crossApp = CrossAppCommunicator::getInstance();
                $crossApp->emit('geoDataImported', $result);
            }
            
            return $result;
        } catch (Throwable $e) {
            error_log("Error importing geographical data: " . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
}

/**
 * NEW: Regions / Zones / Woredas helpers for auto dropdowns
 * These functions are safe and will not crash if tables are missing.
 * Multiple alias names are provided to match older scripts.
 */

// Regions
if (!function_exists('get_regions_list')) {
    function get_regions_list(): array {
        try {
            $pdo  = getPDO();
            $stmt = $pdo->query("SELECT id, name FROM regions ORDER BY name");
            $regions = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];

            $crossApp = CrossAppCommunicator::getInstance();
            $crossApp->setData('regions', $regions, 'geography');

            return $regions;
        } catch (Throwable $e) {
            error_log("get_regions_list error: " . $e->getMessage());
            return [];
        }
    }
}
if (!function_exists('get_regions')) {
    function get_regions(): array {
        return get_regions_list();
    }
}
if (!function_exists('fetch_regions')) {
    function fetch_regions(): array {
        return get_regions_list();
    }
}

// Zones
if (!function_exists('get_zones_by_region')) {
    function get_zones_by_region($regionId): array {
        try {
            $pdo  = getPDO();
            $stmt = $pdo->prepare("SELECT id, name, region_id FROM zones WHERE region_id = ? ORDER BY name");
            if (!$stmt) {
                return [];
            }
            $stmt->execute([(int)$regionId]);
            $zones = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $crossApp = CrossAppCommunicator::getInstance();
            $crossApp->setData('zones_region_' . (int)$regionId, $zones, 'geography');

            return $zones;
        } catch (Throwable $e) {
            error_log("get_zones_by_region error: " . $e->getMessage());
            return [];
        }
    }
}
if (!function_exists('get_zones_for_region')) {
    function get_zones_for_region($regionId): array {
        return get_zones_by_region($regionId);
    }
}
if (!function_exists('fetch_zones_by_region')) {
    function fetch_zones_by_region($regionId): array {
        return get_zones_by_region($regionId);
    }
}
if (!function_exists('get_all_zones')) {
    function get_all_zones(): array {
        try {
            $pdo  = getPDO();
            $stmt = $pdo->query("SELECT id, name, region_id FROM zones ORDER BY name");
            return $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
        } catch (Throwable $e) {
            error_log("get_all_zones error: " . $e->getMessage());
            return [];
        }
    }
}

// Woredas
if (!function_exists('get_woredas_by_zone')) {
    function get_woredas_by_zone($zoneId): array {
        try {
            $pdo  = getPDO();
            $stmt = $pdo->prepare("SELECT id, name, zone_id FROM woredas WHERE zone_id = ? ORDER BY name");
            if (!$stmt) {
                return [];
            }
            $stmt->execute([(int)$zoneId]);
            $woredas = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $crossApp = CrossAppCommunicator::getInstance();
            $crossApp->setData('woredas_zone_' . (int)$zoneId, $woredas, 'geography');

            return $woredas;
        } catch (Throwable $e) {
            error_log("get_woredas_by_zone error: " . $e->getMessage());
            return [];
        }
    }
}
if (!function_exists('get_woredas_for_zone')) {
    function get_woredas_for_zone($zoneId): array {
        return get_woredas_by_zone($zoneId);
    }
}
if (!function_exists('fetch_woredas_by_zone')) {
    function fetch_woredas_by_zone($zoneId): array {
        return get_woredas_by_zone($zoneId);
    }
}
if (!function_exists('get_all_woredas')) {
    function get_all_woredas(): array {
        try {
            $pdo  = getPDO();
            $stmt = $pdo->query("SELECT id, name, zone_id FROM woredas ORDER BY name");
            return $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
        } catch (Throwable $e) {
            error_log("get_all_woredas error: " . $e->getMessage());
            return [];
        }
    }
}

/**
 * Full hierarchy: Region → Zones → Woredas
 */
if (!function_exists('get_full_geography_hierarchy')) {
    function get_full_geography_hierarchy(): array {
        try {
            $pdo = getPDO();
            $sql = "
                SELECT 
                    r.id   AS region_id, r.name AS region_name,
                    z.id   AS zone_id,   z.name AS zone_name,
                    w.id   AS woreda_id, w.name AS woreda_name
                FROM regions r
                LEFT JOIN zones z   ON z.region_id = r.id
                LEFT JOIN woredas w ON w.zone_id   = z.id
                ORDER BY r.name, z.name, w.name
            ";
            $stmt = $pdo->query($sql);
            $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];

            $hierarchy = [];
            foreach ($rows as $row) {
                $rid = (int)$row['region_id'];
                if (!isset($hierarchy[$rid])) {
                    $hierarchy[$rid] = [
                        'id'     => $rid,
                        'name'   => $row['region_name'],
                        'zones'  => []
                    ];
                }
                if (!empty($row['zone_id'])) {
                    $zid = (int)$row['zone_id'];
                    if (!isset($hierarchy[$rid]['zones'][$zid])) {
                        $hierarchy[$rid]['zones'][$zid] = [
                            'id'      => $zid,
                            'name'    => $row['zone_name'],
                            'woredas' => []
                        ];
                    }
                    if (!empty($row['woreda_id'])) {
                        $wid = (int)$row['woreda_id'];
                        $hierarchy[$rid]['zones'][$zid]['woredas'][$wid] = [
                            'id'   => $wid,
                            'name' => $row['woreda_name']
                        ];
                    }
                }
            }

            // Normalize indexes
            foreach ($hierarchy as &$region) {
                $region['zones'] = array_values($region['zones']);
                foreach ($region['zones'] as &$zone) {
                    $zone['woredas'] = array_values($zone['woredas']);
                }
            }
            unset($region, $zone);

            $hierarchy = array_values($hierarchy);

            $crossApp = CrossAppCommunicator::getInstance();
            $crossApp->setData('geo_hierarchy', $hierarchy, 'geography');

            return $hierarchy;
        } catch (Throwable $e) {
            error_log("get_full_geography_hierarchy error: " . $e->getMessage());
            return [];
        }
    }
}

/* ============================================================
 * ENHANCED IMPORT / TEMPLATE HELPERS
 * ============================================================ */

/**
 * Parse a CSV upload (template) into rows with header mapping.
 */
if (!function_exists('parse_uploaded_csv')) {
    function parse_uploaded_csv(string $fileInputName, array $requiredHeaders = [], string $delimiter = ','): array {
        if (empty($_FILES[$fileInputName]) || $_FILES[$fileInputName]['error'] !== UPLOAD_ERR_OK) {
            return ['success' => false, 'error' => 'No file uploaded or upload error', 'headers' => [], 'rows' => []];
        }

        $file = $_FILES[$fileInputName];

        // Basic extension validation – we keep it flexible but safe
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['csv', 'txt'])) {
            return ['success' => false, 'error' => 'Unsupported file type. Please upload a CSV (export your template as CSV).', 'headers' => [], 'rows' => []];
        }

        $handle = fopen($file['tmp_name'], 'r');
        if (!$handle) {
            return ['success' => false, 'error' => 'Unable to open uploaded file.', 'headers' => [], 'rows' => []];
        }

        // Read header row
        $headerRow = fgetcsv($handle, 0, $delimiter);
        if (!$headerRow) {
            fclose($handle);
            return ['success' => false, 'error' => 'The file appears to be empty.', 'headers' => [], 'rows' => []];
        }

        // Normalize headers (trim for matching)
        $headers = [];
        foreach ($headerRow as $col) {
            $headers[] = trim((string)$col);
        }

        // Validate required headers (case-insensitive)
        if (!empty($requiredHeaders)) {
            $missing    = [];
            $normalized = array_map('strtolower', $headers);
            foreach ($requiredHeaders as $req) {
                if (!in_array(strtolower($req), $normalized, true)) {
                    $missing[] = $req;
                }
            }
            if (!empty($missing)) {
                fclose($handle);
                return [
                    'success' => false,
                    'error'   => 'Template mismatch. Missing required columns: ' . implode(', ', $missing),
                    'headers' => $headers,
                    'rows'    => []
                ];
            }
        }

        // Read all rows
        $rows = [];
        while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
            // Skip completely empty lines
            if (count(array_filter($row, fn($v) => (string)$v !== '')) === 0) {
                continue;
            }

            $assoc = [];
            foreach ($headers as $i => $h) {
                $assoc[$h] = $row[$i] ?? null;
            }
            $rows[] = $assoc;
        }

        fclose($handle);

        return [
            'success' => true,
            'error'   => null,
            'headers' => $headers,
            'rows'    => $rows
        ];
    }
}

/**
 * Small helper to quickly validate a template against expected headers.
 */
if (!function_exists('validate_template_headers')) {
    function validate_template_headers(array $headers, array $expected): array {
        $normalized = array_map('strtolower', $headers);
        $missing    = [];
        foreach ($expected as $req) {
            if (!in_array(strtolower($req), $normalized, true)) {
                $missing[] = $req;
            }
        }
        return $missing;
    }
}

/* ============================================================
 * ENHANCED ROLE & PERMISSION HELPERS
 * ============================================================ */

// All application keys used for permissions and nav.
if (!defined('APP_KEYS')) {
    define('APP_KEYS', [
        'dashboard'      => 'Dashboard',
        'projects'       => 'Projects',
        'planning'       => 'Planning / Logframe & Workplan',
        'budget'         => 'Budget planning',
        'geography'      => 'Geography',
        'indicators'     => 'Indicators',
        'enter_data'     => 'Enter data',
        'view_reports'   => 'View report',
        'custom_report'  => 'Custom report',
        'aggregation'    => 'Aggregation',
        'pivot'          => 'Pivot',
        'progress'       => 'Progress dashboard',
        'messages'       => 'Messaging',
        'users'          => 'User management',
        'cfm'            => 'Complaint Feedback Mechanism',
        'data_quality'   => 'Data Quality',
        'nexus_ai'       => 'Nexus AI Assistant'
        // NOTE: New apps will still work even if not listed here,
        // because user_can() has safe defaults.
    ]);
}

/**
 * Ensure user schema and permissions tables exist
 * NOTE: Compatible with ensure_user_schema_and_permissions() and ensure_user_schema_and_permissions($pdo)
 */
if (!function_exists('ensure_user_schema_and_permissions')) {
    function ensure_user_schema_and_permissions($pdoArg = null): void {
        try {
            $pdo = $pdoArg instanceof PDO ? $pdoArg : getPDO();
        } catch (Throwable $e) {
            error_log("ensure_user_schema_and_permissions(): getPDO failed: " . $e->getMessage());
            return;
        }

        // Ensure users table exists with base structure (same as in login.php, safe if duplicated)
        try {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS users (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    email VARCHAR(190) UNIQUE NOT NULL,
                    password_hash VARCHAR(255) DEFAULT NULL,
                    password VARCHAR(255) DEFAULT NULL,
                    full_name VARCHAR(190) NULL,
                    role ENUM('admin','user','guest') NOT NULL DEFAULT 'user',
                    is_active TINYINT(1) NOT NULL DEFAULT 1,
                    email_verified TINYINT(1) NOT NULL DEFAULT 0,
                    failed_logins INT NOT NULL DEFAULT 0,
                    last_failed_login_at DATETIME DEFAULT NULL,
                    locked_until DATETIME DEFAULT NULL,
                    last_login_at DATETIME DEFAULT NULL,
                    last_logout_at DATETIME DEFAULT NULL,
                    last_seen_at DATETIME DEFAULT NULL,
                    is_online TINYINT(1) NOT NULL DEFAULT 0,
                    profile_photo VARCHAR(255) DEFAULT NULL,
                    photo_path VARCHAR(255) DEFAULT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
        } catch (Throwable $e) {
            error_log("ensure_user_schema_and_permissions(): failed to CREATE users: " . $e->getMessage());
        }

        // Helper to test column existence
        $hasCol = function(string $table, string $column) use ($pdo): bool {
            try {
                $stmt = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
                if (!$stmt) {
                    return false;
                }
                $stmt->execute([$column]);
                return (bool)$stmt->fetch();
            } catch (Throwable $e) {
                return false;
            }
        };

        // ---- USERS table columns ----
        $userCols = [
            'full_name'            => "VARCHAR(190) NULL",
            'phone'                => "VARCHAR(50) NULL",
            'role'                 => "ENUM('admin','user','guest') NOT NULL DEFAULT 'user'",
            'photo_path'           => "VARCHAR(255) NULL",
            'password_hash'        => "VARCHAR(255) NULL",
            'is_active'            => "TINYINT(1) NOT NULL DEFAULT 1",
            'email_verified'       => "TINYINT(1) NOT NULL DEFAULT 0",
            'verification_token'   => "VARCHAR(64) DEFAULT NULL",
            'last_login_at'        => "DATETIME DEFAULT NULL",
            'last_logout_at'       => "DATETIME DEFAULT NULL",
            'last_seen_at'         => "DATETIME DEFAULT NULL",
            'is_online'            => "TINYINT(1) NOT NULL DEFAULT 0",
            'failed_logins'        => "INT NOT NULL DEFAULT 0",
            'last_failed_login_at' => "DATETIME DEFAULT NULL",
            'locked_until'         => "DATETIME DEFAULT NULL",
            'access_all_projects'  => "TINYINT(1) NOT NULL DEFAULT 1",
        ];

        foreach ($userCols as $col => $def) {
            if (!$hasCol('users', $col)) {
                try {
                    $pdo->exec("ALTER TABLE `users` ADD COLUMN `$col` $def");
                } catch (Throwable $e) {
                    // ignore errors
                    error_log("ensure_user_schema_and_permissions(): failed to add users.$col: " . $e->getMessage());
                }
            }
        }

        // ---- app_permissions table ----
        try {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS app_permissions (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    user_id INT NOT NULL,
                    app_key VARCHAR(50) NOT NULL,
                    can_view TINYINT(1) NOT NULL DEFAULT 1,
                    can_edit TINYINT(1) NOT NULL DEFAULT 0,
                    is_hidden TINYINT(1) NOT NULL DEFAULT 0,
                    UNIQUE KEY uniq_user_app (user_id, app_key),
                    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
        } catch (Throwable $e) {
            // ignore creation error
            error_log("ensure_user_schema_and_permissions(): failed to create app_permissions: " . $e->getMessage());
        }

        // Ensure is_hidden exists
        if ($hasCol('app_permissions', 'id') && !$hasCol('app_permissions', 'is_hidden')) {
            try {
                $pdo->exec("ALTER TABLE `app_permissions` ADD COLUMN `is_hidden` TINYINT(1) NOT NULL DEFAULT 0");
            } catch (Throwable $e) {
                // ignore
                error_log("ensure_user_schema_and_permissions(): failed to add app_permissions.is_hidden: " . $e->getMessage());
            }
        }

        // ---- user_projects table ----
        try {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS user_projects (
                    user_id   INT NOT NULL,
                    project_id INT NOT NULL,
                    PRIMARY KEY (user_id, project_id),
                    INDEX idx_user_projects_user (user_id),
                    INDEX idx_user_projects_project (project_id),
                    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
        } catch (Throwable $e) {
            // ignore
            error_log("ensure_user_schema_and_permissions(): failed to create user_projects: " . $e->getMessage());
        }
        
        // CFM tables are now created in db.php ensure_schema()
    }
}

/**
 * Is current user admin?
 */
if (!function_exists('current_user_is_admin')) {
    function current_user_is_admin(): bool {
        $u = current_user();
        return $u && (($u['role'] ?? '') === 'admin');
    }
}

/**
 * Backwards-compatible alias
 */
if (!function_exists('is_admin')) {
    function is_admin(): bool {
        return current_user_is_admin();
    }
}

/**
 * Enhanced permission check with cross-app awareness
 *
 * NOTE: Updated so that "hidden" apps can still be managed from app_management.php.
 */
if (!function_exists('user_can')) {
    function user_can(string $appKey, string $action = 'view'): bool {
        ensure_user_schema_and_permissions();

        $user = current_user();
        if (!$user) {
            return false;
        }

        $role   = $user['role'] ?? 'user';
        $userId = (int)$user['id'];

        // Detect current script to allow special behaviour in app_management
        $scriptName       = basename($_SERVER['SCRIPT_NAME'] ?? '');
        $isAppManagement  = ($scriptName === 'app_management.php');

        // Cache permissions per request
        static $permCache = [];

        if (!isset($permCache[$userId])) {
            $pdo = getPDO();
            $permCache[$userId] = [];
            try {
                $stmt = $pdo->prepare("
                    SELECT app_key, can_view, can_edit, is_hidden
                      FROM app_permissions
                     WHERE user_id = ?
                ");
                if ($stmt) {
                    $stmt->execute([$userId]);
                    foreach ($stmt as $row) {
                        $permCache[$userId][$row['app_key']] = [
                            'view'   => (bool)$row['can_view'],
                            'edit'   => (bool)$row['can_edit'],
                            'hidden' => (bool)$row['is_hidden'],
                        ];
                    }
                }
            } catch (Throwable $e) {
                $permCache[$userId] = [];
            }
        }

        $perms = $permCache[$userId][$appKey] ?? null;

        // If app is explicitly hidden for this user, deny everything
        // EXCEPT on the app_management page, where we still want to show
        // the app so the admin/user can restore/unhide it.
        if ($perms && !empty($perms['hidden']) && !$isAppManagement) {
            return false;
        }

        // Admin: full access unless explicitly hidden
        if ($role === 'admin') {
            return true;
        }

        // If explicit permissions exist, use them
        if ($perms !== null) {
            if ($action === 'view') {
                return !empty($perms['view']);
            }
            return !empty($perms['edit']);
        }

        // Default rules when specific permissions not configured
        if ($role === 'guest') {
            $viewAllowed = in_array($appKey, [
                'dashboard',
                'view_reports',
                'custom_report',
                'aggregation',
                'pivot',
                'progress',
                'cfm'
            ], true);

            if ($action === 'view') {
                return $viewAllowed;
            }
            return false;
        }

        // Normal user: can view & edit most apps, except user management
        if ($appKey === 'users') {
            return false;
        }

        // IMPORTANT: any NEW appKey not known here still works:
        // - normal users: can view/edit
        // - admin: already allowed above
        return true;
    }
}

/**
 * Convenience wrappers
 */
if (!function_exists('user_can_view_app')) {
    function user_can_view_app(string $appKey): bool {
        return user_can($appKey, 'view');
    }
}

if (!function_exists('user_can_edit_app')) {
    function user_can_edit_app(string $appKey): bool {
        return user_can($appKey, 'edit');
    }
}

/**
 * Enforce permission
 */
if (!function_exists('require_permission')) {
    function require_permission(string $appKey, string $action = 'view'): void {
        ensure_user_schema_and_permissions();
        if (!user_can($appKey, $action)) {
            http_response_code(403);
            echo "<h1>Access denied</h1>";
            echo "<p>You do not have permission to access this part of SMART Nexus.</p>";
            exit;
        }
    }
}

/**
 * Set flash message for next request
 */
if (!function_exists('set_flash_message')) {
    function set_flash_message(string $message, string $type = 'success'): void {
        $_SESSION['flash_message'] = $message;
        $_SESSION['flash_type']    = $type;
        
        // Also store in cross-app data for cross-request communication
        $crossApp = CrossAppCommunicator::getInstance();
        $crossApp->setData('flash_message', ['message' => $message, 'type' => $type], 'session');
    }
}

/**
 * Get and clear flash message
 */
if (!function_exists('get_flash_message')) {
    function get_flash_message(): ?array {
        if (isset($_SESSION['flash_message'])) {
            $message = $_SESSION['flash_message'];
            $type    = $_SESSION['flash_type'] ?? 'success';
            unset($_SESSION['flash_message'], $_SESSION['flash_type']);
            return ['message' => $message, 'type' => $type];
        }
        return null;
    }
}

/**
 * Validate and sanitize input
 */
if (!function_exists('sanitize_input')) {
    function sanitize_input($data) {
        if (is_array($data)) {
            return array_map('sanitize_input', $data);
        }
        return htmlspecialchars(trim($data ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

/**
 * Generate random token
 */
if (!function_exists('generate_token')) {
    function generate_token(int $length = 32): string {
        return bin2hex(random_bytes($length));
    }
}

/**
 * Enhanced error handler for better debugging
 */
if (!function_exists('handle_error')) {
    function handle_error($errno, $errstr, $errfile, $errline) {
        if (!(error_reporting() & $errno)) {
            return false;
        }
        
        $error_types = [
            E_ERROR           => 'Error',
            E_WARNING         => 'Warning',
            E_PARSE           => 'Parse Error',
            E_NOTICE          => 'Notice',
            E_USER_ERROR      => 'User Error',
            E_USER_WARNING    => 'User Warning',
            E_USER_NOTICE     => 'User Notice',
        ];
        
        $error_type = $error_types[$errno] ?? 'Unknown Error';
        
        error_log("PHP $error_type: $errstr in $errfile on line $errline");
        
        // Store in cross-app data for debugging
        $crossApp = CrossAppCommunicator::getInstance();
        $errors   = $crossApp->getData('system_errors', 'debug', []);
        $errors[] = [
            'type'      => $error_type,
            'message'   => $errstr,
            'file'      => $errfile,
            'line'      => $errline,
            'timestamp' => date('Y-m-d H:i:s')
        ];
        $crossApp->setData('system_errors', $errors, 'debug');
        
        // Don't display errors in production
        if (ini_get('display_errors')) {
            echo "<div style='background: #fee; border: 1px solid #fcc; padding: 10px; margin: 10px;'>";
            echo "<strong>$error_type:</strong> $errstr in <strong>$errfile</strong> on line <strong>$errline</strong>";
            echo "</div>";
        }
        
        return true;
    }
}

/**
 * Enhanced exception handler
 */
if (!function_exists('handle_exception')) {
    function handle_exception($exception) {
        error_log("Uncaught Exception: " . $exception->getMessage() . " in " . $exception->getFile() . " on line " . $exception->getLine());
        
        // Store in cross-app data
        $crossApp   = CrossAppCommunicator::getInstance();
        $exceptions = $crossApp->getData('system_exceptions', 'debug', []);
        $exceptions[] = [
            'message'   => $exception->getMessage(),
            'file'      => $exception->getFile(),
            'line'      => $exception->getLine(),
            'trace'     => $exception->getTraceAsString(),
            'timestamp' => date('Y-m-d H:i:s')
        ];
        $crossApp->setData('system_exceptions', $exceptions, 'debug');
        
        if (ini_get('display_errors')) {
            echo "<div style='background: #fee; border: 1px solid #fcc; padding: 10px; margin: 10px;'>";
            echo "<h3>Uncaught Exception</h3>";
            echo "<p><strong>Message:</strong> " . $exception->getMessage() . "</p>";
            echo "<p><strong>File:</strong> " . $exception->getFile() . "</p>";
            echo "<p><strong>Line:</strong> " . $exception->getLine() . "</p>";
            echo "<pre>" . $exception->getTraceAsString() . "</pre>";
            echo "</div>";
        }
    }
}

// Set error and exception handlers (safe even if header sets its own later)
set_error_handler('handle_error');
set_exception_handler('handle_exception');

// Initialize on each request
ensure_user_schema_and_permissions();

/* ============================================================
 * AI GUIDANCE & UI HELPERS (BLOCK HEADERS)
 * ============================================================ */

// AI-powered guidance system
if (!function_exists('get_ai_guidance')) {
    function get_ai_guidance($context, $userData = null) {
        if ($userData === null) {
            $userData = current_user();
        }
        
        $guidanceTemplates = [
            'project_registration' => [
                'admin'   => "🚀 AI Guidance: As an admin, ensure project details are comprehensive, with clear objectives, KPIs, and stakeholder information.",
                'user'    => "💡 AI Tip: Provide clear project descriptions, locations and timelines. This improves monitoring and reporting quality.",
                'default' => "📋 Best Practice: Fill all mandatory project fields and add realistic milestones to support accurate tracking."
            ],
            'data_entry' => [
                'admin'   => "🎯 AI Insight: Periodically review datasets from enter_data.php and configure automated data quality checks for critical indicators.",
                'user'    => "📊 AI Tip: Double-check numeric values, dates and SADD figures before saving. Consistency improves report reliability.",
                'default' => "✅ Recommendation: Save frequently and use the templates to avoid missing fields or misaligned columns."
            ],
            'report_generation' => [
                'admin'   => "📈 AI Strategy: Use cross-app data from aggregation, custom_report and progress dashboards to identify trends and gaps.",
                'user'    => "🔍 AI Suggestion: Apply filters (project, time period, region, woreda) to focus your analysis and exports.",
                'default' => "📋 Guidance: Always set a clear title and project context before printing or exporting reports."
            ],
            'cfm_management' => [
                'admin'   => "🔄 AI Process: Track resolution times and backlog in CFM statistics, and coordinate with protection teams where needed.",
                'user'    => "💬 AI Advice: Capture detailed feedback and select correct categories/channels to support meaningful follow-up.",
                'default' => "📝 Best Practice: Record all actions and responses in the CFM system for accountability and learning."
            ],
            'default' => [
                'default' => "🤖 AI Assistant: Continue your work. The system is ready to support you with data, analytics and smart exports."
            ]
        ];
        
        $userRole = $userData['role'] ?? 'user';
        $template = $guidanceTemplates[$context] ?? $guidanceTemplates['default'];
        
        return $template[$userRole] ?? $template['default'];
    }
}

/**
 * Render a nice block header inside each app (for sections/cards).
 */
if (!function_exists('render_block_header')) {
    function render_block_header(string $title, string $subtitle = '', string $icon = ''): string {
        $iconHtml = '';
        if ($icon !== '') {
            $iconHtml = '<div style="margin-right:8px; display:flex; align-items:center; justify-content:center; width:32px; height:32px; border-radius:50%; background:linear-gradient(135deg,#667eea,#764ba2); color:#fff;">
                            <i class="fas fa-' . h($icon) . '"></i>
                         </div>';
        }
        $subtitleHtml = $subtitle !== ''
            ? '<div style="font-size:11px; color:#64748b; margin-top:2px;">' . h($subtitle) . '</div>'
            : '';
        
        return '
        <div class="block-header" style="margin:15px 0 10px; padding:10px 14px; border-radius:12px; background:#f8fafc; border:1px solid #e2e8f0; display:flex; align-items:center;">
            ' . $iconHtml . '
            <div style="flex:1;">
                <div style="font-size:13px; font-weight:700; color:#1e293b;">' . h($title) . '</div>
                ' . $subtitleHtml . '
            </div>
        </div>';
    }
}

/**
 * Render a compact AI banner/hint (can be placed near forms, imports, etc.)
 */
if (!function_exists('render_ai_hint')) {
    function render_ai_hint(string $context): string {
        $msg = get_ai_guidance($context);
        return '
        <div class="ai-hint" style="margin:10px 0 15px; padding:8px 10px; border-radius:10px; background:linear-gradient(135deg,#4facfe,#00f2fe); color:#fff; font-size:11px; display:flex; align-items:flex-start; gap:8px;">
            <div style="width:20px; display:flex; align-items:center; justify-content:center;"><i class="fas fa-robot"></i></div>
            <div style="flex:1;">' . $msg . '</div>
        </div>';
    }
}

/**
 * Detect if current request is intended for a pure export/download.
 */
if (!function_exists('is_export_request')) {
    function is_export_request(): bool {
        $uri    = $_SERVER['REQUEST_URI']  ?? '';
        $script = $_SERVER['SCRIPT_NAME']  ?? '';
        $targets= ['export', 'download', '_csv', '_excel', '_xls', '_pdf'];
        foreach ($targets as $t) {
            if (stripos($uri, $t) !== false || stripos($script, $t) !== false) {
                return true;
            }
        }
        return false;
    }
}

// NEW: Enhanced initialization with cross-app systems
if (!function_exists('initialize_enhanced_systems')) {
    function initialize_enhanced_systems() {
        // Ensure cross-app systems are loaded
        $crossApp          = CrossAppCommunicator::getInstance();
        $printManager      = PrintExportManager::getInstance();
        $photoManager      = ProfilePhotoManager::getInstance();
        $visibilityManager = VisibilityManager::getInstance();
        
        // Initialize default print configuration if not set
        $currentProject = get_current_project();
        if ($currentProject) {
            $printManager->setupPrint(
                'SMART Nexus Report', 
                'Generated on ' . date('Y-m-d H:i:s'),
                $currentProject
            );
        }
        
        // Initialize profile photo cache if not set
        if (!isset($_SESSION[PROFILE_PHOTO_KEY])) {
            $_SESSION[PROFILE_PHOTO_KEY] = [];
        }

        // Store global version/env in cross-app system for debugging or display
        $crossApp->setData('app_version', APP_VERSION, 'system');
        $crossApp->setData('app_env', APP_ENV, 'system');
        $crossApp->setData('app_name', APP_NAME, 'system');
        
        return true;
    }
}

// Auto-initialize enhanced systems
initialize_enhanced_systems();

// NEW: Utility function to get all enhanced managers
if (!function_exists('get_enhanced_managers')) {
    function get_enhanced_managers() {
        return [
            'crossApp'       => CrossAppCommunicator::getInstance(),
            'printManager'   => PrintExportManager::getInstance(),
            'photoManager'   => ProfilePhotoManager::getInstance(),
            'visibilityManager'=> VisibilityManager::getInstance(),
            'actionManager'  => new ActionButtonManager()
        ];
    }
}

// NEW: Function to generate standardized action bars
if (!function_exists('generate_action_bar')) {
    function generate_action_bar($context = 'default', $customActions = []) {
        $actions = ActionButtonManager::getStandardActions($context);
        
        if (!empty($customActions)) {
            $actions = array_merge($actions, $customActions);
        }
        
        return ActionButtonManager::createActionBar($actions);
    }
}

// NEW: Function to setup printing with current context
if (!function_exists('setup_app_printing')) {
    function setup_app_printing($title, $subtitle = null, $customData = []) {
        $printManager   = PrintExportManager::getInstance();
        $currentProject = get_current_project();
        
        return $printManager->setupPrint(
            $title,
            $subtitle,
            array_merge($currentProject ?? [], $customData)
        );
    }
}

// NEW: Enhanced function to handle cross-app data sharing
if (!function_exists('share_data_across_apps')) {
    function share_data_across_apps($data, $targetApps = [], $sourceApp = null) {
        $crossApp = CrossAppCommunicator::getInstance();
        
        if ($sourceApp === null) {
            $sourceApp = pathinfo($_SERVER['SCRIPT_NAME'], PATHINFO_FILENAME);
        }
        
        $shareId   = uniqid('share_');
        $shareData = [
            'id'        => $shareId,
            'source'    => $sourceApp,
            'data'      => $data,
            'timestamp' => date('Y-m-d H:i:s'),
            'targets'   => $targetApps
        ];
        
        $crossApp->setData($shareId, $shareData, 'shared_data');
        
        // Send messages to target apps
        foreach ($targetApps as $targetApp) {
            $crossApp->sendMessage($sourceApp, $targetApp, 'data_shared', [
                'share_id'  => $shareId,
                'data_type' => gettype($data),
                'data_size' => is_array($data) ? count($data) : strlen((string)$data)
            ]);
        }
        
        return $shareId;
    }
}

// NEW: Function to retrieve shared data
if (!function_exists('get_shared_data')) {
    function get_shared_data($shareId) {
        $crossApp = CrossAppCommunicator::getInstance();
        return $crossApp->getData($shareId, 'shared_data');
    }
}

// NEW: Enhanced project registration with cross-app sync
if (!function_exists('register_project_across_apps')) {
    function register_project_across_apps($projectData) {
        $pdo = getPDO();
        
        try {
            // Insert project
            $stmt = $pdo->prepare("
                INSERT INTO projects (title, description, code, location, start_date, end_date, budget, status, created_by, created_at) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            
            if (!$stmt) {
                throw new Exception("Failed to prepare project insert statement");
            }

            $stmt->execute([
                $projectData['title'],
                $projectData['description'] ?? '',
                $projectData['code']        ?? '',
                $projectData['location']    ?? '',
                $projectData['start_date']  ?? null,
                $projectData['end_date']    ?? null,
                $projectData['budget']      ?? 0,
                $projectData['status']      ?? 'active',
                $_SESSION['user_id']        ?? 1
            ]);
            
            $projectId = $pdo->lastInsertId();
            
            // Sync across all apps (using enhanced helper to include locations)
            $projectFull = get_project_with_locations($projectId) ?: array_merge($projectData, ['id' => $projectId]);
            $crossApp    = CrossAppCommunicator::getInstance();
            $crossApp->syncProject($projectId, $projectFull);
            
            // Notify all apps about new project
            $crossApp->emit('projectRegistered', [
                'projectId'   => $projectId,
                'projectData' => $projectFull,
                'registeredBy'=> $_SESSION['user_id'] ?? 1
            ]);
            
            return [
                'success'   => true,
                'projectId' => $projectId,
                'message'   => 'Project registered successfully and synced across all apps'
            ];
            
        } catch (Throwable $e) {
            error_log("Project registration error: " . $e->getMessage());
            return [
                'success' => false,
                'error'   => 'Failed to register project: ' . $e->getMessage()
            ];
        }
    }
}

// NEW: Real-time system status monitoring
if (!function_exists('get_system_status')) {
    function get_system_status() {
        $crossApp = CrossAppCommunicator::getInstance();
        
        return [
            'cross_app_communication' => 'active',
            'data_sync'               => 'synchronized',
            'print_system'            => 'ready',
            'photo_management'        => 'active',
            'last_system_check'       => date('Y-m-d H:i:s'),
            'active_users'            => $crossApp->getData('active_users', 'system', 1),
            'pending_messages'        => count($crossApp->getAllAppData('messages')),
            // Expose global version/env
            'app_version'             => APP_VERSION,
            'app_env'                 => APP_ENV
        ];
    }
}

// ============================================================================
// CURRENCIES & DONORS - Database-backed helper functions for all apps
// ============================================================================

/**
 * Initialize currencies and donors tables if they don't exist
 */
if (!function_exists('init_currencies_donors_tables')) {
    function init_currencies_donors_tables(?PDO $pdo = null) {
        if (!$pdo) $pdo = getPDO();
        
        try {
            // Create currencies table
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS system_currencies (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    code VARCHAR(10) NOT NULL UNIQUE,
                    name VARCHAR(100) NOT NULL,
                    symbol VARCHAR(10),
                    region VARCHAR(50),
                    default_rate DECIMAL(15,6) DEFAULT 1.0,
                    is_active TINYINT(1) DEFAULT 1,
                    display_order INT DEFAULT 0,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP NULL ON UPDATE CURRENT_TIMESTAMP,
                    INDEX idx_code (code),
                    INDEX idx_active (is_active),
                    INDEX idx_region (region)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
            
            // Ensure gms_donors table exists (may already exist from grants.php)
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS gms_donors (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    name VARCHAR(255) NOT NULL,
                    short_name VARCHAR(100),
                    donor_type ENUM('UN Agency', 'Bilateral Donor', 'Pooled Fund', 'Foundation', 'Corporate', 'Government', 'Other') NOT NULL,
                    contact_name VARCHAR(255),
                    contact_email VARCHAR(255),
                    contact_phone VARCHAR(50),
                    website VARCHAR(255),
                    address TEXT,
                    country VARCHAR(100),
                    notes TEXT,
                    currency VARCHAR(10) DEFAULT 'USD',
                    is_active TINYINT(1) DEFAULT 1,
                    display_order INT DEFAULT 0,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP NULL ON UPDATE CURRENT_TIMESTAMP,
                    INDEX idx_donor_type (donor_type),
                    INDEX idx_active (is_active),
                    INDEX idx_name (name),
                    INDEX idx_currency (currency)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
            
            // Add currency column if it doesn't exist (for existing tables)
            try {
                $pdo->exec("ALTER TABLE gms_donors ADD COLUMN currency VARCHAR(10) DEFAULT 'USD'");
            } catch (PDOException $e) {
                // Column may already exist, ignore
            }
        } catch (PDOException $e) {
            error_log("Error initializing currencies/donors tables: " . $e->getMessage());
        }
    }
}

/**
 * Seed comprehensive currencies into database
 */
if (!function_exists('seed_comprehensive_currencies')) {
    function seed_comprehensive_currencies(?PDO $pdo = null) {
        if (!$pdo) $pdo = getPDO();
        
        $currencies = [
            // Major Currencies
            ['code' => 'USD', 'name' => 'US Dollar', 'symbol' => '$', 'region' => 'Major', 'default_rate' => 55.5, 'display_order' => 1],
            ['code' => 'EUR', 'name' => 'Euro', 'symbol' => '€', 'region' => 'Major', 'default_rate' => 60.2, 'display_order' => 2],
            ['code' => 'GBP', 'name' => 'Pound Sterling', 'symbol' => '£', 'region' => 'Major', 'default_rate' => 70.1, 'display_order' => 3],
            ['code' => 'CHF', 'name' => 'Swiss Franc', 'symbol' => 'CHF', 'region' => 'Major', 'default_rate' => 61.5, 'display_order' => 4],
            ['code' => 'JPY', 'name' => 'Japanese Yen', 'symbol' => '¥', 'region' => 'Major', 'default_rate' => 0.37, 'display_order' => 5],
            ['code' => 'CNY', 'name' => 'Chinese Yuan Renminbi', 'symbol' => '¥', 'region' => 'Major', 'default_rate' => 7.8, 'display_order' => 6],
            ['code' => 'CAD', 'name' => 'Canadian Dollar', 'symbol' => 'C$', 'region' => 'Major', 'default_rate' => 41.2, 'display_order' => 7],
            ['code' => 'AUD', 'name' => 'Australian Dollar', 'symbol' => 'A$', 'region' => 'Major', 'default_rate' => 36.8, 'display_order' => 8],
            ['code' => 'NZD', 'name' => 'New Zealand Dollar', 'symbol' => 'NZ$', 'region' => 'Major', 'default_rate' => 33.5, 'display_order' => 9],
            ['code' => 'SEK', 'name' => 'Swedish Krona', 'symbol' => 'kr', 'region' => 'Major', 'default_rate' => 5.2, 'display_order' => 10],
            ['code' => 'NOK', 'name' => 'Norwegian Krone', 'symbol' => 'kr', 'region' => 'Major', 'default_rate' => 5.1, 'display_order' => 11],
            ['code' => 'DKK', 'name' => 'Danish Krone', 'symbol' => 'kr', 'region' => 'Major', 'default_rate' => 8.1, 'display_order' => 12],
            
            // Ethiopia & Horn of Africa
            ['code' => 'ETB', 'name' => 'Ethiopian Birr', 'symbol' => 'Br', 'region' => 'Ethiopia & Horn', 'default_rate' => 1.0, 'display_order' => 100],
            ['code' => 'SOS', 'name' => 'Somali Shilling', 'symbol' => 'S', 'region' => 'Ethiopia & Horn', 'default_rate' => 0.08, 'display_order' => 101],
            ['code' => 'SSP', 'name' => 'South Sudanese Pound', 'symbol' => '£', 'region' => 'Ethiopia & Horn', 'default_rate' => 0.04, 'display_order' => 102],
            ['code' => 'SDG', 'name' => 'Sudanese Pound', 'symbol' => '£', 'region' => 'Ethiopia & Horn', 'default_rate' => 0.09, 'display_order' => 103],
            ['code' => 'KES', 'name' => 'Kenyan Shilling', 'symbol' => 'KSh', 'region' => 'Ethiopia & Horn', 'default_rate' => 0.42, 'display_order' => 104],
            ['code' => 'UGX', 'name' => 'Ugandan Shilling', 'symbol' => 'USh', 'region' => 'Ethiopia & Horn', 'default_rate' => 0.015, 'display_order' => 105],
            ['code' => 'TZS', 'name' => 'Tanzanian Shilling', 'symbol' => 'TSh', 'region' => 'Ethiopia & Horn', 'default_rate' => 0.024, 'display_order' => 106],
            ['code' => 'RWF', 'name' => 'Rwandan Franc', 'symbol' => 'RF', 'region' => 'Ethiopia & Horn', 'default_rate' => 0.044, 'display_order' => 107],
            ['code' => 'BIF', 'name' => 'Burundian Franc', 'symbol' => 'FBu', 'region' => 'Ethiopia & Horn', 'default_rate' => 0.019, 'display_order' => 108],
            ['code' => 'DJF', 'name' => 'Djiboutian Franc', 'symbol' => 'Fdj', 'region' => 'Ethiopia & Horn', 'default_rate' => 0.31, 'display_order' => 109],
            ['code' => 'ERN', 'name' => 'Eritrean Nakfa', 'symbol' => 'Nfk', 'region' => 'Ethiopia & Horn', 'default_rate' => 3.7, 'display_order' => 110],
            
            // Southern & Central Africa
            ['code' => 'ZAR', 'name' => 'South African Rand', 'symbol' => 'R', 'region' => 'Southern Africa', 'default_rate' => 3.0, 'display_order' => 200],
            ['code' => 'MWK', 'name' => 'Malawian Kwacha', 'symbol' => 'MK', 'region' => 'Southern Africa', 'default_rate' => 0.033, 'display_order' => 201],
            ['code' => 'ZMW', 'name' => 'Zambian Kwacha', 'symbol' => 'ZK', 'region' => 'Southern Africa', 'default_rate' => 2.1, 'display_order' => 202],
            ['code' => 'MZN', 'name' => 'Mozambican Metical', 'symbol' => 'MT', 'region' => 'Southern Africa', 'default_rate' => 0.87, 'display_order' => 203],
            ['code' => 'SZL', 'name' => 'Lilangeni', 'symbol' => 'L', 'region' => 'Southern Africa', 'default_rate' => 3.0, 'display_order' => 204],
            ['code' => 'LSL', 'name' => 'Lesotho Loti', 'symbol' => 'L', 'region' => 'Southern Africa', 'default_rate' => 3.0, 'display_order' => 205],
            ['code' => 'NAD', 'name' => 'Namibian Dollar', 'symbol' => 'N$', 'region' => 'Southern Africa', 'default_rate' => 3.0, 'display_order' => 206],
            ['code' => 'BWP', 'name' => 'Botswana Pula', 'symbol' => 'P', 'region' => 'Southern Africa', 'default_rate' => 4.1, 'display_order' => 207],
            ['code' => 'MUR', 'name' => 'Mauritian Rupee', 'symbol' => '₨', 'region' => 'Southern Africa', 'default_rate' => 1.2, 'display_order' => 208],
            ['code' => 'SCR', 'name' => 'Seychelles Rupee', 'symbol' => '₨', 'region' => 'Southern Africa', 'default_rate' => 4.1, 'display_order' => 209],
            
            // West Africa
            ['code' => 'XAF', 'name' => 'CFA Franc BEAC', 'symbol' => 'FCFA', 'region' => 'West Africa', 'default_rate' => 0.091, 'display_order' => 300],
            ['code' => 'XOF', 'name' => 'CFA Franc BCEAO', 'symbol' => 'FCFA', 'region' => 'West Africa', 'default_rate' => 0.091, 'display_order' => 301],
            ['code' => 'NGN', 'name' => 'Nigerian Naira', 'symbol' => '₦', 'region' => 'West Africa', 'default_rate' => 0.066, 'display_order' => 302],
            ['code' => 'GHS', 'name' => 'Ghanaian Cedi', 'symbol' => '₵', 'region' => 'West Africa', 'default_rate' => 4.6, 'display_order' => 303],
            ['code' => 'SLL', 'name' => 'Sierra Leonean Leone', 'symbol' => 'Le', 'region' => 'West Africa', 'default_rate' => 0.0029, 'display_order' => 304],
            ['code' => 'LRD', 'name' => 'Liberian Dollar', 'symbol' => '$', 'region' => 'West Africa', 'default_rate' => 0.29, 'display_order' => 305],
            ['code' => 'GNF', 'name' => 'Guinean Franc', 'symbol' => 'FG', 'region' => 'West Africa', 'default_rate' => 0.0061, 'display_order' => 306],
            ['code' => 'CVE', 'name' => 'Cape Verdean Escudo', 'symbol' => 'Esc', 'region' => 'West Africa', 'default_rate' => 0.55, 'display_order' => 307],
            ['code' => 'MRU', 'name' => 'Mauritanian Ouguiya', 'symbol' => 'UM', 'region' => 'West Africa', 'default_rate' => 0.15, 'display_order' => 308],
            
            // North Africa
            ['code' => 'EGP', 'name' => 'Egyptian Pound', 'symbol' => '£', 'region' => 'North Africa', 'default_rate' => 1.8, 'display_order' => 400],
            ['code' => 'MAD', 'name' => 'Moroccan Dirham', 'symbol' => 'DH', 'region' => 'North Africa', 'default_rate' => 5.5, 'display_order' => 401],
            ['code' => 'TND', 'name' => 'Tunisian Dinar', 'symbol' => 'DT', 'region' => 'North Africa', 'default_rate' => 18.0, 'display_order' => 402],
            ['code' => 'DZD', 'name' => 'Algerian Dinar', 'symbol' => 'د.ج', 'region' => 'North Africa', 'default_rate' => 0.40, 'display_order' => 403],
            ['code' => 'LYD', 'name' => 'Libyan Dinar', 'symbol' => 'LD', 'region' => 'North Africa', 'default_rate' => 11.5, 'display_order' => 404],
            
            // Middle East & Gulf
            ['code' => 'SAR', 'name' => 'Saudi Riyal', 'symbol' => '﷼', 'region' => 'Middle East', 'default_rate' => 14.8, 'display_order' => 500],
            ['code' => 'QAR', 'name' => 'Qatari Riyal', 'symbol' => '﷼', 'region' => 'Middle East', 'default_rate' => 15.2, 'display_order' => 501],
            ['code' => 'AED', 'name' => 'UAE Dirham', 'symbol' => 'د.إ', 'region' => 'Middle East', 'default_rate' => 15.1, 'display_order' => 502],
            ['code' => 'KWD', 'name' => 'Kuwaiti Dinar', 'symbol' => 'د.ك', 'region' => 'Middle East', 'default_rate' => 180.0, 'display_order' => 503],
            ['code' => 'YER', 'name' => 'Yemeni Rial', 'symbol' => '﷼', 'region' => 'Middle East', 'default_rate' => 0.22, 'display_order' => 504],
            ['code' => 'JOD', 'name' => 'Jordanian Dinar', 'symbol' => 'د.ا', 'region' => 'Middle East', 'default_rate' => 78.3, 'display_order' => 505],
            ['code' => 'ILS', 'name' => 'Israeli New Shekel', 'symbol' => '₪', 'region' => 'Middle East', 'default_rate' => 15.0, 'display_order' => 506],
            ['code' => 'LBP', 'name' => 'Lebanese Pound', 'symbol' => '£', 'region' => 'Middle East', 'default_rate' => 0.037, 'display_order' => 507],
            
            // Asia
            ['code' => 'INR', 'name' => 'Indian Rupee', 'symbol' => '₹', 'region' => 'Asia', 'default_rate' => 0.67, 'display_order' => 600],
            ['code' => 'SGD', 'name' => 'Singapore Dollar', 'symbol' => 'S$', 'region' => 'Asia', 'default_rate' => 41.0, 'display_order' => 601],
            ['code' => 'HKD', 'name' => 'Hong Kong Dollar', 'symbol' => 'HK$', 'region' => 'Asia', 'default_rate' => 7.1, 'display_order' => 602],
            ['code' => 'KRW', 'name' => 'South Korean Won', 'symbol' => '₩', 'region' => 'Asia', 'default_rate' => 0.042, 'display_order' => 603],
            ['code' => 'BDT', 'name' => 'Bangladeshi Taka', 'symbol' => '৳', 'region' => 'Asia', 'default_rate' => 0.51, 'display_order' => 604],
            ['code' => 'PKR', 'name' => 'Pakistani Rupee', 'symbol' => '₨', 'region' => 'Asia', 'default_rate' => 0.20, 'display_order' => 605],
            ['code' => 'LKR', 'name' => 'Sri Lankan Rupee', 'symbol' => '₨', 'region' => 'Asia', 'default_rate' => 0.17, 'display_order' => 606],
            ['code' => 'NPR', 'name' => 'Nepalese Rupee', 'symbol' => '₨', 'region' => 'Asia', 'default_rate' => 0.42, 'display_order' => 607],
            ['code' => 'MMK', 'name' => 'Myanmar Kyat', 'symbol' => 'K', 'region' => 'Asia', 'default_rate' => 0.027, 'display_order' => 608],
            ['code' => 'KHR', 'name' => 'Cambodian Riel', 'symbol' => '៛', 'region' => 'Asia', 'default_rate' => 0.013, 'display_order' => 609],
            ['code' => 'THB', 'name' => 'Thai Baht', 'symbol' => '฿', 'region' => 'Asia', 'default_rate' => 1.5, 'display_order' => 610],
            ['code' => 'VND', 'name' => 'Vietnamese Dong', 'symbol' => '₫', 'region' => 'Asia', 'default_rate' => 0.0023, 'display_order' => 611],
            ['code' => 'MYR', 'name' => 'Malaysian Ringgit', 'symbol' => 'RM', 'region' => 'Asia', 'default_rate' => 11.8, 'display_order' => 612],
            ['code' => 'PHP', 'name' => 'Philippine Peso', 'symbol' => '₱', 'region' => 'Asia', 'default_rate' => 0.99, 'display_order' => 613],
            ['code' => 'IDR', 'name' => 'Indonesian Rupiah', 'symbol' => 'Rp', 'region' => 'Asia', 'default_rate' => 0.0037, 'display_order' => 614],
            
            // Europe
            ['code' => 'RUB', 'name' => 'Russian Ruble', 'symbol' => '₽', 'region' => 'Europe', 'default_rate' => 0.60, 'display_order' => 700],
            ['code' => 'TRY', 'name' => 'Turkish Lira', 'symbol' => '₺', 'region' => 'Europe', 'default_rate' => 1.9, 'display_order' => 701],
            ['code' => 'UAH', 'name' => 'Ukrainian Hryvnia', 'symbol' => '₴', 'region' => 'Europe', 'default_rate' => 1.5, 'display_order' => 702],
            ['code' => 'PLN', 'name' => 'Polish Zloty', 'symbol' => 'zł', 'region' => 'Europe', 'default_rate' => 13.8, 'display_order' => 703],
            ['code' => 'CZK', 'name' => 'Czech Koruna', 'symbol' => 'Kč', 'region' => 'Europe', 'default_rate' => 2.4, 'display_order' => 704],
            ['code' => 'HUF', 'name' => 'Hungarian Forint', 'symbol' => 'Ft', 'region' => 'Europe', 'default_rate' => 0.15, 'display_order' => 705],
            ['code' => 'RON', 'name' => 'Romanian Leu', 'symbol' => 'lei', 'region' => 'Europe', 'default_rate' => 12.1, 'display_order' => 706],
            ['code' => 'BGN', 'name' => 'Bulgarian Lev', 'symbol' => 'лв', 'region' => 'Europe', 'default_rate' => 30.8, 'display_order' => 707],
            ['code' => 'RSD', 'name' => 'Serbian Dinar', 'symbol' => 'дин', 'region' => 'Europe', 'default_rate' => 0.52, 'display_order' => 708],
            
            // Latin America & Caribbean
            ['code' => 'BRL', 'name' => 'Brazilian Real', 'symbol' => 'R$', 'region' => 'Latin America', 'default_rate' => 10.5, 'display_order' => 800],
            ['code' => 'MXN', 'name' => 'Mexican Peso', 'symbol' => '$', 'region' => 'Latin America', 'default_rate' => 3.2, 'display_order' => 801],
            ['code' => 'ARS', 'name' => 'Argentine Peso', 'symbol' => '$', 'region' => 'Latin America', 'default_rate' => 0.063, 'display_order' => 802],
            ['code' => 'CLP', 'name' => 'Chilean Peso', 'symbol' => '$', 'region' => 'Latin America', 'default_rate' => 0.061, 'display_order' => 803],
            ['code' => 'PEN', 'name' => 'Peruvian Sol', 'symbol' => 'S/', 'region' => 'Latin America', 'default_rate' => 14.8, 'display_order' => 804],
            ['code' => 'COP', 'name' => 'Colombian Peso', 'symbol' => '$', 'region' => 'Latin America', 'default_rate' => 0.013, 'display_order' => 805],
            ['code' => 'UYU', 'name' => 'Uruguayan Peso', 'symbol' => '$U', 'region' => 'Latin America', 'default_rate' => 1.4, 'display_order' => 806],
            ['code' => 'BOB', 'name' => 'Boliviano', 'symbol' => 'Bs.', 'region' => 'Latin America', 'default_rate' => 8.0, 'display_order' => 807],
            ['code' => 'PYG', 'name' => 'Paraguayan Guaraní', 'symbol' => '₲', 'region' => 'Latin America', 'default_rate' => 0.0075, 'display_order' => 808],
            ['code' => 'XCD', 'name' => 'East Caribbean Dollar', 'symbol' => '$', 'region' => 'Latin America', 'default_rate' => 20.5, 'display_order' => 809],
            ['code' => 'DOP', 'name' => 'Dominican Peso', 'symbol' => '$', 'region' => 'Latin America', 'default_rate' => 0.98, 'display_order' => 810],
            ['code' => 'HTG', 'name' => 'Haitian Gourde', 'symbol' => 'G', 'region' => 'Latin America', 'default_rate' => 0.39, 'display_order' => 811],
            
            // Oceania & Pacific
            ['code' => 'PGK', 'name' => 'Papua New Guinean Kina', 'symbol' => 'K', 'region' => 'Oceania', 'default_rate' => 14.5, 'display_order' => 900],
            ['code' => 'FJD', 'name' => 'Fijian Dollar', 'symbol' => 'FJ$', 'region' => 'Oceania', 'default_rate' => 24.8, 'display_order' => 901],
        ];
        
        $stmt = $pdo->prepare("INSERT IGNORE INTO system_currencies (code, name, symbol, region, default_rate, display_order, is_active) VALUES (?, ?, ?, ?, ?, ?, 1)");
        foreach ($currencies as $currency) {
            $stmt->execute([
                $currency['code'],
                $currency['name'],
                $currency['symbol'],
                $currency['region'],
                $currency['default_rate'],
                $currency['display_order']
            ]);
        }
    }
}

/**
 * Get all currencies from database
 */
if (!function_exists('get_all_currencies')) {
    function get_all_currencies(?PDO $pdo = null, $include_inactive = false) {
        if (!$pdo) $pdo = getPDO();
        
        try {
            init_currencies_donors_tables($pdo);
            
            // Check if currencies exist, if not seed them
            $countStmt = $pdo->query("SELECT COUNT(*) as cnt FROM system_currencies");
            $count = $countStmt->fetch(PDO::FETCH_ASSOC)['cnt'] ?? 0;
            if ($count == 0) {
                seed_comprehensive_currencies($pdo);
            }
            
            $sql = "SELECT * FROM system_currencies WHERE is_active = 1 ORDER BY display_order, code";
            if ($include_inactive) {
                $sql = "SELECT * FROM system_currencies ORDER BY display_order, code";
            }
            $stmt = $pdo->query($sql);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Error fetching currencies: " . $e->getMessage());
            return [];
        }
    }
}

/**
 * Get all donors from database
 */
if (!function_exists('get_all_donors')) {
    function get_all_donors(?PDO $pdo = null, $include_inactive = false) {
        if (!$pdo) $pdo = getPDO();
        
        try {
            init_currencies_donors_tables($pdo);
            
            // Always ensure donors are seeded (function exists in grants.php or helpers.php)
            // First, try to load the seed function if it's not available
            if (!function_exists('seed_comprehensive_donors')) {
                // Try to load from grants.php
                $grantsFile = __DIR__ . '/pages/grants.php';
                if (file_exists($grantsFile)) {
                    // Extract just the function definition using regex or include
                    $content = file_get_contents($grantsFile);
                    if (preg_match('/function\s+seed_comprehensive_donors\s*\([^)]*\)\s*:\s*void\s*\{[^}]*\}/s', $content)) {
                        // Function exists in grants.php, we'll call it via file include
                        // But we can't include the whole file here, so we'll handle it differently
                    }
                }
            }
            
            // Try to seed if function is available
            if (function_exists('seed_comprehensive_donors')) {
                try {
                    seed_comprehensive_donors($pdo);
                } catch (Exception $e) {
                    error_log("Error seeding donors in get_all_donors: " . $e->getMessage());
                }
            }
            
            // Get all active donors, ordered by name (individual list, not grouped)
            $sql = "SELECT * FROM gms_donors WHERE is_active = 1 ORDER BY name ASC";
            if ($include_inactive) {
                $sql = "SELECT * FROM gms_donors ORDER BY name ASC";
            }
            
            try {
                $stmt = $pdo->query($sql);
                $donors = $stmt->fetchAll(PDO::FETCH_ASSOC);
            } catch (PDOException $e) {
                error_log("Error querying donors: " . $e->getMessage());
                $donors = [];
            }
            
            // Debug: Log if no donors found
            if (empty($donors)) {
                error_log("WARNING: No donors found in database. Count: 0");
                // Try to force seed by checking if table exists and seeding
                try {
                    $tableCheck = $pdo->query("SHOW TABLES LIKE 'gms_donors'");
                    if ($tableCheck && $tableCheck->rowCount() > 0) {
                        // Table exists but empty, try seeding again
                        if (function_exists('seed_comprehensive_donors')) {
                            error_log("Attempting to seed donors again...");
                            seed_comprehensive_donors($pdo);
                            // Retry query
                            $stmt = $pdo->query($sql);
                            $donors = $stmt->fetchAll(PDO::FETCH_ASSOC);
                            error_log("After re-seed, found " . count($donors) . " donors");
                        }
                    }
                } catch (Exception $e) {
                    error_log("Error in donor seeding retry: " . $e->getMessage());
                }
            } else {
                error_log("Successfully loaded " . count($donors) . " donors from database");
            }
            
            return $donors;
        } catch (PDOException $e) {
            error_log("Error fetching donors: " . $e->getMessage());
            return [];
        }
    }
}

/**
 * Generate currency dropdown HTML options
 */
if (!function_exists('currency_dropdown_options')) {
    function currency_dropdown_options($current = 'USD', $include_other = true, $grouped = false) {
        $currencies = get_all_currencies();
        $html = '';
        
        if ($grouped) {
            $byRegion = [];
            foreach ($currencies as $curr) {
                $region = $curr['region'] ?? 'Other';
                if (!isset($byRegion[$region])) {
                    $byRegion[$region] = [];
                }
                $byRegion[$region][] = $curr;
            }
            
            foreach ($byRegion as $region => $regionCurrencies) {
                $html .= '<optgroup label="' . htmlspecialchars($region) . '">';
                foreach ($regionCurrencies as $curr) {
                    $selected = ($curr['code'] === $current) ? 'selected' : '';
                    $label = $curr['code'] . ' - ' . $curr['name'];
                    $defaultRate = $curr['default_rate'] ?? 1.0;
                    $html .= '<option value="' . htmlspecialchars($curr['code']) . '" data-rate="' . htmlspecialchars($defaultRate) . '" ' . $selected . '>' . htmlspecialchars($label) . '</option>';
                }
                $html .= '</optgroup>';
            }
        } else {
            foreach ($currencies as $curr) {
                $selected = ($curr['code'] === $current) ? 'selected' : '';
                $label = $curr['code'] . ' - ' . $curr['name'];
                $defaultRate = $curr['default_rate'] ?? 1.0;
                $html .= '<option value="' . htmlspecialchars($curr['code']) . '" data-rate="' . htmlspecialchars($defaultRate) . '" ' . $selected . '>' . htmlspecialchars($label) . '</option>';
            }
        }
        
        if ($include_other) {
            $html .= '<option value="OTHER"' . ($current === 'OTHER' ? ' selected' : '') . '>Other (Specify below)</option>';
        }
        
        return $html;
    }
}

/**
 * Generate donor dropdown HTML options
 */
if (!function_exists('donor_dropdown_options')) {
    function donor_dropdown_options($current = '', $include_other = true, $grouped = false) {
        $donors = get_all_donors();
        $html = '<option value="">-- Select Donor --</option>';
        
        if ($grouped) {
            $byType = [];
            foreach ($donors as $donor) {
                $type = $donor['donor_type'] ?? 'Other';
                if (!isset($byType[$type])) {
                    $byType[$type] = [];
                }
                $byType[$type][] = $donor;
            }
            
            $typeOrder = ['UN Agency', 'Pooled Fund', 'Bilateral Donor', 'Foundation', 'Other'];
            foreach ($typeOrder as $type) {
                if (isset($byType[$type]) && !empty($byType[$type])) {
                    $typeLabel = $type === 'UN Agency' ? 'UN Agencies' : 
                                ($type === 'Pooled Fund' ? 'Pooled Funds' : 
                                ($type === 'Bilateral Donor' ? 'Major Bilateral Donors' : 
                                ($type === 'Foundation' ? 'International Foundations' : 'INGOs, NNGOs & Other')));
                    $html .= '<optgroup label="' . htmlspecialchars($typeLabel) . '">';
                    foreach ($byType[$type] as $donor) {
                        $selected = ($donor['id'] == $current) ? 'selected' : '';
                        $displayName = !empty($donor['short_name']) ? $donor['short_name'] . ' - ' . $donor['name'] : $donor['name'];
                        $currency = $donor['currency'] ?? 'USD';
                        $html .= '<option value="' . $donor['id'] . '" data-currency="' . htmlspecialchars($currency) . '" ' . $selected . '>' . htmlspecialchars($displayName) . '</option>';
                    }
                    $html .= '</optgroup>';
                }
            }
            
            // Show remaining types
            foreach ($byType as $type => $donorsList) {
                if (!in_array($type, $typeOrder) && !empty($donorsList)) {
                    $html .= '<optgroup label="' . htmlspecialchars($type) . '">';
                    foreach ($donorsList as $donor) {
                        $selected = ($donor['id'] == $current) ? 'selected' : '';
                        $displayName = !empty($donor['short_name']) ? $donor['short_name'] . ' - ' . $donor['name'] : $donor['name'];
                        $currency = $donor['currency'] ?? 'USD';
                        $html .= '<option value="' . $donor['id'] . '" data-currency="' . htmlspecialchars($currency) . '" ' . $selected . '>' . htmlspecialchars($displayName) . '</option>';
                    }
                    $html .= '</optgroup>';
                }
            }
        } else {
            foreach ($donors as $donor) {
                $selected = ($donor['id'] == $current) ? 'selected' : '';
                $displayName = !empty($donor['short_name']) ? $donor['short_name'] . ' - ' . $donor['name'] : $donor['name'];
                $currency = $donor['currency'] ?? 'USD';
                $shortName = $donor['short_name'] ?? '';
                $donorType = $donor['donor_type'] ?? 'Other';
                $html .= '<option value="' . $donor['id'] . '" data-currency="' . htmlspecialchars($currency) . '" data-short-name="' . htmlspecialchars($shortName) . '" data-donor-type="' . htmlspecialchars($donorType) . '" ' . $selected . '>' . htmlspecialchars($displayName) . '</option>';
            }
        }
        
        if ($include_other) {
            $html .= '<option value="OTHER"' . ($current === 'OTHER' ? ' selected' : '') . '>Other (Specify below)</option>';
        }
        
        return $html;
    }
}

/**
 * Comprehensive Donor Seeding Function - Make it globally accessible
 * This function is also defined in pages/grants.php, but we include it here
 * to ensure it's always available across all applications
 */
if (!function_exists('seed_comprehensive_donors')) {
    function seed_comprehensive_donors(?PDO $pdo = null): void {
        if (!$pdo) $pdo = getPDO();
        
        // Ensure table exists first
        if (function_exists('init_currencies_donors_tables')) {
            init_currencies_donors_tables($pdo);
        }
        
        $donors = [
            // UN Agencies - Starting with EHF/UNOCHA as requested
            ['name' => 'EHF/UNOCHA', 'short_name' => 'EHF/UNOCHA', 'donor_type' => 'Pooled Fund'],
            ['name' => 'UNOCHA - Office for the Coordination of Humanitarian Affairs', 'short_name' => 'UNOCHA', 'donor_type' => 'UN Agency'],
            ['name' => 'UNDP - United Nations Development Programme', 'short_name' => 'UNDP', 'donor_type' => 'UN Agency'],
            ['name' => 'UNICEF - United Nations Children\'s Fund', 'short_name' => 'UNICEF', 'donor_type' => 'UN Agency'],
            ['name' => 'UNHCR - United Nations High Commissioner for Refugees', 'short_name' => 'UNHCR', 'donor_type' => 'UN Agency'],
            ['name' => 'WFP - World Food Programme', 'short_name' => 'WFP', 'donor_type' => 'UN Agency'],
            ['name' => 'WHO - World Health Organization', 'short_name' => 'WHO', 'donor_type' => 'UN Agency'],
            ['name' => 'UNFPA - United Nations Population Fund', 'short_name' => 'UNFPA', 'donor_type' => 'UN Agency'],
            ['name' => 'UNESCO - United Nations Educational, Scientific and Cultural Organization', 'short_name' => 'UNESCO', 'donor_type' => 'UN Agency'],
            ['name' => 'FAO - Food and Agriculture Organization', 'short_name' => 'FAO', 'donor_type' => 'UN Agency'],
            ['name' => 'ILO - International Labour Organization', 'short_name' => 'ILO', 'donor_type' => 'UN Agency'],
            ['name' => 'UN Women - United Nations Entity for Gender Equality', 'short_name' => 'UN Women', 'donor_type' => 'UN Agency'],
            ['name' => 'UNEP - United Nations Environment Programme', 'short_name' => 'UNEP', 'donor_type' => 'UN Agency'],
            ['name' => 'UNIDO - United Nations Industrial Development Organization', 'short_name' => 'UNIDO', 'donor_type' => 'UN Agency'],
            ['name' => 'UN-Habitat - United Nations Human Settlements Programme', 'short_name' => 'UN-Habitat', 'donor_type' => 'UN Agency'],
            ['name' => 'IOM - International Organization for Migration', 'short_name' => 'IOM', 'donor_type' => 'UN Agency'],
            ['name' => 'IFAD - International Fund for Agricultural Development', 'short_name' => 'IFAD', 'donor_type' => 'UN Agency'],
            ['name' => 'ITC - International Trade Centre', 'short_name' => 'ITC', 'donor_type' => 'UN Agency'],
            ['name' => 'ITU - International Telecommunication Union', 'short_name' => 'ITU', 'donor_type' => 'UN Agency'],
            ['name' => 'OHCHR - Office of the High Commissioner for Human Rights', 'short_name' => 'OHCHR', 'donor_type' => 'UN Agency'],
            ['name' => 'UNECA - UN Economic Commission for Africa', 'short_name' => 'UNECA', 'donor_type' => 'UN Agency'],
            ['name' => 'UNAIDS - Joint UN Programme on HIV/AIDS', 'short_name' => 'UNAIDS', 'donor_type' => 'UN Agency'],
            ['name' => 'UNCDF - UN Capital Development Fund', 'short_name' => 'UNCDF', 'donor_type' => 'UN Agency'],
            ['name' => 'UNCTAD - UN Conference on Trade and Development', 'short_name' => 'UNCTAD', 'donor_type' => 'UN Agency'],
            ['name' => 'UNDRR - UN Office for Disaster Risk Reduction', 'short_name' => 'UNDRR', 'donor_type' => 'UN Agency'],
            ['name' => 'UNOAU - UN Office to the African Union', 'short_name' => 'UNOAU', 'donor_type' => 'UN Agency'],
            ['name' => 'UNODC - UN Office on Drugs and Crime', 'short_name' => 'UNODC', 'donor_type' => 'UN Agency'],
            ['name' => 'UNOPS - UN Office for Project Services', 'short_name' => 'UNOPS', 'donor_type' => 'UN Agency'],
            
            // Multilateral Development Banks & Global Funds
            ['name' => 'World Bank Group - IDA', 'short_name' => 'World Bank IDA', 'donor_type' => 'Other'],
            ['name' => 'World Bank Group - IBRD', 'short_name' => 'World Bank IBRD', 'donor_type' => 'Other'],
            ['name' => 'World Bank Group - IFC', 'short_name' => 'World Bank IFC', 'donor_type' => 'Other'],
            ['name' => 'African Development Bank (AfDB)', 'short_name' => 'AfDB', 'donor_type' => 'Other'],
            ['name' => 'European Investment Bank (EIB)', 'short_name' => 'EIB', 'donor_type' => 'Other'],
            ['name' => 'Islamic Development Bank (IsDB)', 'short_name' => 'IsDB', 'donor_type' => 'Other'],
            ['name' => 'European Bank for Reconstruction and Development (EBRD)', 'short_name' => 'EBRD', 'donor_type' => 'Other'],
            ['name' => 'Arab Fund for Economic and Social Development', 'short_name' => 'Arab Fund', 'donor_type' => 'Other'],
            ['name' => 'OPEC Fund for International Development (OFID)', 'short_name' => 'OFID', 'donor_type' => 'Other'],
            ['name' => 'The Global Fund to Fight AIDS, Tuberculosis and Malaria', 'short_name' => 'Global Fund', 'donor_type' => 'Foundation'],
            ['name' => 'Gavi, the Vaccine Alliance', 'short_name' => 'Gavi', 'donor_type' => 'Foundation'],
            ['name' => 'Global Environment Facility (GEF)', 'short_name' => 'GEF', 'donor_type' => 'Foundation'],
            ['name' => 'Green Climate Fund (GCF)', 'short_name' => 'GCF', 'donor_type' => 'Foundation'],
            ['name' => 'Adaptation Fund', 'short_name' => 'Adaptation Fund', 'donor_type' => 'Foundation'],
            ['name' => 'Global Partnership for Education (GPE)', 'short_name' => 'GPE', 'donor_type' => 'Foundation'],
            ['name' => 'Education Cannot Wait (ECW)', 'short_name' => 'ECW', 'donor_type' => 'Foundation'],
            ['name' => 'UN Central Emergency Response Fund (CERF)', 'short_name' => 'CERF', 'donor_type' => 'Pooled Fund'],
            ['name' => 'Country-Based Pooled Funds (CBPF)', 'short_name' => 'CBPF', 'donor_type' => 'Pooled Fund'],
            
            // Major Bilateral Donors
            ['name' => 'USAID - United States Agency for International Development', 'short_name' => 'USAID', 'donor_type' => 'Bilateral Donor'],
            ['name' => 'US Department of State', 'short_name' => 'US State Dept', 'donor_type' => 'Bilateral Donor'],
            ['name' => 'CDC - Centers for Disease Control and Prevention', 'short_name' => 'CDC', 'donor_type' => 'Bilateral Donor'],
            ['name' => 'FCDO - UK Foreign, Commonwealth & Development Office (UK Aid)', 'short_name' => 'FCDO', 'donor_type' => 'Bilateral Donor'],
            ['name' => 'BMZ - German Federal Ministry for Economic Cooperation and Development', 'short_name' => 'BMZ', 'donor_type' => 'Bilateral Donor'],
            ['name' => 'GIZ - Deutsche Gesellschaft für Internationale Zusammenarbeit', 'short_name' => 'GIZ', 'donor_type' => 'Bilateral Donor'],
            ['name' => 'KfW - KfW Development Bank', 'short_name' => 'KfW', 'donor_type' => 'Bilateral Donor'],
            ['name' => 'European Union - European Commission (DG INTPA)', 'short_name' => 'EU DG INTPA', 'donor_type' => 'Bilateral Donor'],
            ['name' => 'DG ECHO - European Civil Protection and Humanitarian Aid Operations', 'short_name' => 'ECHO', 'donor_type' => 'Bilateral Donor'],
            ['name' => 'Sida - Swedish International Development Cooperation Agency', 'short_name' => 'Sida', 'donor_type' => 'Bilateral Donor'],
            ['name' => 'NORAD - Norwegian Agency for Development Cooperation', 'short_name' => 'NORAD', 'donor_type' => 'Bilateral Donor'],
            ['name' => 'Netherlands MFA / RVO', 'short_name' => 'Netherlands MFA', 'donor_type' => 'Bilateral Donor'],
            ['name' => 'DANIDA - Danish International Development Agency', 'short_name' => 'DANIDA', 'donor_type' => 'Bilateral Donor'],
            ['name' => 'SDC - Swiss Agency for Development and Cooperation', 'short_name' => 'SDC', 'donor_type' => 'Bilateral Donor'],
            ['name' => 'Global Affairs Canada', 'short_name' => 'Canada GAC', 'donor_type' => 'Bilateral Donor'],
            ['name' => 'AFD - Agence Française de Développement', 'short_name' => 'AFD', 'donor_type' => 'Bilateral Donor'],
            ['name' => 'AICS - Italian Agency for Development Cooperation', 'short_name' => 'AICS', 'donor_type' => 'Bilateral Donor'],
            ['name' => 'AECID - Spanish Agency for International Development Cooperation', 'short_name' => 'AECID', 'donor_type' => 'Bilateral Donor'],
            ['name' => 'Enabel - Belgian Development Agency', 'short_name' => 'Enabel', 'donor_type' => 'Bilateral Donor'],
            ['name' => 'Irish Aid', 'short_name' => 'Irish Aid', 'donor_type' => 'Bilateral Donor'],
            ['name' => 'Ministry for Foreign Affairs of Finland', 'short_name' => 'Finland MFA', 'donor_type' => 'Bilateral Donor'],
            ['name' => 'ADA - Austrian Development Agency', 'short_name' => 'ADA', 'donor_type' => 'Bilateral Donor'],
            ['name' => 'JICA - Japan International Cooperation Agency', 'short_name' => 'JICA', 'donor_type' => 'Bilateral Donor'],
            ['name' => 'KOICA - Korea International Cooperation Agency', 'short_name' => 'KOICA', 'donor_type' => 'Bilateral Donor'],
            ['name' => 'TİKA - Turkish Cooperation and Coordination Agency', 'short_name' => 'TİKA', 'donor_type' => 'Bilateral Donor'],
            ['name' => 'Chinese Cooperation Funds', 'short_name' => 'China Funds', 'donor_type' => 'Bilateral Donor'],
            ['name' => 'Saudi Fund for Development', 'short_name' => 'Saudi Fund', 'donor_type' => 'Bilateral Donor'],
            ['name' => 'Qatar Fund for Development', 'short_name' => 'Qatar Fund', 'donor_type' => 'Bilateral Donor'],
            ['name' => 'Kuwait Fund for Arab Economic Development', 'short_name' => 'Kuwait Fund', 'donor_type' => 'Bilateral Donor'],
            ['name' => 'Abu Dhabi Fund for Development', 'short_name' => 'Abu Dhabi Fund', 'donor_type' => 'Bilateral Donor'],
            
            // Major INGOs
            ['name' => 'Save the Children International', 'short_name' => 'Save the Children', 'donor_type' => 'Other'],
            ['name' => 'World Vision International', 'short_name' => 'World Vision', 'donor_type' => 'Other'],
            ['name' => 'Plan International', 'short_name' => 'Plan Intl', 'donor_type' => 'Other'],
            ['name' => 'CARE International', 'short_name' => 'CARE', 'donor_type' => 'Other'],
            ['name' => 'Oxfam', 'short_name' => 'Oxfam', 'donor_type' => 'Other'],
            ['name' => 'Mercy Corps', 'short_name' => 'Mercy Corps', 'donor_type' => 'Other'],
            ['name' => 'International Rescue Committee (IRC)', 'short_name' => 'IRC', 'donor_type' => 'Other'],
            ['name' => 'Norwegian Refugee Council (NRC)', 'short_name' => 'NRC', 'donor_type' => 'Other'],
            ['name' => 'Danish Refugee Council (DRC)', 'short_name' => 'DRC', 'donor_type' => 'Other'],
            ['name' => 'Catholic Relief Services (CRS)', 'short_name' => 'CRS', 'donor_type' => 'Other'],
            ['name' => 'Caritas Internationalis', 'short_name' => 'Caritas', 'donor_type' => 'Other'],
            ['name' => 'World Relief', 'short_name' => 'World Relief', 'donor_type' => 'Other'],
            ['name' => 'Samaritan\'s Purse', 'short_name' => 'Samaritan\'s Purse', 'donor_type' => 'Other'],
            ['name' => 'Médecins Sans Frontières (MSF)', 'short_name' => 'MSF', 'donor_type' => 'Other'],
            ['name' => 'Action Against Hunger (ACF)', 'short_name' => 'ACF', 'donor_type' => 'Other'],
            ['name' => 'HI - Humanity & Inclusion', 'short_name' => 'HI', 'donor_type' => 'Other'],
            ['name' => 'Concern Worldwide', 'short_name' => 'Concern', 'donor_type' => 'Other'],
            ['name' => 'GOAL', 'short_name' => 'GOAL', 'donor_type' => 'Other'],
            ['name' => 'Welthungerhilfe (WHH)', 'short_name' => 'WHH', 'donor_type' => 'Other'],
            ['name' => 'Medair', 'short_name' => 'Medair', 'donor_type' => 'Other'],
            ['name' => 'International Medical Corps (IMC)', 'short_name' => 'IMC', 'donor_type' => 'Other'],
            ['name' => 'ACT Alliance', 'short_name' => 'ACT Alliance', 'donor_type' => 'Other'],
            ['name' => 'Lutheran World Federation (LWF)', 'short_name' => 'LWF', 'donor_type' => 'Other'],
            ['name' => 'Islamic Relief Worldwide', 'short_name' => 'Islamic Relief', 'donor_type' => 'Other'],
            ['name' => 'Norwegian Church Aid (NCA)', 'short_name' => 'NCA', 'donor_type' => 'Other'],
            ['name' => 'PATH', 'short_name' => 'PATH', 'donor_type' => 'Other'],
            ['name' => 'Jhpiego', 'short_name' => 'Jhpiego', 'donor_type' => 'Other'],
            ['name' => 'FHI 360', 'short_name' => 'FHI 360', 'donor_type' => 'Other'],
            ['name' => 'Clinton Health Access Initiative (CHAI)', 'short_name' => 'CHAI', 'donor_type' => 'Other'],
            ['name' => 'Population Services International (PSI)', 'short_name' => 'PSI', 'donor_type' => 'Other'],
            ['name' => 'RTI International', 'short_name' => 'RTI', 'donor_type' => 'Other'],
            ['name' => 'Abt Associates', 'short_name' => 'Abt', 'donor_type' => 'Other'],
            ['name' => 'WaterAid', 'short_name' => 'WaterAid', 'donor_type' => 'Other'],
            
            // Ethiopian NNGOs (Sample)
            ['name' => 'Nexus Ethiopia - Nexus of National Humanitarian Actors', 'short_name' => 'Nexus Ethiopia', 'donor_type' => 'Other'],
            ['name' => 'MCMDO - Mothers and Children Multisectoral Development Organization', 'short_name' => 'MCMDO', 'donor_type' => 'Other'],
            ['name' => 'ORDA Ethiopia - Organization for Rehabilitation and Development in Amhara', 'short_name' => 'ORDA', 'donor_type' => 'Other'],
            ['name' => 'Kelem Ethiopia', 'short_name' => 'Kelem', 'donor_type' => 'Other'],
            ['name' => 'Mahibere Hiwot for Social Development', 'short_name' => 'Mahibere Hiwot', 'donor_type' => 'Other'],
            ['name' => 'Mekdim Ethiopia National Association', 'short_name' => 'Mekdim', 'donor_type' => 'Other'],
            ['name' => 'Pro Pride', 'short_name' => 'Pro Pride', 'donor_type' => 'Other'],
            ['name' => 'Hope for Children Organization of Ethiopia', 'short_name' => 'Hope for Children', 'donor_type' => 'Other'],
            ['name' => 'WE-Action - Women Empowerment–Action', 'short_name' => 'WE-Action', 'donor_type' => 'Other'],
            ['name' => 'CRDA / CCRDA - Christian Relief and Development Association', 'short_name' => 'CRDA', 'donor_type' => 'Other'],
            ['name' => 'OSSHD - Organization for Social Services, Health and Development', 'short_name' => 'OSSHD', 'donor_type' => 'Other'],
            ['name' => 'EOTC–DICAC - Ethiopian Orthodox Church Development and Inter-Church Aid Commission', 'short_name' => 'EOTC-DICAC', 'donor_type' => 'Other'],
            ['name' => 'MCDP - Mission for Community Development Programme', 'short_name' => 'MCDP', 'donor_type' => 'Other'],
            ['name' => 'MCDO - Mother and Child Development Organization', 'short_name' => 'MCDO', 'donor_type' => 'Other'],
            ['name' => 'Mums for Mums', 'short_name' => 'Mums for Mums', 'donor_type' => 'Other'],
            ['name' => 'Peace and Development Center (PDC)', 'short_name' => 'PDC', 'donor_type' => 'Other'],
            ['name' => 'Forum for Environment', 'short_name' => 'Forum for Environment', 'donor_type' => 'Other'],
            ['name' => 'EWNHS - Ethiopia Wildlife and Natural History Society', 'short_name' => 'EWNHS', 'donor_type' => 'Other'],
            ['name' => 'Ayzon Foundation', 'short_name' => 'Ayzon', 'donor_type' => 'Foundation'],
            ['name' => 'Shamida Ethiopia', 'short_name' => 'Shamida', 'donor_type' => 'Other'],
            
            // Major International Foundations
            ['name' => 'Bill & Melinda Gates Foundation (BMGF)', 'short_name' => 'Gates Foundation', 'donor_type' => 'Foundation'],
            ['name' => 'Children\'s Investment Fund Foundation (CIFF)', 'short_name' => 'CIFF', 'donor_type' => 'Foundation'],
            ['name' => 'Wellcome Trust', 'short_name' => 'Wellcome', 'donor_type' => 'Foundation'],
            ['name' => 'Ford Foundation', 'short_name' => 'Ford Foundation', 'donor_type' => 'Foundation'],
            ['name' => 'Rockefeller Foundation', 'short_name' => 'Rockefeller', 'donor_type' => 'Foundation'],
            ['name' => 'Open Society Foundations (OSF)', 'short_name' => 'Open Society', 'donor_type' => 'Foundation'],
            ['name' => 'Conrad N. Hilton Foundation', 'short_name' => 'Hilton Foundation', 'donor_type' => 'Foundation'],
            ['name' => 'ELMA Foundation', 'short_name' => 'ELMA', 'donor_type' => 'Foundation'],
            ['name' => 'IKEA Foundation', 'short_name' => 'IKEA Foundation', 'donor_type' => 'Foundation'],
            ['name' => 'Mastercard Foundation', 'short_name' => 'Mastercard Foundation', 'donor_type' => 'Foundation'],
            ['name' => 'Skoll Foundation', 'short_name' => 'Skoll', 'donor_type' => 'Foundation'],
            ['name' => 'MacArthur Foundation', 'short_name' => 'MacArthur', 'donor_type' => 'Foundation'],
            ['name' => 'Hewlett Foundation', 'short_name' => 'Hewlett', 'donor_type' => 'Foundation'],
            ['name' => 'Packard Foundation', 'short_name' => 'Packard', 'donor_type' => 'Foundation'],
            ['name' => 'La Caixa Foundation', 'short_name' => 'La Caixa', 'donor_type' => 'Foundation'],
            ['name' => 'Dubai Cares', 'short_name' => 'Dubai Cares', 'donor_type' => 'Foundation'],
            ['name' => 'Qatar Foundation', 'short_name' => 'Qatar Foundation', 'donor_type' => 'Foundation'],
            ['name' => 'Mohamed Bin Zayed Foundation for Humanity', 'short_name' => 'MBZ Foundation', 'donor_type' => 'Foundation'],
        ];
        
        // Donor currency mapping
        $donorCurrencies = [
            'EHF/UNOCHA' => 'USD', 'UNOCHA' => 'USD', 'UNDP' => 'USD', 'UNICEF' => 'USD', 'UNHCR' => 'USD',
            'WFP' => 'USD', 'WHO' => 'USD', 'UNFPA' => 'USD', 'UNESCO' => 'USD', 'FAO' => 'USD',
            'ILO' => 'USD', 'UN Women' => 'USD', 'UNEP' => 'USD', 'UNIDO' => 'USD', 'UN-Habitat' => 'USD',
            'IOM' => 'USD', 'IFAD' => 'USD', 'ITC' => 'USD', 'ITU' => 'USD', 'OHCHR' => 'USD',
            'UNECA' => 'USD', 'UNAIDS' => 'USD', 'UNCDF' => 'USD', 'UNCTAD' => 'USD', 'UNDRR' => 'USD',
            'UNOAU' => 'USD', 'UNODC' => 'USD', 'UNOPS' => 'USD', 'CERF' => 'USD', 'CBPF' => 'USD',
            'USAID' => 'USD', 'US State Dept' => 'USD', 'CDC' => 'USD',
            'FCDO' => 'GBP', 'BMZ' => 'EUR', 'GIZ' => 'EUR', 'KfW' => 'EUR', 'EU DG INTPA' => 'EUR',
            'ECHO' => 'EUR', 'Sida' => 'SEK', 'NORAD' => 'NOK', 'Netherlands MFA' => 'EUR',
            'DANIDA' => 'DKK', 'SDC' => 'CHF', 'Canada GAC' => 'CAD', 'AFD' => 'EUR', 'AICS' => 'EUR',
            'AECID' => 'EUR', 'Enabel' => 'EUR', 'Irish Aid' => 'EUR', 'Finland MFA' => 'EUR', 'ADA' => 'EUR',
            'JICA' => 'JPY', 'KOICA' => 'KRW', 'TİKA' => 'TRY', 'China Funds' => 'CNY',
            'Saudi Fund' => 'SAR', 'Qatar Fund' => 'QAR', 'Kuwait Fund' => 'KWD', 'Abu Dhabi Fund' => 'AED',
        ];
        
        // First, ensure currency column exists
        try {
            $pdo->exec("ALTER TABLE gms_donors ADD COLUMN IF NOT EXISTS currency VARCHAR(10) DEFAULT 'USD'");
        } catch (PDOException $e) {
            // Column may already exist, ignore
        }
        
        // Ensure unique constraint on name exists (check first to avoid error)
        try {
            $checkStmt = $pdo->query("SHOW INDEX FROM gms_donors WHERE Key_name = 'unique_donor_name'");
            if ($checkStmt && $checkStmt->rowCount() == 0) {
                $pdo->exec("ALTER TABLE gms_donors ADD UNIQUE KEY unique_donor_name (name)");
            }
        } catch (PDOException $e) {
            // Constraint may already exist or table doesn't exist yet, ignore
        }
        
        // Use INSERT ... ON DUPLICATE KEY UPDATE to ensure all donors are present and updated
        try {
            $stmt = $pdo->prepare("INSERT INTO gms_donors (name, short_name, donor_type, currency, is_active) VALUES (?, ?, ?, ?, 1) ON DUPLICATE KEY UPDATE short_name = VALUES(short_name), donor_type = VALUES(donor_type), currency = VALUES(currency), is_active = 1");
        } catch (PDOException $e) {
            // Fallback to INSERT IGNORE if ON DUPLICATE KEY doesn't work
            $stmt = $pdo->prepare("INSERT IGNORE INTO gms_donors (name, short_name, donor_type, currency, is_active) VALUES (?, ?, ?, ?, 1)");
        }
        
        $inserted = 0;
        foreach ($donors as $donor) {
            $shortName = $donor['short_name'] ?? '';
            $currency = $donorCurrencies[$shortName] ?? 'USD';
            try {
                $stmt->execute([$donor['name'], $shortName, $donor['donor_type'], $currency]);
                $inserted++;
            } catch (PDOException $e) {
                error_log("Error inserting donor " . $donor['name'] . ": " . $e->getMessage());
            }
        }
        error_log("Helpers: Seeded/updated $inserted donors in gms_donors table");
    }
}

// Initialize tables on first load
if (function_exists('getPDO')) {
    try {
        $pdo = getPDO();
        init_currencies_donors_tables($pdo);
        // Auto-seed donors on helpers load
        if (function_exists('seed_comprehensive_donors')) {
            seed_comprehensive_donors($pdo);
        }
    } catch (Exception $e) {
        error_log("Error initializing currencies/donors on helpers load: " . $e->getMessage());
    }
}

/* ============================================================
 * RESPONSE / DOWNLOAD / AJAX SAFETY HELPERS (v3.2)
 * Fixes:
 *  - "Headers already sent" caused by stray output (BOM/whitespace)
 *  - Export/Download mixing with HTML output buffer
 *  - AJAX JSON responses corrupted by prior buffered HTML
 * ============================================================ */

if (!function_exists('hrs_end_all_output_buffers')) {
    /**
     * Clean and close ALL active output buffers (safe for downloads/AJAX).
     * Use this immediately before sending file headers (CSV/Excel/Word/PDF) or JSON.
     */
    function hrs_end_all_output_buffers(): void {
        try {
            while (ob_get_level() > 0) {
                @ob_end_clean();
            }
        } catch (Throwable $e) {
            // ignore
        }
    }
}

if (!function_exists('hrs_is_ajax_request')) {
    function hrs_is_ajax_request(): bool {
        $xrw = $_SERVER['HTTP_X_REQUESTED_WITH'] ?? '';
        if (strcasecmp($xrw, 'XMLHttpRequest') === 0) return true;

        // Fetch/XHR sometimes sends "Accept: application/json"
        $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
        if (stripos($accept, 'application/json') !== false) return true;

        // Explicit query switches
        if (isset($_GET['ajax']) || isset($_POST['ajax'])) return true;

        return false;
    }
}

if (!function_exists('hrs_is_export_request')) {
    /**
     * Detect export/download requests even if triggered by POST buttons.
     */
    function hrs_is_export_request(): bool {
        $q = $_GET ?? [];
        $p = $_POST ?? [];

        $exportKeys = [
            'export','download','print','pdf','excel','xls','xlsx','csv','word','doc','docx','format','mode','action'
        ];

        foreach ($exportKeys as $k) {
            if (isset($q[$k]) || isset($p[$k])) return true;
        }

        // Common submit button names used across your apps
        $btnKeys = [
            'export_csv','export_excel','export_xls','export_word','export_doc','export_docx','export_pdf',
            'download_csv','download_excel','download_word','download_pdf',
            'print_view','print','download'
        ];
        foreach ($btnKeys as $k) {
            if (isset($p[$k]) || isset($q[$k])) return true;
        }

        // URL/path heuristic
        $uri = $_SERVER['REQUEST_URI'] ?? '';
        if (preg_match('~(export|download|print|pdf|excel|csv|word)~i', $uri)) {
            return true;
        }

        return false;
    }
}

if (!function_exists('hrs_prepare_json_response')) {
    function hrs_prepare_json_response(int $statusCode = 200): void {
        // Clear buffered HTML (from header.php etc.)
        hrs_end_all_output_buffers();

        if (!headers_sent()) {
            http_response_code($statusCode);
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
            header('Pragma: no-cache');
        }
    }
}

if (!function_exists('hrs_prepare_download_response')) {
    /**
     * Prepare a clean download response (clears buffers, sets headers).
     */
    function hrs_prepare_download_response(string $contentType, string $filename, array $extraHeaders = []): void {
        hrs_end_all_output_buffers();

        // Disable compression (can corrupt binary downloads)
        @ini_set('zlib.output_compression', 'Off');

        if (!headers_sent()) {
            header('Content-Type: ' . $contentType);
            header('Content-Disposition: attachment; filename="' . addslashes($filename) . '"');
            header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
            header('Pragma: no-cache');
        }

        foreach ($extraHeaders as $h) {
            if (!headers_sent()) {
                header($h);
            }
        }
    }
}

if (!function_exists('hrs_prepare_csv_download')) {
    function hrs_prepare_csv_download(string $filename): void {
        hrs_prepare_download_response('text/csv; charset=utf-8', $filename);
        // Excel-friendly UTF-8 BOM
        echo "\xEF\xBB\xBF";
    }
}

if (!function_exists('hrs_prepare_excel_download')) {
    /**
     * "Excel" download without extra libraries.
     * We output an HTML table as .xls which Excel opens correctly.
     */
    function hrs_prepare_excel_download(string $filename): void {
        hrs_prepare_download_response('application/vnd.ms-excel; charset=utf-8', $filename);
    }
}

if (!function_exists('hrs_prepare_word_download')) {
    /**
     * "Word" download without extra libraries.
     * We output HTML as .doc which Word opens correctly.
     */
    function hrs_prepare_word_download(string $filename): void {
        hrs_prepare_download_response('application/msword; charset=utf-8', $filename);
    }
}

if (!function_exists('hrs_prepare_pdf_fallback')) {
    /**
     * Lightweight PDF fallback:
     * Sends a print-friendly HTML with a .html filename, so user can Print -> Save as PDF.
     * (Keeps system functional without external PDF libraries.)
     */
    function hrs_prepare_pdf_fallback(string $filenameHtml = 'report_print.html'): void {
        hrs_prepare_download_response('text/html; charset=utf-8', $filenameHtml);
    }
}

/* ============================================================
 * TRUE SERVER-SIDE PDF EXPORT (Dompdf via Composer)
 *
 * How it works:
 *  - If /vendor/autoload.php exists and Dompdf is installed, we generate a real PDF
 *  - If Dompdf is NOT installed, we fall back to print-friendly HTML (existing behavior)
 *
 * XAMPP + Composer install (from project root):
 *   composer install
 *   (or) composer require dompdf/dompdf
 * ============================================================ */

if (!function_exists('hrs_try_load_composer_autoload')) {
    function hrs_try_load_composer_autoload(): void {
        static $loaded = false;
        if ($loaded) return;
        $loaded = true;

        $autoload = __DIR__ . '/vendor/autoload.php';
        if (is_file($autoload)) {
            require_once $autoload;
        }
    }
}

if (!function_exists('hrs_has_dompdf')) {
    function hrs_has_dompdf(): bool {
        hrs_try_load_composer_autoload();
        return class_exists('Dompdf\\Dompdf');
    }
}

if (!function_exists('hrs_export_pdf_from_html')) {
    /**
     * Export a real PDF from HTML (server-side) using Dompdf.
     * Falls back to print-friendly HTML if Dompdf is unavailable.
     */
    function hrs_export_pdf_from_html(string $html, string $filenamePdf = 'report.pdf', string $paper = 'A4', string $orientation = 'portrait'): void {
        // Ensure the response isn't polluted by buffered HTML from headers
        hrs_end_all_output_buffers();

        // If Dompdf is not installed, keep the system functional
        if (!hrs_has_dompdf()) {
            // Provide a clear hint inside the file for admins
            $note = "<!-- PDF engine missing. Install Dompdf with Composer: composer require dompdf/dompdf -->\n";
            hrs_prepare_pdf_fallback(preg_replace('~\\.pdf$~i', '', $filenamePdf) . '_print.html');
            echo $note . $html;
            exit;
        }

        // Build a safe base href so images/css with relative paths can resolve
        $baseHref = defined('BASE_URL') ? rtrim((string)BASE_URL, '/') . '/' : '';
        if ($baseHref !== '' && stripos($html, '<base') === false) {
            $html = preg_replace('~<head(\\s*?)>~i', '<head$1><base href="' . htmlspecialchars($baseHref, ENT_QUOTES, 'UTF-8') . '">', $html, 1);
        }

        // Dompdf options
        $optsClass = 'Dompdf\\Options';
        $domClass  = 'Dompdf\\Dompdf';

        $options = new $optsClass();
        // Allow loading local/remote assets referenced by URL
        if (method_exists($options, 'set')) {
            $options->set('isRemoteEnabled', true);
            $options->set('isHtml5ParserEnabled', true);
            // Better Unicode support
            $options->set('defaultFont', 'DejaVu Sans');
        }

        $dompdf = new $domClass($options);
        $dompdf->setPaper($paper, $orientation);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->render();

        // Output a real PDF
        hrs_prepare_download_response('application/pdf', $filenamePdf);
        echo $dompdf->output();
        exit;
    }
}


