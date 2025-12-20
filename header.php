<?php 
// header.php - UPGRADED & STANDARDIZED VERSION
// VERSION: 3.1 – SMART Nexus Enhanced Header (integrated with app_management.php)
// NOTE: v3.1 includes comprehensive donor/currency management and cross-app integration.

// Start output buffering to prevent headers already sent errors
ob_start();

require_once __DIR__ . '/../helpers.php';

// Make sure session is started (in case helpers.php didn't)
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Polyfill for PHP 7 if server doesn't support str_contains
if (!function_exists('str_contains')) {
    function str_contains($haystack, $needle)
    {
        return $needle !== '' && mb_strpos($haystack, $needle) !== false;
    }
}

// Safe user retrieval
$user = null;
if (function_exists('current_user')) {
    $user = current_user();
}

// Display name
$displayName = 'Guest';
if ($user) {
    $displayName = $user['name'] ?? $user['full_name'] ?? $user['email'] ?? 'User';
}

/**
 * IMPORTANT: set this to your project folder name.
 */
/**
 * Auto-detect base URL folder (supports renames like /health_reporting_system_vB)
 */
$__script = $_SERVER['SCRIPT_NAME'] ?? '';
if (strpos($__script, '/pages/') !== false) {
    $BASE_URL = substr($__script, 0, strpos($__script, '/pages/'));
} else {
    $BASE_URL = rtrim(dirname($__script), '/');
}
if ($BASE_URL === '') { $BASE_URL = '/'; }
/**
 * LEGACY COMPATIBILITY HOOKS (added back in v2.4)
 * - $pageTitle        : override <title> from pages
 * - $extra_head_css   : extra CSS links / styles for specific pages
 * - $extra_head_js    : extra JS includes to put in <head>
 * - $disableWelcomeAnimation : if true, no full-screen welcome overlay
 * - $forceHeader      : if true, always render full header even on download/export URLs
 */
if (!isset($pageTitle)) {
    $pageTitle = 'SMART Nexus – Smart Project Monitoring & Reporting Platform';
}
$extra_head_css          = $extra_head_css          ?? '';
$extra_head_js           = $extra_head_js           ?? '';
$disableWelcomeAnimation = $disableWelcomeAnimation ?? false;
$forceHeader             = $forceHeader             ?? false;

// Current path for active menu highlighting
$currentPath = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?? '';

function nav_active(string $target, string $currentPath): string {
    return (strpos($currentPath, $target) !== false) ? ' nav-active' : '';
}

