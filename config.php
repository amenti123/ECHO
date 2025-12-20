<?php
/**
 * SMART Nexus Configuration File
 * Centralized configuration for all apps
 * Version: 3.0
 */

// Application Information
if (!defined('APP_NAME')) define('APP_NAME', 'SMART Nexus - Health Reporting System');
if (!defined('APP_VERSION')) define('APP_VERSION', '3.1');
if (!defined('APP_ENV')) define('APP_ENV', getenv('APP_ENV') ?: 'production');

// Database Configuration
if (!defined('DB_HOST')) define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
if (!defined('DB_PORT')) define('DB_PORT', getenv('DB_PORT') ?: '3306');
if (!defined('DB_NAME')) define('DB_NAME', getenv('DB_NAME') ?: 'health_system_reporting');
if (!defined('DB_USER')) define('DB_USER', getenv('DB_USER') ?: 'root');
if (!defined('DB_PASS')) define('DB_PASS', getenv('DB_PASS') ?: '');
if (!defined('DB_CHARSET')) define('DB_CHARSET', 'utf8mb4');

// Base URL Configuration
$protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';

// Compute a stable BASE_URL that always points to the system ROOT (not /pages or /api).
$scriptPath = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));
$scriptPath = rtrim($scriptPath, '/');
$scriptPath = preg_replace('~/(pages|api)$~i', '', $scriptPath);
if ($scriptPath === '/' || $scriptPath === '.' ) { $scriptPath = ''; }

if (!defined('BASE_URL')) {
    define('BASE_URL', getenv('BASE_URL') ?: ($protocol . '://' . $host . $scriptPath));
}

// Filesystem base path (root of the system)
if (!defined('BASE_PATH')) {
    define('BASE_PATH', __DIR__);
}

// API Configuration
if (!defined('API_VERSION')) define('API_VERSION', '3.0');
if (!defined('API_BASE_PATH')) define('API_BASE_PATH', '/api');
if (!defined('API_ENABLED')) define('API_ENABLED', true);

// Cross-App Communication
if (!defined('CROSS_APP_SESSION_KEY')) define('CROSS_APP_SESSION_KEY', 'cross_app_data_v3');
if (!defined('CROSS_APP_ENABLED')) define('CROSS_APP_ENABLED', true);
if (!defined('AUTO_SYNC_ENABLED')) define('AUTO_SYNC_ENABLED', true);
if (!defined('REAL_TIME_SYNC')) define('REAL_TIME_SYNC', true);

// Security Configuration
if (!defined('SESSION_LIFETIME')) define('SESSION_LIFETIME', 3600 * 8); // 8 hours
if (!defined('API_TOKEN_LIFETIME')) define('API_TOKEN_LIFETIME', 3600 * 24 * 30); // 30 days
if (!defined('MAX_LOGIN_ATTEMPTS')) define('MAX_LOGIN_ATTEMPTS', 5);
if (!defined('LOGIN_LOCKOUT_TIME')) define('LOGIN_LOCKOUT_TIME', 900); // 15 minutes

// File Upload Configuration
if (!defined('UPLOAD_MAX_SIZE')) define('UPLOAD_MAX_SIZE', 10 * 1024 * 1024); // 10MB
if (!defined('UPLOAD_ALLOWED_TYPES')) define('UPLOAD_ALLOWED_TYPES', ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'application/pdf']);

// Cache Configuration
if (!defined('CACHE_ENABLED')) define('CACHE_ENABLED', true);
if (!defined('CACHE_LIFETIME')) define('CACHE_LIFETIME', 3600); // 1 hour

// Logging Configuration
if (!defined('LOG_ENABLED')) define('LOG_ENABLED', true);
if (!defined('LOG_LEVEL')) define('LOG_LEVEL', 'INFO'); // DEBUG, INFO, WARNING, ERROR
if (!defined('LOG_PATH')) define('LOG_PATH', __DIR__ . '/logs');

// Performance Configuration
if (!defined('QUERY_CACHE_ENABLED')) define('QUERY_CACHE_ENABLED', true);
if (!defined('QUERY_CACHE_LIFETIME')) define('QUERY_CACHE_LIFETIME', 300); // 5 minutes

// Feature Flags
if (!defined('FEATURE_AI_ENABLED')) define('FEATURE_AI_ENABLED', true);
if (!defined('FEATURE_REAL_TIME_UPDATES')) define('FEATURE_REAL_TIME_UPDATES', true);
if (!defined('FEATURE_DATA_EXPORT')) define('FEATURE_DATA_EXPORT', true);
if (!defined('FEATURE_ADVANCED_REPORTING')) define('FEATURE_ADVANCED_REPORTING', true);

// Error Reporting
if (APP_ENV === 'development') {
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
} else {
    error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING);
    ini_set('display_errors', 0);
}

// Timezone
date_default_timezone_set('Africa/Addis_Ababa');