// ---------- NEW: ensure app_layout table exists (shared with app_management) ----------
if (!function_exists('ensure_app_layout_schema')) {
    function ensure_app_layout_schema(PDO $pdo): void {
        try {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS app_layout (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    app_key VARCHAR(50) NOT NULL UNIQUE,
                    group_name VARCHAR(100) NOT NULL,
                    sort_order INT NOT NULL DEFAULT 0
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
        } catch (Throwable $e) {
            // Ignore errors – header will still work with default layout
        }
    }
}

// ENHANCED: Profile Photo Handling with Advanced Caching
$photoManager = ProfilePhotoManager::getInstance();
$photoSrc = null;
if ($user) {
    $photoSrc = $photoManager->getPhotoUrl($user, $BASE_URL);
    if (!$photoSrc) {
        $photoSrc = $photoManager->getDefaultPhotoUrl($user, $BASE_URL);
    }
}

// Persist / reuse last successful photo URL to avoid “re-upload” feeling
if (!empty($photoSrc)) {
    $_SESSION['last_photo_url'] = $photoSrc;
} elseif (isset($_SESSION['last_photo_url'])) {
    $photoSrc = $_SESSION['last_photo_url'];
}

// Enhanced Cross-App Communication
$crossApp = CrossAppCommunicator::getInstance();
$currentProject = $crossApp->getData('current_project', 'projects');
$systemStatus = get_system_status();

// Unread messages and notifications
$unreadMessages = $crossApp->getData('unread_count', 'messages', 0);
$notifications = $crossApp->getData('notifications', 'system', []);

// Get user role
$userRole = $user['role'] ?? 'guest';
$isAdmin = ($userRole === 'admin');

// AI-Powered Dynamic Greetings with Enhanced Personalization
$currentHour = date('G');
$dayPart = '';
$greeting = '';
$welcomeMessage = '';

if ($currentHour < 5) {
    $dayPart = 'early morning';
    $greeting = 'Rise and Shine';
    $welcomeMessage = 'Start your day with purpose and make every moment count!';
} elseif ($currentHour < 12) {
    $dayPart = 'morning';
    $greeting = 'Good Morning';
    $welcomeMessage = 'Embrace new opportunities and drive impactful changes today!';
} elseif ($currentHour < 14) {
    $dayPart = 'noon';
    $greeting = 'Good Noon';
    $welcomeMessage = 'Your midday momentum is building - keep pushing forward!';
} elseif ($currentHour < 17) {
    $dayPart = 'afternoon';
    $greeting = 'Good Afternoon';
    $welcomeMessage = 'Your productivity is shining bright this afternoon!';
} elseif ($currentHour < 20) {
    $dayPart = 'evening';
    $greeting = 'Good Evening';
    $welcomeMessage = 'Finish strong and celebrate today achievements!';
} else {
    $dayPart = 'night';
    $greeting = 'Good Night';
    $welcomeMessage = 'Reflect on your accomplishments and plan for tomorrow success!';
}

// Enhanced Role-based personalized messages with AI insights
if ($user) {
    $roleMessages = [
        'admin' => [
            'Lead with vision and empower your team to achieve greatness!',
            'Your strategic oversight drives organizational excellence!',
            'Shape the future with data-driven decisions and inspired leadership!',
            'AI Insights: Monitor system performance and user engagement metrics!',
            'Pro Tip: Use cross-app data to identify integration opportunities!'
        ],
        'manager' => [
            'Your leadership transforms data into actionable insights!',
            'Empower your team and watch productivity soar!',
            'Strategic thinking meets operational excellence in your hands!',
            'AI Tip: Track project milestones and resource allocation patterns!',
            'Insight: Leverage real-time analytics for informed decision-making!'
        ],
        'user' => [
            'Your contributions are building a better future every day!',
            'Every data point you enter creates meaningful impact!',
            'Your dedication is the foundation of our success story!',
            'AI Assistant: Remember to save your work frequently!',
            'Tip: Use the search feature to quickly find information!'
        ]
    ];
    
    $userRoleKey = $isAdmin ? 'admin' : ($userRole === 'manager' ? 'manager' : 'user');
    $roleSpecificMessages = $roleMessages[$userRoleKey] ?? $roleMessages['user'];
    $welcomeMessage = $roleSpecificMessages[array_rand($roleSpecificMessages)];
}

// Dynamic AI-powered messages with real-time updates
$dynamicMessages = [
    "🚀 AI-Powered Analytics: Transform Data into Strategic Decisions",
    "📊 Real-time Monitoring: Track Progress with Precision & Accuracy", 
    "💡 Smart Insights: Predictive Analytics for Proactive Management",
    "🌐 Integrated Platform: Seamless Collaboration Across All Projects",
    "📈 Performance Excellence: Data-Driven Results in Real-Time",
    "🔍 Quality Assurance: Automated Data Validation & Reporting",
    "⚡ Intelligent Automation: Streamline Operations with AI",
    "🎯 Strategic Oversight: Comprehensive Project Lifecycle Management",
    "🤖 Nexus AI: Your Intelligent Assistant for Smarter Workflows",
    "📱 Cross-Platform Sync: Data Available Anytime, Anywhere",
    "🔒 Secure & Reliable: Enterprise-Grade Data Protection",
    "📋 Smart Reporting: Automated Insights and Recommendations"
];

$currentMessage = $dynamicMessages[array_rand($dynamicMessages)];

// Enhanced motivational quotes with context awareness
$weeklyQuotes = [
    "🌟 Your data tells a story - make it legendary!",
    "🚀 Transforming numbers into narratives of success!",
    "📊 Every report you generate shapes tomorrow decisions!",
    "💡 Innovation meets execution in your dashboard!",
    "🎯 Precision in data, excellence in outcomes!",
    "🔥 Your work today builds a better tomorrow!",
    "⚡ Data-driven decisions create unstoppable momentum!",
    "🌈 Turn challenges into opportunities with smart insights!",
    "💎 Quality data is the foundation of great decisions!",
    "🚦 Guide your projects to success with real-time metrics!"
];

$dailyQuote = $weeklyQuotes[date('N') % count($weeklyQuotes)];

/**
 * Show welcome animation once per session,
 * but allow disabling via $disableWelcomeAnimation (legacy compatibility).
 */
$showWelcomeAnimation = !$disableWelcomeAnimation && !isset($_SESSION['welcome_animation_shown']);
if ($showWelcomeAnimation) {
    $_SESSION['welcome_animation_shown'] = true;
}

// ==========================================================
// Enhanced app configurations with cross-communication capabilities
// ==========================================================
$apps = [
    'dashboard' => [
        'icon' => 'tachometer-alt',
        'title' => 'Dashboard',
        'desc' => 'Live analytics & overview',
        'category' => 'overview',
        'color' => 'linear-gradient(135deg, #667eea 0%, #764ba2 100%)'
    ],
    'projects' => [
        'icon' => 'project-diagram',
        'title' => 'Projects',
        'desc' => 'Manage configuration',
        'category' => 'core',
        'color' => 'linear-gradient(135deg, #f093fb 0%, #f5576c 100%)'
    ],
    'planning' => [
        'icon' => 'calendar-check',
        'title' => 'Planning',
        'desc' => 'Schedule & timelines',
        'category' => 'planning',
        'color' => 'linear-gradient(135deg, #4facfe 0%, #00f2fe 100%)'
    ],
    'budget' => [
        'icon' => 'money-check-alt',
        'title' => 'Budget',
        'desc' => 'Finance tracking',
        'category' => 'financial',
        'color' => 'linear-gradient(135deg, #43e97b 0%, #38f9d7 100%)'
    ],
    'grants' => [
        'icon' => 'hand-holding-usd',
        'title' => 'Grant Management',
        'desc' => 'Grant lifecycle & compliance',
        'category' => 'financial',
        'color' => 'linear-gradient(135deg, #f093fb 0%, #f5576c 100%)'
    ],
    'geography' => [
        'icon' => 'map-marked-alt',
        'title' => 'Geography',
        'desc' => 'Location management',
        'category' => 'location',
        'color' => 'linear-gradient(135deg, #fa709a 0%, #fee140 100%)'
    ],
    'indicators' => [
        'icon' => 'bullseye',
        'title' => 'Indicators',
        'desc' => 'KPI management',
        'category' => 'metrics',
        'color' => 'linear-gradient(135deg, #ff9a9e 0%, #fecfef 100%)'
    ],
    'enter_data' => [
        'icon' => 'database',
        'title' => 'Data Entry',
        'desc' => 'Input data forms',
        'category' => 'data',
        'color' => 'linear-gradient(135deg, #a1c4fd 0%, #c2e9fb 100%)'
    ],

    // ✅ NEW APP: Project Activity Report (under /pages/)
    'project_activity_report' => [
        'icon' => 'clipboard-list',
        'title' => 'Activity Report',
        'desc' => 'Project activity reporting',
        'category' => 'reporting',
        'color' => 'linear-gradient(135deg, #ffecd2 0%, #fcb69f 100%)'
    ],

    'view_reports' => [
        'icon' => 'chart-line',
        'title' => 'Reports',
        'desc' => 'Analysis & insights',
        'category' => 'reporting',
        'color' => 'linear-gradient(135deg, #d4fc79 0%, #96e6a1 100%)'
    ],
    'custom_report' => [
        'icon' => 'chart-bar',
        'title' => 'Custom Reports',
        'desc' => 'Tailored analytics',
        'category' => 'reporting',
        'color' => 'linear-gradient(135deg, #a6c0fe 0%, #f68084 100%)'
    ],
    'aggregation' => [
        'icon' => 'chart-pie',
        'title' => 'Aggregation',
        'desc' => 'Data consolidation',
        'category' => 'reporting',
        'color' => 'linear-gradient(135deg, #fccb90 0%, #d57eeb 100%)'
    ],
    'pivot' => [
        'icon' => 'table',
        'title' => 'Pivot Tables',
        'desc' => 'Advanced analysis',
        'category' => 'analysis',
        'color' => 'linear-gradient(135deg, #e0c3fc 0%, #8ec5fc 100%)'
    ],
    'progress' => [
        'icon' => 'tasks',
        'title' => 'Progress Dashboard',
        'desc' => 'Track milestones',
        'category' => 'monitoring',
        'color' => 'linear-gradient(135deg, #fad0c4 0%, #ffd1ff 100%)'
    ],
    'cfm' => [
        'icon' => 'comments',
        'title' => 'CFM System',
        'desc' => 'Feedback management',
        'category' => 'communication',
        'color' => 'linear-gradient(135deg, #4facfe 0%, #00f2fe 100%)'
    ],
    'messages' => [
        'icon' => 'envelope',
        'title' => 'Messaging',
        'desc' => 'Communication hub',
        'category' => 'communication',
        'color' => 'linear-gradient(135deg, #ff9a9e 0%, #fecfef 100%)'
    ],
    'users' => [
        'icon' => 'users-cog',
        'title' => 'User Management',
        'desc' => 'Access control',
        'category' => 'admin',
        'color' => 'linear-gradient(135deg, #a1c4fd 0%, #c2e9fb 100%)'
    ],

    // ✅ App Management app (file: /pages/app_management.php)
    'app_management' => [
        'icon' => 'sliders-h',
        'title' => 'App Management',
        'desc' => 'Show, hide & reorder apps',
        'category' => 'admin',
        'color' => 'linear-gradient(135deg, #ffbe0b 0%, #fb5607 100%)'
    ],

    'nexus_ai' => [
        'icon' => 'robot',
        'title' => 'Nexus AI',
        'desc' => 'Smart assistant',
        'category' => 'ai',
        'color' => 'linear-gradient(135deg, #667eea 0%, #764ba2 100%)'
    ],
    'data_quality' => [
        'icon' => 'chart-line',
        'title' => 'Data Quality',
        'desc' => 'Monitoring & validation',
        'category' => 'quality',
        'color' => 'linear-gradient(135deg, #43e97b 0%, #38f9d7 100%)'
    ]
];

// Get user's app order from session or set default
$appOrder = $_SESSION['app_order'] ?? [
    'dashboard',
    'projects',
    'planning',
    'budget',
    'grants',
    'geography',
    'indicators',
    'enter_data',
    // ✅ Show new app next to Data Entry
    'project_activity_report',
    'view_reports',
    'custom_report',
    'aggregation',
    'pivot',
    'progress',
    'cfm',
    'messages',
    'users',
    'app_management',
    'nexus_ai',
    'data_quality'
];

/**
 * ✅ Ensure ALL apps defined in $apps appear in the bar,
 *    even if new apps were added after the user’s older session order.
 */

// 1) Remove any old keys that no longer exist in $apps
$appOrder = array_values(array_filter($appOrder, function ($key) use ($apps) {
    return isset($apps[$key]);
}));

// 2) Append any NEW apps that are in $apps but missing in $appOrder
foreach (array_keys($apps) as $appKey) {
    if (!in_array($appKey, $appOrder, true)) {
        $appOrder[] = $appKey;
    }
}

// ---------- NEW: Read app_permissions & app_layout for header behaviour ----------
$permByApp   = [];
$layoutByApp = [];

try {
    $pdo = getPDO();
    ensure_app_layout_schema($pdo);

    if ($user && isset($user['id'])) {
        $stmt = $pdo->prepare("SELECT app_key, can_view, can_edit, is_hidden FROM app_permissions WHERE user_id = ?");
        $stmt->execute([$user['id']]);
        foreach ($stmt as $row) {
            $permByApp[$row['app_key']] = [
                'can_view' => (int)$row['can_view'],
                'can_edit' => (int)$row['can_edit'],
                'is_hidden'=> (int)$row['is_hidden'],
            ];
        }
    }

    $stmt2 = $pdo->query("SELECT app_key, group_name, sort_order FROM app_layout");
    foreach ($stmt2 as $row) {
        $layoutByApp[$row['app_key']] = [
            'group_name' => $row['group_name'],
            'sort_order' => (int)$row['sort_order'],
        ];
    }
} catch (Throwable $e) {
    // If tables don't exist yet, header still works fine
}

// Optional: use layout sort_order to slightly adjust order (without breaking user drag)
usort($appOrder, function($a, $b) use ($layoutByApp, $apps) {
    $oa = $layoutByApp[$a]['sort_order'] ?? 0;
    $ob = $layoutByApp[$b]['sort_order'] ?? 0;
    if ($oa !== $ob) {
        return $oa <=> $ob;
    }
    // fallback: keep definition order of $apps
    $idxA = array_search($a, array_keys($apps), true);
    $idxB = array_search($b, array_keys($apps), true);
    return $idxA <=> $idxB;
});

// Function to check if we should output HTML headers
function shouldOutputHeaders() {
    // Skip full HTML header for AJAX / export / download requests
    if (function_exists('hrs_is_ajax_request') && hrs_is_ajax_request()) { return false; }
    if (function_exists('hrs_is_export_request') && hrs_is_export_request()) { return false; }
    global $forceHeader; // legacy compatibility override
    
    // If explicitly forced, always output full header
    if (!empty($forceHeader)) {
        return true;
    }

    $currentPath = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?? '';
    
    $noHeaderScripts = [
        '/download',
        '/export',
        '/api/',
        'download.php',
        'export.php',
        '/print/',
        'print.php',
        '/pdf/',
        'pdf.php'
    ];
    
    // Extra protection: do NOT output full layout when URL is used for download/print/import modes
    $query = $_GET ?? [];
    if (
        isset($query['download']) ||
        isset($query['export']) ||
        (isset($query['mode']) && in_array($query['mode'], ['download', 'export', 'print', 'pdf', 'import'], true))
    ) {
        return false;
    }

    foreach ($noHeaderScripts as $script) {
        if (strpos($currentPath, $script) !== false) {
            return false;
        }
    }
    
    return true;
}

$outputHTML = shouldOutputHeaders();

// Enhanced Print/Export Configuration
$printManager = PrintExportManager::getInstance();
$printConfig = $printManager->getPrintConfig();

// If we're outputting HTML, start the document
if ($outputHTML):
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title><?php echo h($pageTitle); ?></title>
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="description" content="SMART Nexus - Advanced Project Monitoring and Reporting Platform with AI-Powered Insights">
    
    <!-- Font Awesome for Icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">

    <!-- SortableJS for drag and drop -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/Sortable/1.15.0/Sortable.min.js"></script>

    <!-- SheetJS for Excel Export -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>

    <!-- Chart.js for Analytics -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/3.9.1/chart.min.js"></script>

    <!-- SMART Nexus Cross-App Communication Client -->
    <script src="<?php echo $BASE_URL; ?>/assets/cross-app-client.js?v=3.0"></script>

    <!-- LEGACY EXTRA HEAD HOOKS (CSS / JS from pages) -->
    <?php 
        // Allow pages to inject additional <link>, <style>, <script> tags into <head>
        if (!empty($extra_head_css)) {
            echo $extra_head_css . "\n";
        }
        if (!empty($extra_head_js)) {
            echo $extra_head_js . "\n";
        }
    ?>

    <style>
        :root {
            --primary: #4361ee;
            --secondary: #3f37c9;
            --success: #4cc9f0;
            --warning: #f72585;
            --info: #4895ef;
            --light: #f8f9fa;
            --dark: #212529;
            --gradient-primary: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            --gradient-secondary: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
            --gradient-success: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
            --gradient-warning: linear-gradient(135deg, #fa709a 0%, #fee140 100%);
            --gradient-apps: linear-gradient(135deg, #1e3c72 0%, #2a5298 100%);
            --shadow: 0 8px 24px rgba(0,0,0,0.1);
            --shadow-hover: 0 12px 32px rgba(0,0,0,0.15);
            --border-radius: 16px;
            --transition: all 0.3s ease;
        }
        
        * { 
            box-sizing: border-box; 
            margin: 0;
            padding: 0;
        }
        
        body {
            font-family: 'Inter', system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            background: linear-gradient(135deg, #f8fafc 0%, #e2e8f0 100%);
            color: #334155;
            line-height: 1.6;
            min-height: 100vh;
        }
        
        a { 
            text-decoration: none; 
            color: inherit;
        }

        .welcome-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: var(--gradient-primary);
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            z-index: 9999;
            animation: fadeOut 1.2s ease-in-out 2.6s forwards;
        }

        .welcome-content {
            text-align: center;
            color: white;
            animation: zoomIn 0.8s ease-out;
        }

        .welcome-avatar {
            width: 110px;
            height: 110px;
            border-radius: 50%;
            margin: 0 auto 16px;
            border: 4px solid rgba(255,255,255,0.8);
            box-shadow: 0 8px 24px rgba(0,0,0,0.3);
            animation: bounce 2s infinite;
            object-fit: cover;
            background: var(--gradient-warning);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2.2rem;
            color: white;
        }

        .welcome-greeting {
            font-size: 2.1rem;
            font-weight: 900;
            margin-bottom: 8px;
            background: linear-gradient(45deg, #FFD700, #FFA500, #FF8C00);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            animation: textGlow 2s ease-in-out infinite;
        }

        .welcome-sub {
            font-size: 1.05rem;
            opacity: 0.9;
            margin-bottom: 20px;
            max-width: 480px;
            line-height: 1.5;
        }

        .welcome-platform {
            font-size: 1.25rem;
            font-weight: 700;
            background: rgba(255,255,255,0.2);
            padding: 10px 28px;
            border-radius: 40px;
            backdrop-filter: blur(10px);
            border: 2px solid rgba(255,255,255,0.3);
        }

        @keyframes fadeOut {
            to { opacity: 0; visibility: hidden; }
        }

        @keyframes zoomIn {
            from { transform: scale(0.5); opacity: 0; }
            to { transform: scale(1); opacity: 1; }
        }

        @keyframes bounce {
            0%, 100% { transform: translateY(0); }
            50% { transform: translateY(-8px); }
        }

        @keyframes textGlow {
            0%, 100% { text-shadow: 0 0 16px rgba(255, 215, 0, 0.5); }
            50% { text-shadow: 0 0 24px rgba(255, 165, 0, 0.8), 0 0 32px rgba(255, 140, 0, 0.6); }
        }

        .topbar {
            background: var(--gradient-primary);
            color: white;
            position: relative;
            overflow: hidden;
            box-shadow: 0 6px 24px rgba(67, 97, 238, 0.3);
            padding: 0;
        }

        .topbar::before {
            content: "";
            position: absolute;
            top: 0; left: 0; right: 0; bottom: 0;
            background: url('data:image/svg+xml;utf8,<svg viewBox="0 0 1200 120" xmlns="http://www.w3.org/2000/svg"><path d="M0 0v46.29c47.79 22.2 103.59 32.17 158 28 70.36-5.37 136.33-33.31 206.8-37.5 73.84-4.36 147.54 16.88 218.2 35.26 69.27 18 138.3 24.88 209.4 13.08 36.15-6 69.85-17.84 104.45-29.34C989.49 25 1113-14.29 1200 52.47V0z" fill="%23ffffff" opacity=".15"/></svg>');
            background-size: cover;
            animation: wave 25s linear infinite;
        }

        @keyframes wave {
            0% { transform: translateX(0); }
            100% { transform: translateX(-50%); }
        }

        .topbar-content {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 8px 16px;
            position: relative;
            z-index: 2;
        }

        .topbar-left {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .brand-logo {
            height: 40px;
            width: auto;
            object-fit: contain;
            filter: drop-shadow(0 4px 10px rgba(0,0,0,0.3));
            animation: logoFloat 5s ease-in-out infinite;
        }

        @keyframes logoFloat {
            0%, 100% { transform: translateY(0) rotate(0deg) scale(1); }
            25% { transform: translateY(-2px) rotate(1deg) scale(1.02); }
            50% { transform: translateY(-1px) rotate(-1deg) scale(1.01); }
            75% { transform: translateY(-1px) rotate(0.5deg) scale(1.015); }
        }

        .brand-text {
            display: flex;
            flex-direction: column;
        }

        .brand-main {
            font-size: 18px;
            font-weight: 900;
            letter-spacing: -0.4px;
            background: linear-gradient(45deg, #FFD700, #FFA500, #FF8C00, #FFD700);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            background-size: 200% 200%;
            animation: textGlow 4s ease-in-out infinite, gradientShift 3s ease infinite;
        }

        @keyframes gradientShift {
            0% { background-position: 0% 50%; }
            50% { background-position: 100% 50%; }
            100% { background-position: 0% 50%; }
        }

        .brand-sub {
            font-size: 10px;
            font-weight: 600;
            opacity: 0.95;
            letter-spacing: 0.8px;
            margin-top: 1px;
        }

        .topbar-center {
            flex: 1;
            text-align: center;
            padding: 0 10px;
        }

        .welcome-main {
            font-size: 16px;
            font-weight: 800;
            margin-bottom: 2px;
            text-shadow: 0 3px 6px rgba(0,0,0,0.3);
            animation: bounceIn 0.8s ease-out;
        }

        .welcome-sub {
            font-size: 11px;
            opacity: 0.95;
            font-weight: 500;
            background: rgba(255,255,255,0.2);
            padding: 4px 12px;
            border-radius: 40px;
            backdrop-filter: blur(10px);
            display: inline-block;
            max-width: 460px;
            margin: 0 auto;
        }

        .daily-quote {
            font-size: 10px;
            font-style: italic;
            margin-top: 4px;
            opacity: 0.9;
            animation: fadeIn 1.8s ease-in;
        }

        @keyframes bounceIn {
            0% { transform: scale(0.3); opacity: 0; }
            50% { transform: scale(1.05); }
            70% { transform: scale(0.9); }
            100% { transform: scale(1); opacity: 1; }
        }

        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 0.9; }
        }

        .topbar-right {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .user-chip {
            display: flex;
            align-items: center;
            gap: 8px;
            background: rgba(255, 255, 255, 0.2);
            backdrop-filter: blur(15px);
            padding: 6px 12px;
            border-radius: 40px;
            border: 2px solid rgba(255, 255, 255, 0.3);
            transition: var(--transition);
            box-shadow: 0 4px 18px rgba(0,0,0,0.1);
            position: relative;
            cursor: pointer;
        }

        .user-chip:hover {
            background: rgba(255, 255, 255, 0.3);
            transform: translateY(-1px);
            box-shadow: 0 8px 22px rgba(0,0,0,0.18);
        }

        .user-photo {
            height: 34px;
            width: 34px;
            border-radius: 50%;
            border: 2px solid rgba(255, 255, 255, 0.9);
            object-fit: cover;
            background: rgba(255, 255, 255, 0.3);
            box-shadow: 0 3px 10px rgba(0,0,0,0.2);
            transition: var(--transition);
        }

        .user-chip:hover .user-photo {
            transform: scale(1.08) rotate(4deg);
        }

        .user-initials {
            height: 34px;
            width: 34px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            background: var(--gradient-warning);
            border: 2px solid rgba(255, 255, 255, 0.9);
            font-weight: 800;
            font-size: 13px;
            box-shadow: 0 3px 10px rgba(0,0,0,0.2);
            transition: var(--transition);
        }

        .user-chip:hover .user-initials {
            transform: scale(1.08) rotate(4deg);
        }

        .user-meta {
            display: flex;
            flex-direction: column;
        }

        .user-name { 
            font-weight: 800;
            font-size: 11px;
        }
        
        .user-role { 
            font-size: 9px;
            opacity: 0.9;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.4px;
        }

        .top-links {
            display: flex;
            gap: 6px;
        }

        .top-link {
            padding: 6px 10px;
            border-radius: 40px;
            border: 2px solid rgba(255, 255, 255, 0.4);
            font-size: 10px;
            font-weight: 700;
            transition: var(--transition);
            display: flex;
            align-items: center;
            gap: 4px;
            backdrop-filter: blur(10px);
            background: rgba(255, 255, 255, 0.15);
            cursor: pointer;
            white-space: nowrap;
        }

        .top-link:hover {
            background: rgba(255, 255, 255, 0.3);
            transform: translateY(-1px);
            box-shadow: 0 6px 18px rgba(0,0,0,0.18);
        }

        .notification-badge {
            position: absolute;
            top: -4px;
            right: -4px;
            background: var(--warning);
            color: white;
            border-radius: 50%;
            width: 16px;
            height: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 9px;
            font-weight: 800;
            animation: pulse 2s infinite;
        }

        .apps-nav {
            background: var(--gradient-apps);
            padding: 12px 16px;
            position: relative;
            overflow: hidden;
            box-shadow: 0 6px 20px rgba(30, 60, 114, 0.35);
        }

        .apps-nav::before {
            content: "";
            position: absolute;
            top: 0; left: 0; right: 0; bottom: 0;
            background: url('data:image/svg+xml;utf8,<svg viewBox="0 0 1200 120" xmlns="http://www.w3.org/2000/svg"><path d="M0 0v46.29c47.79 22.2 103.59 32.17 158 28 70.36-5.37 136.33-33.31 206.8-37.5 73.84-4.36 147.54 16.88 218.2 35.26 69.27 18 138.3 24.88 209.4 13.08 36.15-6 69.85-17.84 104.45-29.34C989.49 25 1113-14.29 1200 52.47V0z" fill="%23ffffff" opacity=".08"/></svg>');
            background-size: cover;
            animation: waveReverse 20s linear infinite;
        }

        @keyframes waveReverse {
            0% { transform: translateX(-50%); }
            100% { transform: translateX(0); }
        }

        .apps-header {
            text-align: center;
            margin-bottom: 10px;
            position: relative;
            z-index: 2;
        }

        .apps-title {
            font-size: 18px;
            font-weight: 900;
            color: white;
            margin-bottom: 4px;
            text-shadow: 0 4px 10px rgba(0,0,0,0.35);
        }

        .apps-subtitle {
            font-size: 11px;
            color: rgba(255,255,255,0.9);
            font-weight: 500;
            max-width: 480px;
            margin: 0 auto;
            animation: fadeInOut 8s infinite;
        }

        @keyframes fadeInOut {
            0%, 100% { opacity: 0.7; }
            50% { opacity: 1; }
        }

        .search-container {
            max-width: 360px;
            margin: 0 auto 12px;
            position: relative;
        }

        .search-input {
            width: 100%;
            padding: 8px 12px 8px 34px;
            border-radius: 22px;
            border: 2px solid rgba(255,255,255,0.3);
            background: rgba(255,255,255,0.15);
            backdrop-filter: blur(10px);
            color: white;
            font-size: 13px;
            transition: var(--transition);
        }

        .search-input::placeholder {
            color: rgba(255,255,255,0.7);
        }

        .search-input:focus {
            outline: none;
            background: rgba(255,255,255,0.25);
            border-color: rgba(255,255,255,0.5);
            box-shadow: 0 0 18px rgba(255,255,255,0.2);
        }

        .search-icon {
            position: absolute;
            left: 12px;
            top: 50%;
            transform: translateY(-50%);
            color: rgba(255,255,255,0.8);
            font-size: 12px;
        }

        .apps-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(80px, 1fr));
            gap: 5px;
            position: relative;
            z-index: 2;
            max-width: 1100px;
            margin: 0 auto;
        }

        .app-card {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(20px);
            border-radius: 8px;
            padding: 8px 4px 10px 4px;
            text-align: center;
            transition: var(--transition);
            border: 1px solid rgba(255, 255, 255, 0.3);
            box-shadow: 0 3px 10px rgba(0,0,0,0.1);
            position: relative;
            overflow: hidden;
            cursor: grab;
            user-select: none;
        }

        .app-card:active {
            cursor: grabbing;
            transform: rotate(3deg) scale(1.05);
            box-shadow: 0 8px 20px rgba(0,0,0,0.2);
        }

        .app-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.4), transparent);
            transition: left 0.6s ease;
        }

        .app-card:hover {
            transform: translateY(-2px) scale(1.02);
            box-shadow: 0 6px 20px rgba(0,0,0,0.15);
        }

        .app-card.nav-active {
            background: rgba(255, 255, 255, 1);
            box-shadow: 0 6px 18px rgba(67, 97, 238, 0.3);
            border: 2px solid var(--primary);
        }

        .app-icon {
            font-size: 15px;
            height: 28px;
            width: 28px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 8px;
            margin: 0 auto 4px;
            color: white;
            box-shadow: 0 3px 9px rgba(0,0,0,0.3);
            transition: var(--transition);
        }

        .app-card:hover .app-icon {
            transform: scale(1.18) rotate(4deg);
        }

        .app-title {
            font-size: 10px;
            font-weight: 700;
            color: #1e293b;
            margin-bottom: 2px;
            line-height: 1.2;
        }

        .app-desc {
            font-size: 8px;
            color: #64748b;
            line-height: 1.2;
            font-weight: 500;
        }

        /* NEW: display group name coming from app_management layout */
        .app-group-label {
            font-size: 8px;
            margin-top: 2px;
            color: #475569;
            font-style: italic;
            opacity: 0.95;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .nav-badge {
            position: absolute;
            top: 5px;
            right: 5px;
            background: var(--warning);
            color: white;
            border-radius: 50px;
            padding: 1px 5px;
            font-size: 8px;
            font-weight: 800;
            min-width: 14px;
            text-align: center;
            animation: pulse 2s infinite;
            box-shadow: 0 2px 8px rgba(247, 37, 133, 0.4);
        }

        @keyframes pulse {
            0% { transform: scale(1); }
            50% { transform: scale(1.1); }
            100% { transform: scale(1); }
        }

        .sortable-ghost {
            opacity: 0.4;
            background: rgba(255,255,255,0.5);
        }

        .sortable-chosen {
            transform: rotate(3deg);
            box-shadow: 0 10px 28px rgba(0,0,0,0.3);
        }

        .sortable-drag {
            opacity: 0.9;
            transform: rotate(5deg) scale(1.05);
        }

        .cross-app-panel {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 10px 16px;
            border-radius: 8px;
            margin: 12px 16px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
            border-left: 4px solid #4cc9f0;
        }

        .cross-app-data {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            font-size: 11px;
        }

        .data-item {
            background: rgba(255,255,255,0.2);
            padding: 6px 10px;
            border-radius: 6px;
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255,255,255,0.3);
        }

        .data-item strong {
            color: #4cc9f0;
            margin-right: 4px;
        }

        .cross-app-controls {
            display: flex;
            gap: 8px;
        }

        .cross-app-btn {
            background: rgba(255,255,255,0.2);
            border: 1px solid rgba(255,255,255,0.4);
            color: white;
            padding: 4px 10px;
            border-radius: 4px;
            font-size: 11px;
            cursor: pointer;
            transition: var(--transition);
            backdrop-filter: blur(10px);
        }

        .cross-app-btn:hover {
            background: rgba(255,255,255,0.3);
            transform: translateY(-1px);
        }

        .page-container {
            padding: 16px;
            animation: fadeInUp 0.8s ease-out;
        }

        @keyframes fadeInUp {
            from { opacity: 0; transform: translateY(24px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .card {
            background: white;
            border-radius: var(--border-radius);
            padding: 16px;
            margin-bottom: 16px;
            box-shadow: var(--shadow);
            border: none;
            transition: var(--transition);
        }

        .card:hover {
            box-shadow: var(--shadow-hover);
        }

        .card h1, .card h2, .card h3 {
            margin-top: 0;
            color: #1e293b;
            font-weight: 800;
        }

        .admin-badge {
            background: var(--gradient-warning);
            color: white;
            padding: 1px 6px;
            border-radius: 50px;
            font-size: 8px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.4px;
            margin-left: 4px;
            box-shadow: 0 2px 8px rgba(247, 37, 133, 0.3);
        }

        .status-indicator {
            display: inline-flex;
            align-items: center;
            gap: 3px;
            padding: 1px 6px;
            border-radius: 20px;
            font-size: 9px;
            font-weight: 600;
            margin-left: 5px;
        }

        .status-online {
            background: rgba(76, 201, 240, 0.2);
            color: #4cc9f0;
            border: 1px solid #4cc9f0;
        }

        .status-busy {
            background: rgba(247, 37, 133, 0.2);
            color: #f72585;
            border: 1px solid #f72585;
        }

        .action-buttons {
            display: flex;
            gap: 8px;
            margin: 16px 0;
            flex-wrap: wrap;
            align-items: center;
        }

        .btn {
            padding: 7px 14px;
            border: none;
            border-radius: 6px;
            font-size: 11px;
            font-weight: 600;
            cursor: pointer;
            transition: var(--transition);
            display: inline-flex;
            align-items: center;
            gap: 5px;
            text-decoration: none;
        }

        .btn-primary { background: var(--primary); color: white; }
        .btn-success { background: var(--success); color: white; }
        .btn-warning { background: var(--warning); color: white; }
        .btn-danger  { background: #dc3545; color: white; }
        .btn-info    { background: var(--info); color: white; }
        .btn-secondary { background: #6c757d; color: white; }

        .btn:hover {
            transform: translateY(-1px);
            box-shadow: 0 3px 10px rgba(0,0,0,0.2);
        }

        .btn:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none;
        }

        @media print {
            .topbar, .apps-nav, .action-buttons, .no-print, .cross-app-panel {
                display: none !important;
            }
            
            body {
                background: white !important;
            }

            .page-container {
                padding: 0;
                margin: 0;
            }
            
            .card {
                box-shadow: none;
                border: 1px solid #ddd;
                margin: 8px 0;
                page-break-inside: avoid;
            }
            
            .print-header {
                display: block !important;
                text-align: center;
                margin-bottom: 16px;
                padding: 16px;
                border-bottom: 2px solid #333;
                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%) !important;
                color: white !important;
                border-radius: 6px;
            }
            
            .print-title {
                font-size: 20px;
                font-weight: bold;
                margin-bottom: 4px;
                color: white !important;
            }
            
            .print-subtitle {
                font-size: 13px;
                color: rgba(255,255,255,0.9) !important;
            }
            
            .print-project-info {
                background: rgba(255,255,255,0.2);
                padding: 8px;
                margin: 8px 0;
                border-radius: 4px;
                text-align: left;
            }
            
            table {
                width: 100%;
                border-collapse: collapse;
                page-break-inside: auto;
            }
            
            table th, table td {
                border: 1px solid #ddd;
                padding: 6px;
                font-size: 11px;
            }
            
            table th {
                background-color: #f8f9fa;
                font-weight: bold;
            }
            
            tr {
                page-break-inside: avoid;
                page-break-after: auto;
            }
        }

        .print-header {
            display: none;
        }

        .visibility-controls {
            background: #f8f9fa;
            padding: 10px;
            border-radius: 8px;
            margin: 10px 0;
            border: 1px solid #dee2e6;
            box-shadow: 0 2px 4px rgba(0,0,0,0.08);
        }

        .visibility-options {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-top: 8px;
        }

        .visibility-option {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 11px;
            background: white;
            padding: 5px 8px;
            border-radius: 6px;
            border: 1px solid #e9ecef;
            cursor: pointer;
            transition: all 0.2s;
        }

        .visibility-option:hover {
            background: #e9ecef;
        }

        .hidden-column {
            display: none !important;
        }

        .hidden-row {
            display: none !important;
        }

        .table-responsive {
            overflow-x: auto;
            margin: 10px 0;
        }

        .data-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 11px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
        }

        .data-table th, .data-table td {
            border: 1px solid #dee2e6;
            padding: 8px;
            text-align: left;
        }

        .data-table th {
            background-color: #f8f9fa;
            font-weight: 600;
            position: sticky;
            top: 0;
            color: #4361ee;
        }

        .data-table tr:hover {
            background-color: #f8f9fa;
        }

        .photo-error {
            display: none;
        }

        @media (max-width: 1200px) {
            .apps-grid {
                grid-template-columns: repeat(auto-fit, minmax(75px, 1fr));
            }
        }

        @media (max-width: 1024px) {
            .topbar-content {
                flex-wrap: wrap;
                gap: 10px;
            }
            
            .topbar-center {
                flex-basis: 100%;
                padding: 6px 0 0;
            }

            .apps-grid {
                grid-template-columns: repeat(auto-fit, minmax(70px, 1fr));
                gap: 4px;
            }

            .cross-app-panel {
                flex-direction: column;
                gap: 8px;
                text-align: center;
            }

            .cross-app-data {
                justify-content: center;
            }
        }

        @media (max-width: 768px) {
            .topbar-content {
                flex-direction: column;
                text-align: center;
                gap: 10px;
            }
            
            .topbar-left, .topbar-right {
                width: 100%;
                justify-content: center;
            }
            
            .topbar-center {
                order: 0;
            }

            .apps-nav {
                padding: 10px 12px;
            }

            .apps-grid {
                grid-template-columns: repeat(auto-fit, minmax(65px, 1fr));
                gap: 4px;
            }

            .app-card {
                padding: 7px 3px 9px 3px;
            }

            .app-icon {
                height: 24px;
                width: 24px;
                font-size: 13px;
                margin-bottom: 3px;
            }

            .app-title {
                font-size: 9px;
            }

            .page-container {
                padding: 12px 10px;
            }
            
            .welcome-main {
                font-size: 15px;
            }
            
            .brand-main {
                font-size: 16px;
            }

            .apps-title {
                font-size: 16px;
            }

            .action-buttons {
                gap: 6px;
            }

            .btn {
                padding: 6px 10px;
                font-size: 10px;
            }

            .cross-app-panel {
                margin: 8px 10px;
                padding: 10px 12px;
            }
        }

        @media (max-width: 480px) {
            .user-chip {
                padding: 5px 8px;
            }
            
            .top-link {
                padding: 5px 8px;
                font-size: 9px;
            }

            .apps-grid {
                grid-template-columns: repeat(3, 1fr);
            }

            .app-card {
                padding: 5px 2px 8px 2px;
            }

            .app-icon {
                height: 22px;
                width: 22px;
                font-size: 12px;
            }

            .app-title {
                font-size: 8px;
            }

            .app-desc {
                font-size: 7px;
            }

            .welcome-main {
                font-size: 13px;
            }

            .welcome-sub {
                font-size: 9px;
                padding: 3px 10px;
            }

            .action-buttons {
                justify-content: center;
            }

            .btn {
                flex: 1;
                min-width: 80px;
                justify-content: center;
            }

            .cross-app-data {
                flex-direction: column;
                gap: 6px;
            }

            .data-item {
                text-align: center;
            }
        }
    </style>

<link rel="manifest" href="/hrs/pwa/manifest.json">
<meta name="theme-color" content="#0b5ed7">
<link rel="icon" href="/hrs/icons/icon-192.png">
<link rel="apple-touch-icon" href="/hrs/icons/icon-192.png">

</head>
<body>
<?php if ($showWelcomeAnimation && $user): ?>
<div class="welcome-overlay">
    <div class="welcome-content">
        <?php if ($photoSrc && !str_contains($photoSrc, 'data:image/svg')): ?>
            <img src="<?php echo h($photoSrc); ?>" alt="Welcome" class="welcome-avatar"
                 onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
            <div class="welcome-avatar" style="display: none;">
                <?php
                    $initialsSource = $displayName ?: ($user['email'] ?? '');
                    $initials = strtoupper(substr($initialsSource, 0, 2));
                    echo h($initials);
                ?>
            </div>
        <?php else: ?>
            <div class="welcome-avatar">
                <?php
                    $initialsSource = $displayName ?: ($user['email'] ?? '');
                    $initials = strtoupper(substr($initialsSource, 0, 2));
                    echo h($initials);
                ?>
            </div>
        <?php endif; ?>
        <h1 class="welcome-greeting">🌟 <?php echo $greeting; ?>, <?php echo htmlspecialchars($displayName); ?>!</h1>
        <p class="welcome-sub"><?php echo $welcomeMessage; ?></p>
        <div class="welcome-platform">Welcome to SMART Nexus Project Monitoring and Reporting Platform!</div>
    </div>
</div>
<?php elseif ($showWelcomeAnimation && !$user): ?>
<div class="welcome-overlay">
    <div class="welcome-content">
        <div class="welcome-avatar">
            👤
        </div>
        <h1 class="welcome-greeting">🌟 Welcome, Guest!</h1>
        <p class="welcome-sub">Discover the power of SMART Nexus platform</p>
        <div class="welcome-platform">Welcome to SMART Nexus Project Monitoring and Reporting Platform!</div>
    </div>
</div>
<?php endif; ?>

<header class="topbar">
    <div class="topbar-content">
        <div class="topbar-left">
            <img
                class="brand-logo"
                src="<?php echo $BASE_URL; ?>/assets/nexus-logo.gif"
                onerror="this.onerror=null;this.src='<?php echo $BASE_URL; ?>/assets/nexus-logo.png';"
                alt="Nexus Ethiopia Logo"
            >
            <div class="brand-text">
                <div class="brand-main">Nexus Ethiopia</div>
                <div class="brand-sub">SMART Nexus Platform v3.1</div>
            </div>
        </div>

        <div class="topbar-center">
            <div class="welcome-main">🌟 SMART Nexus Project Monitoring and Reporting Platform</div>
            <div class="welcome-sub">
                <?php echo $greeting; ?>, 
                <?php if ($user): ?>
                    <strong><?php echo htmlspecialchars($displayName); ?></strong>!
                    <?php if ($isAdmin): ?>
                        <span class="admin-badge">Admin</span>
                    <?php endif; ?>
                <?php else: ?>
                    <strong>Guest</strong>!
                <?php endif; ?>
                <?php echo $welcomeMessage; ?>
            </div>
            <div class="daily-quote">
                <?php echo $dailyQuote; ?>
            </div>
        </div>

        <div class="topbar-right">
            <?php if ($user): ?>
                <div class="user-chip" onclick="toggleUserMenu()">
                    <?php if ($photoSrc && !str_contains($photoSrc, 'data:image/svg')): ?>
                        <img
                            src="<?php echo h($photoSrc); ?>"
                            alt="User photo"
                            class="user-photo"
                            title="Welcome back, <?php echo htmlspecialchars($displayName); ?>!"
                            onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';"
                        >
                        <div class="user-initials" style="display: none;" title="Welcome back, <?php echo htmlspecialchars($displayName); ?>!">
                            <?php
                                $initialsSource = $displayName ?: ($user['email'] ?? '');
                                $initials = strtoupper(substr($initialsSource, 0, 2));
                                echo h($initials);
                            ?>
                        </div>
                    <?php else: ?>
                        <?php
                            $initialsSource = $displayName ?: ($user['email'] ?? '');
                            $initials = strtoupper(substr($initialsSource, 0, 2));
                        ?>
                        <div class="user-initials" title="Welcome back, <?php echo htmlspecialchars($displayName); ?>!">
                            <?php echo h($initials); ?>
                        </div>
                    <?php endif; ?>
                    <div class="user-meta">
                        <span class="user-name">
                            <?php echo h($displayName); ?>
                            <span class="status-indicator status-online">
                                <i class="fas fa-circle"></i> Online
                            </span>
                        </span>
                        <?php if (!empty($user['role'])): ?>
                            <span class="user-role"><?php echo h(ucfirst($user['role'])); ?> Specialist</span>
                        <?php endif; ?>
                    </div>
                    <?php if ($unreadMessages > 0): ?>
                        <div class="notification-badge"><?php echo $unreadMessages; ?></div>
                    <?php endif; ?>
                </div>
                <div class="top-links">
                    <button class="top-link" type="button" onclick="scrollToApps()" title="Open App Center">
                        <i class="fas fa-th-large"></i> Apps
                    </button>
                    <a class="top-link" href="<?php echo $BASE_URL; ?>/pages/nexus_ai.php" title="AI Assistant & Support">
                        <i class="fas fa-robot"></i> Nexus AI
                    </a>
                    <a class="top-link" href="<?php echo $BASE_URL; ?>/profile.php" title="Manage Profile">
                        <i class="fas fa-user-cog"></i> Profile
                    </a>
                    <a class="top-link" href="<?php echo $BASE_URL; ?>/logout.php" title="Sign Out">
                        <i class="fas fa-sign-out-alt"></i> Exit
                    </a>
                </div>
            <?php else: ?>
                <div class="top-links">
                    <a class="top-link" href="<?php echo $BASE_URL; ?>/login.php" title="Access Your Account">
                        <i class="fas fa-sign-in-alt"></i> Sign In
                    </a>
                </div>
            <?php endif; ?>
        </div>
    </div>
</header>

<?php if ($user): ?>
<nav class="apps-nav" id="appsNav">
    <div class="apps-header">
        <h1 class="apps-title">🚀 SMART-NEXUS App Center</h1>
        <p class="apps-subtitle"><?php echo $currentMessage; ?></p>
    </div>

    <div class="search-container">
        <i class="fas fa-search search-icon"></i>
        <input type="text" class="search-input" placeholder="Search applications..." id="appSearch">
    </div>
    
    <div class="apps-grid" id="appsGrid">
        <?php 
        foreach ($appOrder as $appKey) {
            if (!isset($apps[$appKey])) {
                continue;
            }

            // App Management: header visible only for admin
            if ($appKey === 'app_management' && !$isAdmin) {
                continue;
            }

            // Respect per-user overrides from app_permissions
            if (isset($permByApp[$appKey])) {
                $perm = $permByApp[$appKey];
                if (!$perm['can_view'] || $perm['is_hidden']) {
                    continue; // hidden from this user (header + menu)
                }
            }

            if (function_exists('user_can_view_app') && !user_can_view_app($appKey)) {
                continue;
            }

            $app = $apps[$appKey];

            // ✅ Build correct path for each app (special case for dashboard & project_activity_report)
            if ($appKey === 'dashboard') {
                $appPath = '/index.php';
            } elseif ($appKey === 'project_activity_report') {
                // NEW APP is located in /pages/
                $appPath = '/pages/project_activity_report.php';
            } else {
                $appPath = '/pages/' . $appKey . '.php';
            }

            $isActive = nav_active($appPath, $currentPath);
            $groupLabel = $layoutByApp[$appKey]['group_name'] ?? null;
        ?>
            <a class="app-card<?php echo $isActive; ?>" 
               href="<?php echo $BASE_URL . $appPath; ?>" 
               data-app="<?php echo $appKey; ?>" 
               title="<?php echo $app['title']; ?>">
                <div class="app-icon" style="background: <?php echo $app['color']; ?>">
                    <i class="fas fa-<?php echo $app['icon']; ?>"></i>
                </div>
                <div class="app-title"><?php echo $app['title']; ?></div>
                <div class="app-desc"><?php echo $app['desc']; ?></div>
                <?php if (!empty($groupLabel)): ?>
                    <div class="app-group-label"><?php echo h($groupLabel); ?></div>
                <?php endif; ?>
                <?php if ($appKey === 'messages' && $unreadMessages > 0): ?>
                    <span class="nav-badge"><?php echo $unreadMessages; ?></span>
                <?php endif; ?>
            </a>
        <?php
        }
        ?>
    </div>
</nav>

<div class="cross-app-panel" id="crossAppPanel">
    <div class="cross-app-data">
        <div class="data-item">
            <strong>Current Project:</strong> 
            <span id="currentProjectDisplay"><?php echo h($currentProject['title'] ?? 'All Projects'); ?></span>
        </div>
        <div class="data-item">
            <strong>Active App:</strong> 
            <span id="currentAppDisplay"><?php echo basename($currentPath, '.php'); ?></span>
        </div>
        <div class="data-item">
            <strong>Last Updated:</strong> 
            <span id="lastUpdateDisplay"><?php echo date('Y-m-d H:i:s'); ?></span>
        </div>
        <div class="data-item">
            <strong>System Status:</strong> 
            <span id="systemStatusDisplay">
                <?php echo (($systemStatus['cross_app_communication'] ?? '') === 'active') ? '🟢 Online' : '🔴 Offline'; ?>
            </span>
        </div>
    </div>
    <div class="cross-app-controls">
        <button class="cross-app-btn" onclick="refreshCrossAppData()">
            <i class="fas fa-sync-alt"></i> Refresh
        </button>
        <button class="cross-app-btn" onclick="syncAllApps()">
            <i class="fas fa-cloud-upload-alt"></i> Sync All
        </button>
    </div>
</div>
<?php endif; ?>

<div class="page-container">

<div class="print-header" id="printHeader">
    <div class="print-title" id="printTitle">Nexus Ethiopia Project Performance Report</div>
    <div class="print-subtitle" id="printSubtitle">Generated on <?php echo date('Y-m-d H:i:s'); ?></div>
    <div class="print-project-info" id="printProjectInfo">
        <strong>Project Title :</strong> 
        <span id="printProjectName"><?php echo h($currentProject['title'] ?? 'All Projects'); ?></span>
        <?php if ($currentProject && isset($currentProject['description'])): ?>
            <br><strong>Description:</strong> 
            <span id="printProjectDescription"><?php echo h($currentProject['description']); ?></span>
        <?php endif; ?>
        <br>
        <strong>Region:</strong> <span id="printRegion">___________</span>
        &nbsp;&nbsp;<strong>Zone</strong> <span id="printZone">_________</span>
        &nbsp;&nbsp;<strong>Name of Woreda -</strong> <span id="printWoreda">_________</span>
        &nbsp;&nbsp;<strong>Report type</strong> <span id="printReportType">_________</span>
    </div>
</div>

<script>
// Global app base URL for cross-module API calls
window.APP_BASE_URL = '<?php echo $BASE_URL; ?>';
window.APP_VERSION = '3.1';

// Load enhanced Cross-App Client if available
if (typeof window.CrossApp === 'undefined' || !window.CrossApp.version || window.CrossApp.version < '3.0') {
    // Fallback to basic implementation if enhanced client not loaded
    window.CrossApp = {
    data: <?php echo json_encode($crossApp->getAllAppData()); ?>,
    listeners: {},
    
    setData(key, value, app = 'global') {
        if (!this.data[app]) this.data[app] = {};
        this.data[app][key] = value;
        
        this.persistData(key, value, app);
        this.updateDisplay();
        this.notifyListeners(key, value, app);
    },
    
    getData(key, app = 'global', defaultValue = null) {
        return this.data[app]?.[key] ?? defaultValue;
    },
    
    getAllData(app = 'global') {
        return this.data[app] || {};
    },
    
    persistData(key, value, app = 'global') {
        fetch(window.APP_BASE_URL + '/api/cross_app.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ 
                action: 'set_data', 
                app: app, 
                key: key, 
                value: value,
                timestamp: new Date().toISOString()
            })
        }).catch(error => {
            console.error('Failed to persist data:', error);
        });
    },
    
    updateDisplay() {
        const projectDisplay = document.getElementById('currentProjectDisplay');
        const project = this.getData('current_project', 'projects');
        if (projectDisplay && project) {
            projectDisplay.textContent = project.title || 'All Projects';
        }
        
        const appDisplay = document.getElementById('currentAppDisplay');
        if (appDisplay) {
            const currentApp = this.getData('current_app', 'navigation', 'Dashboard');
            appDisplay.textContent = currentApp;
        }
        
        const systemStatus = document.getElementById('systemStatusDisplay');
        if (systemStatus) {
            systemStatus.innerHTML = '🟢 Online';
        }
        
        const lastUpdate = document.getElementById('lastUpdateDisplay');
        if (lastUpdate) {
            lastUpdate.textContent = new Date().toLocaleString();
        }
    },
    
    syncProject(projectId, projectData) {
        this.setData('current_project', projectData, 'projects');
        this.setData('last_project_sync', new Date().toISOString(), 'projects');
        this.setData('active_project_id', projectId, 'global');
        
        if (window.PrintExport) {
            window.PrintExport.setupPrint(
                window.PrintExport.config.title,
                window.PrintExport.config.subtitle,
                projectData
            );
        }
        
        document.dispatchEvent(new CustomEvent('projectChanged', {
            detail: { projectId, projectData, timestamp: new Date().toISOString() }
        }));
        
        return true;
    },
    
    sendMessage(fromApp, toApp, message, data = {}) {
        const messageId = 'msg_' + Date.now() + '_' + Math.random().toString(36).substr(2, 9);
        const messageData = {
            id: messageId,
            from: fromApp,
            to: toApp,
            message: message,
            data: data,
            timestamp: new Date().toISOString(),
            read: false
        };
        
        this.setData(messageId, messageData, 'messages');
        this.updateNotificationBadge();
        
        return messageId;
    },
    
    updateNotificationBadge() {
        const messages = this.getAllData('messages');
        const unreadCount = Object.values(messages).filter(msg => 
            msg && !msg.read && msg.to === 'current_app'
        ).length;
        
        const badge = document.querySelector('.notification-badge');
        if (badge) {
            if (unreadCount > 0) {
                badge.textContent = unreadCount;
                badge.style.display = 'flex';
            } else {
                badge.style.display = 'none';
            }
        }
    },
    
    on(event, callback) {
        if (!this.listeners[event]) {
            this.listeners[event] = [];
        }
        this.listeners[event].push(callback);
    },
    
    notifyListeners(key, value, app) {
        const event = `dataChanged:${app}.${key}`;
        if (this.listeners[event]) {
            this.listeners[event].forEach(callback => callback(value, key, app));
        }
    }
    };
    
    // If enhanced client loaded, merge functionality
    if (window.CrossAppClient && window.CrossAppClient.version >= '3.0') {
        // Merge enhanced client methods
        Object.assign(window.CrossApp, {
            sync: window.CrossAppClient.sync.bind(window.CrossAppClient),
            sendMessage: window.CrossAppClient.sendMessage.bind(window.CrossAppClient),
            on: window.CrossAppClient.on.bind(window.CrossAppClient),
            off: window.CrossAppClient.off.bind(window.CrossAppClient),
            emit: window.CrossAppClient.emit.bind(window.CrossAppClient),
            version: '3.0'
        });
    }
}
    
    // If enhanced client loaded, merge functionality
    if (window.CrossAppClient && window.CrossAppClient.version >= '3.0') {
        // Merge enhanced client methods
        Object.assign(window.CrossApp, {
            sync: window.CrossAppClient.sync.bind(window.CrossAppClient),
            sendMessage: window.CrossAppClient.sendMessage.bind(window.CrossAppClient),
            on: window.CrossAppClient.on.bind(window.CrossAppClient),
            off: window.CrossAppClient.off.bind(window.CrossAppClient),
            emit: window.CrossAppClient.emit.bind(window.CrossAppClient),
            version: '3.0'
        });
    }
}

window.PrintExport = {
    config: {
        title: 'Nexus Ethiopia Project Performance Report',
        subtitle: 'Generated on <?php echo date('Y-m-d H:i:s'); ?>',
        project: <?php echo json_encode($currentProject ?: ['title' => 'All Projects']); ?>,
        customHeaders: {},
        orientation: 'portrait',
        includeHeader: true,
        includeFooter: true,
        showPageNumbers: true
    },
    
    setupPrint(title, subtitle = null, project = null, customHeaders = {}) {
        this.config.title = title;
        this.config.subtitle = subtitle || `Generated on ${new Date().toLocaleString()}`;
        this.config.project = project || this.config.project;
        this.config.customHeaders = customHeaders || {};
        
        this.updatePrintHeader();
        CrossApp.setData('print_config', this.config, 'printing');
        
        return this.config;
    },
    
    autoDetectTitle() {
        const appTitle = document.querySelector('[data-app-title]');
        let title = null;
        if (appTitle) {
            title = appTitle.getAttribute('data-app-title');
        } else {
            const h1 = document.querySelector('.page-container h1, .page-container .card h1');
            if (h1) title = h1.textContent.trim();
        }
        if (title) {
            this.config.title = title;
            this.updatePrintHeader();
        }
    },
    
    updatePrintHeader() {
        const printTitle = document.getElementById('printTitle');
        const printSubtitle = document.getElementById('printSubtitle');
        const printProject = document.getElementById('printProjectName');
        const printProjectDesc = document.getElementById('printProjectDescription');
        const printRegion = document.getElementById('printRegion');
        const printZone = document.getElementById('printZone');
        const printWoreda = document.getElementById('printWoreda');
        const printReportType = document.getElementById('printReportType');
        
        if (printTitle) printTitle.textContent = this.config.title;
        if (printSubtitle) printSubtitle.textContent = this.config.subtitle;
        if (printProject) {
            printProject.textContent = (this.config.project && this.config.project.title)
                ? this.config.project.title
                : this.config.project;
        }
        
        if (printProjectDesc && this.config.project && this.config.project.description) {
            printProjectDesc.textContent = this.config.project.description;
        }

        const headers = this.config.customHeaders || {};
        if (printRegion) {
            printRegion.textContent = headers.region || '___________';
        }
        if (printZone) {
            printZone.textContent = headers.zone || '_________';
        }
        if (printWoreda) {
            printWoreda.textContent = headers.woreda || '_________';
        }
        if (printReportType) {
            printReportType.textContent = headers.reportType || '_________';
        }
    },
    
    print() {
        this.autoDetectTitle();
        this.setupPrint(this.config.title, this.config.subtitle, this.config.project, this.config.customHeaders);
        this.addPrintStyles();
        window.print();
        setTimeout(() => this.removePrintStyles(), 1000);
    },
    
    addPrintStyles() {
        const style = document.createElement('style');
        style.id = 'print-styles';
        style.textContent = `
            @media print {
                body { font-size: 12pt; line-height: 1.4; }
                .card { break-inside: avoid; margin-bottom: 12px; }
                table { break-inside: avoid; }
                .print-header { display: block !important; }
                .no-print { display: none !important; }
            }
        `;
        document.head.appendChild(style);
    },
    
    removePrintStyles() {
        const styles = document.getElementById('print-styles');
        if (styles) {
            styles.remove();
        }
    },
    
    exportToExcel(tableId, filename = 'export.xlsx') {
        const table = document.getElementById(tableId);
        if (!table) {
            console.error('Table not found:', tableId);
            return;
        }
        
        this.addExportHeaders(table);
        
        const ws = XLSX.utils.table_to_sheet(table);
        const wb = XLSX.utils.book_new();
        XLSX.utils.book_append_sheet(wb, ws, 'Sheet1');
        XLSX.writeFile(wb, filename);
    },
    
    addExportHeaders(table) {
        const cloned = table.cloneNode(true);
        const headerRow1 = cloned.insertRow(0);
        const cell1 = headerRow1.insertCell(0);
        cell1.colSpan = cloned.rows[1] ? cloned.rows[1].cells.length : 1;
        cell1.innerHTML = `<strong>${this.config.title}</strong>`;
        
        const headerRow2 = cloned.insertRow(1);
        const cell2 = headerRow2.insertCell(0);
        cell2.colSpan = cell1.colSpan;
        cell2.innerHTML = `<em>${this.config.subtitle}</em>`;
        
        table._originalOuterHTML = table.outerHTML;
        table.outerHTML = cloned.outerHTML;
    },
    
    exportToWord(tableId, filename = 'export.doc') {
        const table = document.getElementById(tableId);
        if (!table) return;
        
        this.autoDetectTitle();
        
        const html = `
            <html xmlns:o="urn:schemas-microsoft-com:office:office" 
                  xmlns:w="urn:schemas-microsoft-com:office:word" 
                  xmlns="http://www.w3.org/TR/REC-html40">
            <head>
                <meta charset="utf-8">
                <title>${this.config.title}</title>
                <style>
                    body { font-family: Arial, sans-serif; margin: 20px; }
                    .header { text-align: center; margin-bottom: 30px; padding: 20px; border-bottom: 2px solid #333; }
                    table { border-collapse: collapse; width: 100%; margin: 20px 0; }
                    th, td { border: 1px solid #000; padding: 8px; text-align: left; }
                    th { background-color: #f2f2f2; font-weight: bold; }
                </style>
            
<link rel="manifest" href="/hrs/pwa/manifest.json">
<meta name="theme-color" content="#0b5ed7">
<link rel="icon" href="/hrs/icons/icon-192.png">
<link rel="apple-touch-icon" href="/hrs/icons/icon-192.png">

</head>
            <body>
                <div class="header">
                    <h1>${this.config.title}</h1>
                    <p><strong>Project Title :</strong> ${(this.config.project && this.config.project.title) ? this.config.project.title : this.config.project}</p>
                    <p><strong>Generated:</strong> ${this.config.subtitle}</p>
                    <p>
                        <strong>Region:</strong> ${(this.config.customHeaders && this.config.customHeaders.region) || '___________'}
                        &nbsp;&nbsp;<strong>Zone</strong> ${(this.config.customHeaders && this.config.customHeaders.zone) || '_________'}
                        &nbsp;&nbsp;<strong>Name of Woreda -</strong> ${(this.config.customHeaders && this.config.customHeaders.woreda) || '_________'}
                        &nbsp;&nbsp;<strong>Report type</strong> ${(this.config.customHeaders && this.config.customHeaders.reportType) || '_________'}
                    </p>
                </div>
                ${table.outerHTML}
            </body>
            </html>
        `;
        
        const blob = new Blob([html], { type: 'application/msword' });
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = filename;
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        URL.revokeObjectURL(url);
    },
    
    exportToPDF(htmlContent, filename = 'export.pdf') {
        this.autoDetectTitle();
        this.setupPrint(this.config.title, this.config.subtitle, this.config.project, this.config.customHeaders);
        
        const printWindow = window.open('', '_blank');
        printWindow.document.write(`
            <html>
            <head>
                <title>${this.config.title}</title>
                <style>
                    body { font-family: Arial, sans-serif; margin: 20px; }
                    .print-header { text-align: center; margin-bottom: 30px; padding: 20px; border-bottom: 2px solid #333; }
                    table { width: 100%; border-collapse: collapse; margin: 15px 0; }
                    table th, table td { border: 1px solid #ddd; padding: 8px; text-align: left; }
                    table th { background-color: #f8f9fa; font-weight: bold; }
                </style>
            
<link rel="manifest" href="/hrs/pwa/manifest.json">
<meta name="theme-color" content="#0b5ed7">
<link rel="icon" href="/hrs/icons/icon-192.png">
<link rel="apple-touch-icon" href="/hrs/icons/icon-192.png">

</head>
            <body>
                ${this.generatePrintHeader()}
                ${htmlContent}
            </body>
            </html>
        `);
        
        printWindow.document.close();
        printWindow.print();
    },
    
    generatePrintHeader() {
        const headers = this.config.customHeaders || {};
        return `
            <div class="print-header">
                <h1>${this.config.title}</h1>
                <p>${this.config.subtitle}</p>
                <div class="project-info">
                    <p><strong>Project Title :</strong> ${(this.config.project && this.config.project.title) ? this.config.project.title : this.config.project}</p>
                    <p>
                        <strong>Region:</strong> ${headers.region || '___________'}
                        &nbsp;&nbsp;<strong>Zone</strong> ${headers.zone || '_________'}
                        &nbsp;&nbsp;<strong>Name of Woreda -</strong> ${headers.woreda || '_________'}
                        &nbsp;&nbsp;<strong>Report type</strong> ${headers.reportType || '_________'}
                    </p>
                </div>
            </div>
        `;
    }
};

window.VisibilityManager = {
    storageKey: 'column_visibility_v2',
    
    init(tableId, columns = []) {
        const table = document.getElementById(tableId);
        if (!table) return;
        
        this.loadVisibility(tableId);
        
        if (columns.length > 0) {
            this.createVisibilityControls(tableId, columns);
        }
        
        this.createRowFilters(tableId);
    },
    
    createVisibilityControls(tableId, columns) {
        const table = document.getElementById(tableId);
        const container = table.parentNode;
        
        let controls = container.querySelector('.visibility-controls');
        if (!controls) {
            controls = document.createElement('div');
            controls.className = 'visibility-controls';
            controls.innerHTML = `
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px;">
                    <strong style="color: #4361ee;">📊 Column Visibility Controls</strong>
                    <div>
                        <button type="button" class="btn-visibility-toggle" onclick="VisibilityManager.toggleAllColumns('${tableId}', true)" style="background: #4cc9f0; color: white; border: none; padding: 4px 8px; border-radius: 4px; font-size: 10px; margin-right: 4px;">Show All</button>
                        <button type="button" class="btn-visibility-toggle" onclick="VisibilityManager.toggleAllColumns('${tableId}', false)" style="background: #6c757d; color: white; border: none; padding: 4px 8px; border-radius: 4px; font-size: 10px;">Hide All</button>
                    </div>
                </div>
                <div class="visibility-options"></div>
            `;
            container.insertBefore(controls, table);
        }
        
        const optionsContainer = controls.querySelector('.visibility-options');
        optionsContainer.innerHTML = '';
        
        columns.forEach((column, index) => {
            const columnId = column.id || index;
            const columnName = column.name || columnId;
            const columnType = column.type || 'text';
            
            const isVisible = this.isColumnVisible(tableId, columnId);
            const icon = this.getColumnIcon(columnType);
            
            const option = document.createElement('label');
            option.className = 'visibility-option';
            option.innerHTML = `
                <input type="checkbox" data-column="${columnId}" ${isVisible ? 'checked' : ''} 
                       onchange="VisibilityManager.toggleColumn('${tableId}', '${columnId}', this.checked)">
                <span style="color: #666;">${icon}</span>
                ${columnName}
            `;
            
            optionsContainer.appendChild(option);
        });
    },
    
    createRowFilters(tableId) {
        // placeholder for future row filters
    },
    
    toggleColumn(tableId, columnId, visible) {
        const table = document.getElementById(tableId);
        if (!table) return;
        
        const headers = table.querySelectorAll('thead th');
        headers.forEach((header, index) => {
            if (header.getAttribute('data-column') === columnId || index === parseInt(columnId)) {
                header.classList.toggle('hidden-column', !visible);
            }
        });
        
        const rows = table.querySelectorAll('tbody tr');
        rows.forEach(row => {
            const cells = row.querySelectorAll('td');
            cells.forEach((cell, index) => {
                if (index === parseInt(columnId)) {
                    cell.classList.toggle('hidden-column', !visible);
                }
            });
        });
        
        this.saveVisibility(tableId);
        CrossApp.setData(`visibility_${tableId}_${columnId}`, visible, 'ui_settings');
    },
    
    toggleAllColumns(tableId, visible) {
        const table = document.getElementById(tableId);
        if (!table) return;
        
        const headers = table.querySelectorAll('thead th');
        headers.forEach((header) => {
            header.classList.toggle('hidden-column', !visible);
        });
        
        const rows = table.querySelectorAll('tbody tr');
        rows.forEach(row => {
            const cells = row.querySelectorAll('td');
            cells.forEach((cell) => {
                cell.classList.toggle('hidden-column', !visible);
            });
        });
        
        this.saveVisibility(tableId);
    },
    
    isColumnVisible(tableId, columnId) {
        const settings = this.getAllSettings();
        const tableSettings = settings[tableId] || {};
        return tableSettings[columnId] !== false;
    },
    
    saveVisibility(tableId) {
        const table = document.getElementById(tableId);
        const headers = table.querySelectorAll('thead th');
        const visibility = {};
        
        headers.forEach((header, index) => {
            const columnId = header.getAttribute('data-column') || index;
            visibility[columnId] = !header.classList.contains('hidden-column');
        });
        
        const allSettings = this.getAllSettings();
        allSettings[tableId] = visibility;
        localStorage.setItem(this.storageKey, JSON.stringify(allSettings));
    },
    
    loadVisibility(tableId) {
        const allSettings = this.getAllSettings();
        const visibility = allSettings[tableId];
        
        if (!visibility) return;
        
        Object.keys(visibility).forEach(columnId => {
            if (!visibility[columnId]) {
                this.toggleColumn(tableId, columnId, false);
            }
        });
    },
    
    getAllSettings() {
        return JSON.parse(localStorage.getItem(this.storageKey) || '{}');
    },
    
    getColumnIcon(type) {
        const icons = {
            'text': '📝',
            'number': '🔢',
            'date': '📅',
            'email': '📧',
            'phone': '📞',
            'money': '💰',
            'percentage': '📊',
            'boolean': '✅',
            'user': '👤',
            'location': '📍',
            'action': '⚡'
        };
        
        return icons[type] || '📋';
    }
};

window.ActionManager = {
    createButton(text, type = 'primary', icon = null, onClick = null, id = null, extraClasses = '') {
        const button = document.createElement('button');
        button.className = `btn btn-${type} ${extraClasses}`;
        button.textContent = text;
        
        if (icon) {
            const iconElem = document.createElement('i');
            iconElem.className = `fas fa-${icon}`;
            button.prepend(iconElem);
            button.innerHTML += ' ' + text;
        }
        
        if (onClick) {
            button.addEventListener('click', onClick);
        }
        
        if (id) {
            button.id = id;
        }
        
        return button;
    },
    
    createActionBar(containerId, actions = []) {
        const container = document.getElementById(containerId);
        if (!container) return;
        
        const actionBar = document.createElement('div');
        actionBar.className = 'action-buttons';
        
        actions.forEach(action => {
            if (action === 'separator') {
                const separator = document.createElement('div');
                separator.style.cssText = 'width: 1px; background: #dee2e6; height: 26px; margin: 0 4px;';
                actionBar.appendChild(separator);
                return;
            }
            
            const button = this.createButton(
                action.text,
                action.type || 'primary',
                action.icon,
                action.onClick,
                action.id,
                action.classes || ''
            );
            
            if (action.tooltip) {
                button.title = action.tooltip;
            }
            
            actionBar.appendChild(button);
        });
        
        container.appendChild(actionBar);
    },
    
    handleEditAction(button) {
        const id = button.getAttribute('data-id');
        const type = button.getAttribute('data-type');
        
        CrossApp.sendMessage('action_manager', 'current_app', 'edit_action', {
            id: id,
            type: type,
            timestamp: new Date().toISOString()
        });
        
        this.showEditForm(id, type);
    },
    
    handleDeleteAction(button) {
        const id = button.getAttribute('data-id');
        const type = button.getAttribute('data-type');
        
        if (confirm('Are you sure you want to delete this item?')) {
            CrossApp.sendMessage('action_manager', 'current_app', 'delete_action', {
                id: id,
                type: type,
                timestamp: new Date().toISOString()
            });
            
            this.performDelete(id, type);
        }
    },
    
    handlePrintAction() {
        CrossApp.sendMessage('action_manager', 'printing', 'print_request', {
            timestamp: new Date().toISOString()
        });
        
        window.PrintExport.print();
    },
    
    handleExportAction() {
        const tableId = prompt('Enter table ID to export:', 'dataTable');
        if (tableId) {
            CrossApp.sendMessage('action_manager', 'export', 'export_request', {
                tableId: tableId,
                format: 'excel',
                timestamp: new Date().toISOString()
            });
            
            window.PrintExport.exportToExcel(tableId, `export_${new Date().toISOString().split('T')[0]}.xlsx`);
        }
    },
    
    handleShareAction() {
        const shareData = {
            title: document.title,
            text: 'Check out this report from SMART Nexus',
            url: window.location.href
        };
        
        if (navigator.share) {
            navigator.share(shareData);
        } else {
            navigator.clipboard.writeText(window.location.href);
            alert('Link copied to clipboard!');
        }
        
        CrossApp.sendMessage('action_manager', 'sharing', 'share_action', {
            url: window.location.href,
            timestamp: new Date().toISOString()
        });
    },
    
    showEditForm(id, type) {
        console.log('Edit form for:', type, id);
    },
    
    performDelete(id, type) {
        console.log('Delete:', type, id);
    }
};

window.GeoAPI = {
    cache: new Map(),
    
    async getRegions() {
        const cacheKey = 'regions';
        if (this.cache.has(cacheKey)) {
            return this.cache.get(cacheKey);
        }
        
        try {
            const resp = await fetch(window.APP_BASE_URL + '/api/geography.php?action=get_regions');
            if (!resp.ok) throw new Error('Failed to load regions');
            const data = await resp.json();
            this.cache.set(cacheKey, data);
            return data;
        } catch (e) {
            console.error('GeoAPI.getRegions error:', e);
            return [];
        }
    },
    
    async getZones(regionId) {
        if (!regionId) return [];
        const cacheKey = `zones_${regionId}`;
        if (this.cache.has(cacheKey)) {
            return this.cache.get(cacheKey);
        }
        
        try {
            const resp = await fetch(window.APP_BASE_URL + '/api/geography.php?action=get_zones&region_id=' + encodeURIComponent(regionId));
            if (!resp.ok) throw new Error('Failed to load zones');
            const data = await resp.json();
            this.cache.set(cacheKey, data);
            return data;
        } catch (e) {
            console.error('GeoAPI.getZones error:', e);
            return [];
        }
    },
    
    async getWoredas(zoneId) {
        if (!zoneId) return [];
        const cacheKey = `woredas_${zoneId}`;
        if (this.cache.has(cacheKey)) {
            return this.cache.get(cacheKey);
        }
        
        try {
            const resp = await fetch(window.APP_BASE_URL + '/api/geography.php?action=get_woredas&zone_id=' + encodeURIComponent(zoneId));
            if (!resp.ok) throw new Error('Failed to load woredas');
            const data = await resp.json();
            this.cache.set(cacheKey, data);
            return data;
        } catch (e) {
            console.error('GeoAPI.getWoredas error:', e);
            return [];
        }
    },
    
    clearCache() {
        this.cache.clear();
    }
};

function clearSelectOptions(select, placeholder) {
    if (!select) return;
    select.innerHTML = '';
    const opt = document.createElement('option');
    opt.value = '';
    opt.textContent = placeholder || 'Select...';
    select.appendChild(opt);
}

function populateSelectOptions(select, items, valueKey, labelKey, placeholder) {
    clearSelectOptions(select, placeholder);
    if (!Array.isArray(items)) return;
    items.forEach(item => {
        const opt = document.createElement('option');
        opt.value = item[valueKey];
        opt.textContent = item[labelKey];
        select.appendChild(opt);
    });
}

// ✅ UPGRADED: supports more IDs/names (region_id/zone_id/woreda_id etc.) without breaking old behaviour
function initGeographyDropdowns() {
    const regionSelects = document.querySelectorAll(
        'select.geo-region, ' +
        'select.region-select, ' +
        'select#region_id, ' +
        'select#filter_region_id, ' +
        'select#region, ' +
        'select[name="region_id"], ' +
        'select[name="filter_region_id"]'
    );

    const zoneSelects   = document.querySelectorAll(
        'select.geo-zone, ' +
        'select.zone-select, ' +
        'select#zone_id, ' +
        'select#filter_zone_id, ' +
        'select#zone, ' +
        'select[name="zone_id"], ' +
        'select[name="filter_zone_id"]'
    );

    const woredaSelects = document.querySelectorAll(
        'select.geo-woreda, ' +
        'select.woreda-select, ' +
        'select#woreda_id, ' +
        'select#filter_woreda_id, ' +
        'select#woreda, ' +
        'select[name="woreda_id"], ' +
        'select[name="woreda_ids[]"], ' +
        'select[name="filter_woreda_id"]'
    );

    if (!regionSelects.length && !zoneSelects.length && !woredaSelects.length) {
        return;
    }

    const groups = new Map();
    const addToGroup = (el, type) => {
        const group = el.dataset.geoGroup || 'default';
        if (!groups.has(group)) groups.set(group, { regions: [], zones: [], woredas: [] });
        groups.get(group)[type].push(el);
    };

    regionSelects.forEach(el => addToGroup(el, 'regions'));
    zoneSelects.forEach(el => addToGroup(el, 'zones'));
    woredaSelects.forEach(el => addToGroup(el, 'woredas'));

    groups.forEach(async (controls, groupKey) => {
        let regions = await window.GeoAPI.getRegions();
        controls.regions.forEach(sel => {
            populateSelectOptions(
                sel,
                regions,
                sel.dataset.valueKey || 'id',
                sel.dataset.labelKey || 'name',
                sel.dataset.placeholder || 'Select Region'
            );
        });

        controls.regions.forEach(sel => {
            sel.addEventListener('change', async function () {
                const regionId = this.value;
                controls.zones.forEach(zSel => clearSelectOptions(zSel, zSel.dataset.placeholder || 'Select Zone'));
                controls.woredas.forEach(wSel => clearSelectOptions(wSel, wSel.dataset.placeholder || 'Select Woreda'));
                
                if (!regionId) {
                    document.dispatchEvent(new CustomEvent('geoSelectionChanged', {
                        detail: { group: groupKey, regionId: null, zoneId: null, woredaId: null }
                    }));
                    return;
                }

                const zones = await window.GeoAPI.getZones(regionId);
                controls.zones.forEach(zSel => {
                    populateSelectOptions(
                        zSel,
                        zones,
                        zSel.dataset.valueKey || 'id',
                        zSel.dataset.labelKey || 'name',
                        zSel.dataset.placeholder || 'Select Zone'
                    );
                });

                document.dispatchEvent(new CustomEvent('geoSelectionChanged', {
                    detail: { group: groupKey, regionId: regionId, zoneId: null, woredaId: null }
                }));
            });

            if (sel.value) {
                sel.dispatchEvent(new Event('change'));
            }
        });

        controls.zones.forEach(sel => {
            sel.addEventListener('change', async function () {
                const zoneId = this.value;
                controls.woredas.forEach(wSel => clearSelectOptions(wSel, wSel.dataset.placeholder || 'Select Woreda'));

                if (!zoneId) {
                    document.dispatchEvent(new CustomEvent('geoSelectionChanged', {
                        detail: { group: groupKey, regionId: null, zoneId: null, woredaId: null }
                    }));
                    return;
                }

                const woredas = await window.GeoAPI.getWoredas(zoneId);
                controls.woredas.forEach(wSel => {
                    populateSelectOptions(
                        wSel,
                        woredas,
                        wSel.dataset.valueKey || 'id',
                        wSel.dataset.labelKey || 'name',
                        wSel.dataset.placeholder || 'Select Woreda'
                    );
                });

                document.dispatchEvent(new CustomEvent('geoSelectionChanged', {
                    detail: { group: groupKey, regionId: null, zoneId: zoneId, woredaId: null }
                }));
            });

            if (sel.value) {
                sel.dispatchEvent(new Event('change'));
            }
        });

        controls.woredas.forEach(sel => {
            sel.addEventListener('change', function () {
                document.dispatchEvent(new CustomEvent('geoSelectionChanged', {
                    detail: { group: groupKey, regionId: null, zoneId: null, woredaId: this.value || null }
                }));
            });

            if (sel.value) {
                sel.dispatchEvent(new Event('change'));
            }
        });
    });
}

function scrollToApps() {
    const appsNav = document.getElementById('appsNav');
    if (appsNav) {
        appsNav.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
}

const appSearchInput = document.getElementById('appSearch') || document.getElementById('app-search') || document.querySelector('[data-app-search]');
if (appSearchInput) {
    appSearchInput.addEventListener('input', function(e) {
        const searchTerm = e.target.value.toLowerCase();
        const appCards = document.querySelectorAll('.app-card');
        
        appCards.forEach(card => {
            const title = (card.querySelector('.app-title')?.textContent || '').toLowerCase();
            const desc  = (card.querySelector('.app-desc')?.textContent || '').toLowerCase();
            const group = (card.querySelector('.app-group-label')?.textContent || '').toLowerCase();
            const appKey = card.getAttribute('data-app') || '';
            
            if (title.includes(searchTerm) || desc.includes(searchTerm) || group.includes(searchTerm) || appKey.includes(searchTerm)) {
                card.style.display = 'block';
                card.style.animation = 'bounceIn 0.5s ease-out';
            } else {
                card.style.display = 'none';
            }
        });
    });
}

function refreshProfilePhoto() {
    const userPhotos = document.querySelectorAll('.user-photo');
    userPhotos.forEach(photo => {
        if (photo.src) {
            const newSrc = photo.src.split('?')[0] + '?t=' + new Date().getTime();
            photo.src = newSrc;
        }
    });
}

function refreshCrossAppData() {
    CrossApp.updateDisplay();
    
    const buttons = document.querySelectorAll('.cross-app-btn');
    if (buttons.length) {
        const refreshBtn = buttons[0];
        const originalHtml = refreshBtn.innerHTML;
        refreshBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Refreshing...';
        setTimeout(() => {
            refreshBtn.innerHTML = originalHtml;
        }, 800);
    }
}

function syncAllApps() {
    CrossApp.sendMessage('header', 'all_apps', 'sync_request', {
        timestamp: new Date().toISOString()
    });
    
    const buttons = document.querySelectorAll('.cross-app-btn');
    if (buttons.length > 1) {
        const syncBtn = buttons[1];
        const originalHtml = syncBtn.innerHTML;
        syncBtn.innerHTML = '<i class="fas fa-sync-alt fa-spin"></i> Syncing...';
        setTimeout(() => {
            syncBtn.innerHTML = originalHtml;
            alert('All apps synchronized successfully!');
        }, 1800);
    }
}

function toggleUserMenu() {
    console.log('User menu toggled');
}

document.addEventListener('DOMContentLoaded', function() {
    const appsGrid = document.getElementById('appsGrid');
    
    if (appsGrid) {
        new Sortable(appsGrid, {
            animation: 150,
            ghostClass: 'sortable-ghost',
            chosenClass: 'sortable-chosen',
            dragClass: 'sortable-drag',
            onEnd: function(evt) {
                const newOrder = Array.from(appsGrid.children)
                    .filter(card => card.classList.contains('app-card'))
                    .map(card => card.getAttribute('data-app'));
                
                fetch('<?php echo $BASE_URL; ?>/api/update_app_order.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({ app_order: newOrder })
                }).then(response => {
                    if (response.ok) {
                        CrossApp.sendMessage('header', 'all_apps', 'app_order_updated', {
                            newOrder: newOrder,
                            timestamp: new Date().toISOString()
                        });
                    }
                });
            }
        });
    }

    const messages = <?php echo json_encode($dynamicMessages); ?>;
    let currentMessageIndex = 0;
    const subtitle = document.querySelector('.apps-subtitle');

    if (subtitle) {
        setInterval(() => {
            currentMessageIndex = (currentMessageIndex + 1) % messages.length;
            subtitle.style.opacity = '0.7';
            
            setTimeout(() => {
                subtitle.textContent = messages[currentMessageIndex];
                subtitle.style.opacity = '1';
                
                CrossApp.setData('current_message', messages[currentMessageIndex], 'system');
            }, 400);
        }, 8000);
    }

    const userPhotos = document.querySelectorAll('.user-photo, .welcome-avatar[src]');
    userPhotos.forEach(photo => {
        photo.addEventListener('error', function() {
            console.error('Failed to load profile photo:', this.src);
            CrossApp.sendMessage('header', 'profile_manager', 'photo_load_error', {
                photoSrc: this.src,
                timestamp: new Date().toISOString()
            });
        });
    });

    initGeographyDropdowns();
    
    CrossApp.updateDisplay();
    CrossApp.updateNotificationBadge();
    
    const currentProject = CrossApp.getData('current_project', 'projects');
    if (currentProject) {
        const panel = document.getElementById('crossAppPanel');
        if (panel) {
            panel.style.display = 'flex';
        }
    }
    
    const currentApp = CrossApp.getData('current_app', 'navigation', 'Dashboard');
    window.PrintExport.setupPrint(
        `${currentApp} Report`,
        `Generated on ${new Date().toLocaleString()}`,
        currentProject,
        window.PrintExport.config.customHeaders
    );
    window.PrintExport.autoDetectTitle();
});

document.addEventListener('profilePhotoUpdated', function(e) {
    const newPhotoUrl = e.detail.photoUrl;
    const userPhotos = document.querySelectorAll('.user-photo, .welcome-avatar[src]');
    
    userPhotos.forEach(photo => {
        photo.src = newPhotoUrl + '?t=' + new Date().getTime();
    });
    
    CrossApp.sendMessage('header', 'all_apps', 'profile_photo_updated', {
        photoUrl: newPhotoUrl,
        timestamp: new Date().toISOString()
    });
});

document.addEventListener('projectChanged', function(e) {
    const { projectId, projectData } = e.detail;
    
    CrossApp.updateDisplay();
    
    window.PrintExport.setupPrint(
        window.PrintExport.config.title,
        window.PrintExport.config.subtitle,
        projectData,
        window.PrintExport.config.customHeaders
    );
});

setInterval(refreshProfilePhoto, 300000);
setInterval(refreshCrossAppData, 60000);

window.addEventListener('error', function(e) {
    CrossApp.sendMessage('header', 'error_monitor', 'client_error', {
        message: e.message,
        filename: e.filename,
        lineno: e.lineno,
        colno: e.colno,
        timestamp: new Date().toISOString()
    });
});

window.addEventListener('beforeunload', function() {
    CrossApp.sendMessage('header', 'session_manager', 'user_leaving', {
        timestamp: new Date().toISOString(),
        url: window.location.href
    });
});
</script>

<?php
else:
    // If we are in a download/export/print context, discard buffered layout HTML
    ob_end_clean();
endif;
?>
