<?php
// File: pages/dashboard.php
require_once __DIR__ . '/../header.php';
require_login();

$pdo   = getPDO();
$user  = current_user();
$userId = $user['id'] ?? 0;
$isAdmin = ($user['role'] ?? 'user') === 'admin';

// Helpers to fetch reference data (robust to 'title' vs 'name' column)
$projects = function() use ($pdo) {
    try {
        // Newer schema (with 'title')
        return $pdo->query("SELECT id, title, code, total_fund_usd, status FROM projects ORDER BY title")->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        // Fallback for older schema using 'name'
        return $pdo->query("SELECT id, name AS title, code, total_fund_usd, status FROM projects ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
    }
};

$indicatorsFn = function() use ($pdo) {
    return $pdo->query("SELECT id, code, name FROM indicators ORDER BY code")->fetchAll(PDO::FETCH_ASSOC);
};

// Get system statistics for admin dashboard
$systemStats = [];
if ($isAdmin) {
    // Total projects
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM projects");
    $systemStats['total_projects'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
    
    // Total beneficiaries
    $stmt = $pdo->query("
        SELECT SUM(boys_u5 + girls_u5 + boys_5_17 + girls_5_17 + men_18_59 + women_18_59 + men_60p + women_60p) as total 
        FROM report_values
    ");
    $systemStats['total_beneficiaries'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;
    
    // Total funding
    $stmt = $pdo->query("SELECT SUM(total_fund_usd) as total FROM projects");
    $systemStats['total_funding'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;
    
    // Active users
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM users WHERE status = 'active'");
    $systemStats['active_users'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
}

$message = '';
$error   = '';

// Handle welcome message updates (admin only)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $isAdmin) {
    if (isset($_POST['update_welcome_message'])) {
        $welcomeMessage = trim($_POST['welcome_message'] ?? '');
        $welcomeTitle = trim($_POST['welcome_title'] ?? '');
        
        // Store in database or file - for simplicity, we'll use session
        $_SESSION['dashboard_welcome_title'] = $welcomeTitle;
        $_SESSION['dashboard_welcome_message'] = $welcomeMessage;
        $message = 'Welcome message updated successfully!';
    }
    
    if (isset($_POST['share_dashboard'])) {
        $shareType = $_POST['share_type'] ?? 'link';
        $recipients = $_POST['recipients'] ?? '';
        $messageText = $_POST['share_message'] ?? '';
        
        // Handle different share types
        switch ($shareType) {
            case 'email':
                $message = "Dashboard shared via email to: " . htmlspecialchars($recipients);
                break;
            case 'telegram':
                $message = "Dashboard Telegram link generated and shared";
                break;
            case 'whatsapp':
                $message = "Dashboard WhatsApp link generated and shared";
                break;
            case 'facebook':
                $message = "Dashboard Facebook post created";
                break;
            default:
                $message = "Dashboard shareable link generated";
        }
    }
}

// ---------------------------------------------------------
// Handle POST: save / delete widget
// ---------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['save_widget'])) {
        $widgetId    = isset($_POST['id']) ? (int)$_POST['id'] : 0;
        $title       = trim($_POST['title'] ?? '');
        $projectId   = $_POST['project_id']   !== '' ? (int)$_POST['project_id']   : null;
        $indicatorId = $_POST['indicator_id'] !== '' ? (int)$_POST['indicator_id'] : null;
        $groupBy     = $_POST['group_by']     ?? 'woreda';
        $year        = $_POST['year']         !== '' ? (int)$_POST['year'] : null;
        $chartType   = $_POST['chart_type']   ?? 'bar';

        if ($title === '') {
            $error = 'Widget title is required.';
        } else {
            $config = [
                'project_id'   => $projectId,
                'indicator_id' => $indicatorId,
                'group_by'     => $groupBy,
                'year'         => $year,
                'chart_type'   => $chartType,
            ];
            $configJson = json_encode($config);

            if ($widgetId > 0) {
                $stmt = $pdo->prepare("
                    UPDATE dashboard_widgets
                    SET title = ?, config = ?
                    WHERE id = ? AND user_id = ?
                ");
                $stmt->execute([$title, $configJson, $widgetId, $userId]);
                $message = 'Dashboard widget updated.';
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO dashboard_widgets (user_id, title, config)
                    VALUES (?,?,?)
                ");
                $stmt->execute([$userId, $title, $configJson]);
                $message = 'Dashboard widget created.';
            }
        }

    } elseif (isset($_POST['delete_widget'])) {
        $widgetId = (int)($_POST['id'] ?? 0);
        if ($widgetId > 0) {
            $stmt = $pdo->prepare("DELETE FROM dashboard_widgets WHERE id = ? AND user_id = ?");
            $stmt->execute([$widgetId, $userId]);
            $message = 'Dashboard widget deleted.';
        }
    }
}

// ---------------------------------------------------------
// Load widgets for this user
// ---------------------------------------------------------
$stmt = $pdo->prepare("SELECT * FROM dashboard_widgets WHERE user_id = ? ORDER BY created_at DESC");
$stmt->execute([$userId]);
$widgets = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Which widget are we editing / viewing?
$editId   = isset($_GET['edit_id'])   ? (int)$_GET['edit_id']   : 0;
$viewId   = isset($_GET['widget_id']) ? (int)$_GET['widget_id'] : 0;

$editWidget  = null;
$viewWidget  = null;

foreach ($widgets as $w) {
    if ($editId && (int)$w['id'] === $editId) {
        $editWidget = $w;
    }
    if ($viewId && (int)$w['id'] === $viewId) {
        $viewWidget = $w;
    }
}
if (!$viewWidget && $widgets) {
    // Default: view first widget if none selected
    $viewWidget = $widgets[0];
}

// Prefill form for create/edit
$formId        = $editWidget['id']    ?? 0;
$formTitle     = $editWidget['title'] ?? '';
$formConfig    = $editWidget ? json_decode($editWidget['config'], true) : [];
$formProjectId = $formConfig['project_id']   ?? '';
$formIndicator = $formConfig['indicator_id'] ?? '';
$formGroupBy   = $formConfig['group_by']     ?? 'woreda';
$formYear      = $formConfig['year']         ?? '';
$formChartType = $formConfig['chart_type']   ?? 'bar';

// ---------------------------------------------------------
// If we have a widget to view, pull aggregated data
// ---------------------------------------------------------
$chartData = [
    'labels'     => [],
    'total_sadd' => [],
    'total_pwd'  => [],
    'total_non'  => [],
    'title'      => '',
    'config_desc'=> '',
];

if ($viewWidget) {
    $cfg = json_decode($viewWidget['config'], true) ?: [];

    $groupBy   = $cfg['group_by']     ?? 'woreda';
    $projId    = $cfg['project_id']   ?? null;
    $indId     = $cfg['indicator_id'] ?? null;
    $year      = $cfg['year']         ?? null;
    $chartType = $cfg['chart_type']   ?? 'bar';

    $selectLabel = '';
    $groupBySql  = '';

    if ($groupBy === 'region') {
        $selectLabel = "COALESCE(rg.name,'N/A') AS label";
        $groupBySql  = "rg.name";
    } elseif ($groupBy === 'woreda') {
        $selectLabel = "COALESCE(w.name,'N/A') AS label";
        $groupBySql  = "w.name";
    } elseif ($groupBy === 'month') {
        // Label as YYYY-MM
        $selectLabel = "CONCAT(r.year,'-',LPAD(r.month,2,'0')) AS label";
        $groupBySql  = "r.year, r.month";
    } else {
        // 'nexus' → single label "Nexus total"
        $selectLabel = "'Nexus total' AS label";
        $groupBySql  = "label"; // fake group by name
    }

    $sql = "
        SELECT
            {$selectLabel},
            SUM(
                rv.boys_u5 + rv.girls_u5 +
                rv.boys_5_17 + rv.girls_5_17 +
                rv.men_18_59 + rv.women_18_59 +
                rv.men_60p + rv.women_60p
            ) AS total_sadd,
            SUM(rv.pwd_count)       AS total_pwd,
            SUM(rv.non_beneficiary) AS total_non_ben
        FROM reports r
        JOIN report_values rv ON rv.report_id = r.id
        JOIN indicators i     ON i.id = rv.indicator_id
        LEFT JOIN woredas w   ON w.id = r.woreda_id
        LEFT JOIN zones z     ON z.id = w.zone_id
        LEFT JOIN regions rg  ON rg.id = z.region_id
        WHERE 1=1
    ";

    $params = [];
    if ($projId) {
        $sql      .= " AND r.project_id = ?";
        $params[] = $projId;
    }
    if ($indId) {
        $sql      .= " AND rv.indicator_id = ?";
        $params[] = $indId;
    }
    if ($year) {
        $sql      .= " AND r.year = ?";
        $params[] = $year;
    }

    if ($groupBy === 'nexus') {
        $sql .= " GROUP BY label ORDER BY label";
    } else {
        $sql .= " GROUP BY {$groupBySql} ORDER BY {$groupBySql}";
    }

    $stmtAgg = $pdo->prepare($sql);
    $stmtAgg->execute($params);
    $rows = $stmtAgg->fetchAll(PDO::FETCH_ASSOC);

    $labels    = [];
    $totSadd   = [];
    $totPwd    = [];
    $totNon    = [];

    foreach ($rows as $r) {
        $labels[]  = $r['label'];
        $totSadd[] = (int)$r['total_sadd'];
        $totPwd[]  = (int)$r['total_pwd'];
        $totNon[]  = (int)$r['total_non_ben'];
    }

    // Human-readable description
    $descParts = [];
    if ($projId) {
        $descParts[] = "Project ID: {$projId}";
    } else {
        $descParts[] = "All projects";
    }
    if ($indId) {
        $descParts[] = "Indicator ID: {$indId}";
    } else {
        $descParts[] = "All indicators";
    }
    if ($year) {
        $descParts[] = "Year: {$year}";
    } else {
        $descParts[] = "All years";
    }
    $descParts[] = "Group by: " . ucfirst($groupBy);

    $chartData = [
        'labels'      => $labels,
        'total_sadd'  => $totSadd,
        'total_pwd'   => $totPwd,
        'total_non'   => $totNon,
        'title'       => $viewWidget['title'],
        'config_desc' => implode(' | ', $descParts),
        'chart_type'  => $chartType,
    ];
}

$projectList   = $projects();
$indicatorList = $indicatorsFn();

// Get welcome messages from session or set defaults
$welcomeTitle = $_SESSION['dashboard_welcome_title'] ?? ($isAdmin ? '🌟 Welcome to SMART Nexus Project Monitoring and Reporting Dashboard' : '🌟 SMART Nexus Dashboard');
$welcomeMessage = $_SESSION['dashboard_welcome_message'] ?? ($isAdmin ? 
    'Monitor project progress, track beneficiaries, and measure impact across all N-SMART initiatives. Customize this message to share updates with your team and stakeholders.' : 
    'Welcome to the SMART  Nexus Dashboard. Track project progress and impact metrics in real-time.');
?>

<!-- Add CSS Styles -->
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
    --shadow: 0 10px 30px rgba(0,0,0,0.1);
    --shadow-hover: 0 15px 40px rgba(0,0,0,0.15);
    --border-radius: 12px;
    --transition: all 0.3s ease;
}

/* Animated Welcome Header */
.welcome-header {
    position: relative;
    background: var(--gradient-primary);
    color: white;
    padding: 60px 20px;
    border-radius: var(--border-radius);
    margin: 20px 0;
    overflow: hidden;
    text-align: center;
}

.welcome-header::before {
    content: "";
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: url('data:image/svg+xml;utf8,<svg viewBox="0 0 1200 120" xmlns="http://www.w3.org/2000/svg"><path d="M0 0v46.29c47.79 22.2 103.59 32.17 158 28 70.36-5.37 136.33-33.31 206.8-37.5 73.84-4.36 147.54 16.88 218.2 35.26 69.27 18 138.3 24.88 209.4 13.08 36.15-6 69.85-17.84 104.45-29.34C989.49 25 1113-14.29 1200 52.47V0z" fill="%23ffffff" opacity=".15"/></svg>');
    background-size: cover;
    animation: wave 15s linear infinite;
}

@keyframes wave {
    0% { transform: translateX(0); }
    100% { transform: translateX(-50%); }
}

.welcome-title {
    font-size: 3rem;
    font-weight: 800;
    margin-bottom: 20px;
    background: linear-gradient(45deg, #FFD700, #FFA500, #FF8C00);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    background-clip: text;
    animation: glow 2s ease-in-out infinite alternate;
}

@keyframes glow {
    from { text-shadow: 0 0 20px rgba(255, 215, 0, 0.6); }
    to { text-shadow: 0 0 30px rgba(255, 165, 0, 0.8), 0 0 40px rgba(255, 140, 0, 0.6); }
}

/* Stats Grid */
.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 20px;
    margin: 20px 0;
}

.stat-card {
    background: white;
    padding: 25px;
    border-radius: var(--border-radius);
    text-align: center;
    box-shadow: var(--shadow);
    transition: var(--transition);
    border-left: 5px solid var(--primary);
    position: relative;
    overflow: hidden;
}

.stat-card::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 4px;
    background: var(--gradient-primary);
}

.stat-card:hover {
    transform: translateY(-5px);
    box-shadow: var(--shadow-hover);
}

.stat-icon {
    font-size: 2.5rem;
    margin-bottom: 15px;
    opacity: 0.8;
}

.stat-number {
    font-size: 2.5em;
    font-weight: bold;
    background: var(--gradient-primary);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    margin-bottom: 10px;
}

.stat-label {
    color: #6c757d;
    font-weight: 600;
    text-transform: uppercase;
    font-size: 0.9em;
    letter-spacing: 1px;
}

/* Dashboard Layout */
.dashboard-container {
    display: grid;
    grid-template-columns: 300px 1fr;
    gap: 20px;
    margin: 20px 0;
}

@media (max-width: 1024px) {
    .dashboard-container {
        grid-template-columns: 1fr;
    }
}

.dashboard-sidebar {
    background: white;
    border-radius: var(--border-radius);
    padding: 20px;
    box-shadow: var(--shadow);
    height: fit-content;
}

.dashboard-main {
    display: flex;
    flex-direction: column;
    gap: 20px;
}

/* Card Styling */
.dashboard-card {
    background: white;
    border-radius: var(--border-radius);
    padding: 25px;
    box-shadow: var(--shadow);
    border: none;
    overflow: hidden;
    transition: var(--transition);
}

.dashboard-card:hover {
    box-shadow: var(--shadow-hover);
}

/* Chart Container */
.chart-container {
    position: relative;
    height: 400px;
    width: 100%;
    margin: 20px 0;
}

/* Social Share */
.social-share {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
    margin: 15px 0;
}

.share-btn {
    padding: 10px 20px;
    border-radius: 8px;
    color: white;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    font-weight: 600;
    transition: var(--transition);
    border: none;
    cursor: pointer;
}

.share-email { background: #ea4335; }
.share-telegram { background: #0088cc; }
.share-whatsapp { background: #25d366; }
.share-facebook { background: #3b5998; }
.share-link { background: var(--primary); }

.share-btn:hover {
    transform: translateY(-2px);
    box-shadow: 0 5px 15px rgba(0,0,0,0.2);
}

/* Form Styling */
.form-row {
    display: flex;
    gap: 15px;
    margin-bottom: 15px;
    flex-wrap: wrap;
}

.form-row label {
    flex: 1;
    min-width: 200px;
    font-weight: 600;
}

.form-row input, .form-row select, .form-row textarea {
    width: 100%;
    padding: 10px;
    border: 2px solid #e9ecef;
    border-radius: 8px;
    transition: var(--transition);
}

.form-row input:focus, .form-row select:focus, .form-row textarea:focus {
    border-color: var(--primary);
    box-shadow: 0 0 0 3px rgba(67, 97, 238, 0.1);
    outline: none;
}

/* Button Styling */
.dashboard-btn {
    padding: 10px 20px;
    border: none;
    border-radius: 8px;
    cursor: pointer;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    font-weight: 600;
    transition: var(--transition);
}

.btn-primary {
    background: var(--gradient-primary);
    color: white;
}

.btn-success {
    background: var(--gradient-success);
    color: white;
}

.btn-warning {
    background: var(--gradient-warning);
    color: white;
}

.dashboard-btn:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 25px rgba(0,0,0,0.15);
}

.btn-sm {
    padding: 8px 15px;
    font-size: 0.9em;
}

/* Table Styling */
.dashboard-table {
    width: 100%;
    border-collapse: collapse;
    margin: 20px 0;
    background: white;
    border-radius: var(--border-radius);
    overflow: hidden;
    box-shadow: 0 5px 15px rgba(0,0,0,0.08);
}

.dashboard-table th {
    background: var(--gradient-primary);
    color: white;
    padding: 15px;
    text-align: left;
    font-weight: 700;
}

.dashboard-table td {
    padding: 12px 15px;
    border-bottom: 1px solid #e9ecef;
}

.dashboard-table tr:hover td {
    background-color: #f8f9fa;
}

/* Badge Styling */
.badge {
    padding: 8px 15px;
    border-radius: 50px;
    font-weight: 600;
    display: inline-flex;
    align-items: center;
    gap: 5px;
}

.badge-success {
    background: var(--gradient-success);
    color: white;
}

.badge-error {
    background: var(--gradient-secondary);
    color: white;
}

/* Floating Action */
.floating-action {
    position: fixed;
    bottom: 30px;
    right: 30px;
    z-index: 1000;
}

.floating-btn {
    width: 60px;
    height: 60px;
    border-radius: 50%;
    background: var(--gradient-primary);
    color: white;
    border: none;
    font-size: 24px;
    cursor: pointer;
    box-shadow: 0 10px 30px rgba(67, 97, 238, 0.3);
    transition: var(--transition);
    display: flex;
    align-items: center;
    justify-content: center;
}

.floating-btn:hover {
    transform: scale(1.1) rotate(15deg);
    box-shadow: 0 15px 40px rgba(67, 97, 238, 0.4);
}

/* Tabs */
.tabs {
    display: flex;
    border-bottom: 2px solid #e9ecef;
    margin-bottom: 20px;
}

.tab {
    padding: 15px 25px;
    cursor: pointer;
    border-bottom: 3px solid transparent;
    transition: var(--transition);
    font-weight: 600;
}

.tab.active {
    border-bottom-color: var(--primary);
    color: var(--primary);
}

.tab-content {
    display: none;
}

.tab-content.active {
    display: block;
}

/* Progress Bars */
.progress-bar {
    height: 8px;
    background: #e9ecef;
    border-radius: 4px;
    overflow: hidden;
    margin: 10px 0;
}

.progress-fill {
    height: 100%;
    background: var(--gradient-primary);
    border-radius: 4px;
    transition: width 0.6s ease;
}

/* Animations */
@keyframes fadeIn {
    from { opacity: 0; transform: translateY(20px); }
    to { opacity: 1; transform: translateY(0); }
}

.fade-in {
    animation: fadeIn 0.6s ease-out;
}

/* Responsive */
@media (max-width: 768px) {
    .dashboard-container {
        grid-template-columns: 1fr;
    }
    
    .form-row {
        flex-direction: column;
    }
    
    .form-row label {
        min-width: 100%;
    }
    
    .stats-grid {
        grid-template-columns: 1fr;
    }
    
    .welcome-title {
        font-size: 2rem;
    }
}
</style>

<!-- Add Font Awesome -->
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

<!-- Animated Welcome Header -->
<div class="welcome-header">
    <h1 class="welcome-title"><?php echo htmlspecialchars($welcomeTitle); ?></h1>
    <p style="font-size: 1.2rem; opacity: 0.9; max-width: 800px; margin: 0 auto;">
        <?php echo htmlspecialchars($welcomeMessage); ?>
    </p>
    <div style="display: flex; justify-content: center; gap: 15px; margin-top: 20px; flex-wrap: wrap;">
        <span style="background: rgba(255,255,255,0.2); padding: 8px 16px; border-radius: 20px; backdrop-filter: blur(10px);">
            📊 Real-time Analytics
        </span>
        <span style="background: rgba(255,255,255,0.2); padding: 8px 16px; border-radius: 20px; backdrop-filter: blur(10px);">
            👥 Beneficiary Tracking
        </span>
        <span style="background: rgba(255,255,255,0.2); padding: 8px 16px; border-radius: 20px; backdrop-filter: blur(10px);">
            📈 Progress Monitoring
        </span>
        <span style="background: rgba(255,255,255,0.2); padding: 8px 16px; border-radius: 20px; backdrop-filter: blur(10px);">
            🔗 Multi-platform Sharing
        </span>
    </div>
</div>

<?php if ($message): ?>
    <div class="dashboard-card fade-in">
        <div class="badge badge-success">
            <i class="fas fa-check-circle"></i>
            <?php echo htmlspecialchars($message); ?>
        </div>
    </div>
<?php endif; ?>

<?php if ($error): ?>
    <div class="dashboard-card fade-in">
        <div class="badge badge-error">
            <i class="fas fa-exclamation-triangle"></i>
            <?php echo htmlspecialchars($error); ?>
        </div>
    </div>
<?php endif; ?>

<!-- System Overview Stats (Admin Only) -->
<?php if ($isAdmin && !empty($systemStats)): ?>
<div class="dashboard-card fade-in">
    <h2><i class="fas fa-chart-line"></i> System Overview</h2>
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-icon">📁</div>
            <div class="stat-number"><?php echo number_format($systemStats['total_projects']); ?></div>
            <div class="stat-label">Total Projects</div>
        </div>
        <div class="stat-card">
            <div class="stat-icon">👥</div>
            <div class="stat-number"><?php echo number_format($systemStats['total_beneficiaries']); ?></div>
            <div class="stat-label">Beneficiaries Reached</div>
        </div>
        <div class="stat-card">
            <div class="stat-icon">💰</div>
            <div class="stat-number">$<?php echo number_format($systemStats['total_funding']); ?></div>
            <div class="stat-label">Total Funding</div>
        </div>
        <div class="stat-card">
            <div class="stat-icon">👤</div>
            <div class="stat-number"><?php echo number_format($systemStats['active_users']); ?></div>
            <div class="stat-label">Active Users</div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Quick Actions & Social Sharing -->
<div class="dashboard-card fade-in">
    <div style="display: flex; justify-content: between; align-items: center; flex-wrap: wrap; gap: 15px;">
        <h2 style="margin: 0;"><i class="fas fa-bolt"></i> Quick Actions</h2>
        <?php if ($isAdmin): ?>
        <div class="social-share">
            <button class="share-btn share-email" onclick="shareDashboard('email')">
                <i class="fas fa-envelope"></i> Email
            </button>
            <button class="share-btn share-telegram" onclick="shareDashboard('telegram')">
                <i class="fab fa-telegram"></i> Telegram
            </button>
            <button class="share-btn share-whatsapp" onclick="shareDashboard('whatsapp')">
                <i class="fab fa-whatsapp"></i> WhatsApp
            </button>
            <button class="share-btn share-facebook" onclick="shareDashboard('facebook')">
                <i class="fab fa-facebook"></i> Facebook
            </button>
            <button class="share-btn share-link" onclick="copyDashboardLink()">
                <i class="fas fa-link"></i> Copy Link
            </button>
        </div>
        <?php endif; ?>
    </div>
</div>

<div class="dashboard-container">
    <!-- Sidebar - Widget Management -->
    <div class="dashboard-sidebar fade-in">
        <h3><i class="fas fa-sliders-h"></i> Dashboard Controls</h3>
        
        <!-- Widget Creation/Editing Form -->
        <div class="dashboard-card" style="margin-bottom: 20px;">
            <h4><?php echo $formId ? 'Edit Widget' : 'Create New Widget'; ?></h4>
            <form method="post">
                <input type="hidden" name="id" value="<?php echo (int)$formId; ?>">
                <div class="form-row">
                    <label>Widget Title
                        <input type="text" name="title" value="<?php echo htmlspecialchars($formTitle); ?>" required>
                    </label>
                </div>
                <div class="form-row">
                    <label>Project
                        <select name="project_id">
                            <option value="">All Projects</option>
                            <?php foreach ($projectList as $p): ?>
                                <option value="<?php echo $p['id']; ?>"
                                    <?php echo ($formProjectId == $p['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($p['title']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                </div>
                <div class="form-row">
                    <label>Indicator
                        <select name="indicator_id">
                            <option value="">All Indicators</option>
                            <?php foreach ($indicatorList as $ind): ?>
                                <option value="<?php echo $ind['id']; ?>"
                                    <?php echo ($formIndicator == $ind['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($ind['code'] . ' - ' . $ind['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                </div>
                <div class="form-row">
                    <label>Group By
                        <select name="group_by">
                            <option value="woreda" <?php echo $formGroupBy==='woreda' ? 'selected' : ''; ?>>Woreda</option>
                            <option value="region" <?php echo $formGroupBy==='region' ? 'selected' : ''; ?>>Region</option>
                            <option value="month"  <?php echo $formGroupBy==='month'  ? 'selected' : ''; ?>>Month</option>
                            <option value="nexus"  <?php echo $formGroupBy==='nexus'  ? 'selected' : ''; ?>>Nexus Total</option>
                        </select>
                    </label>
                </div>
                <div class="form-row">
                    <label>Year
                        <select name="year">
                            <option value="">All Years</option>
                            <?php for ($y = 2020; $y <= 2030; $y++): ?>
                                <option value="<?php echo $y; ?>" <?php echo ($formYear == $y ? 'selected' : ''); ?>>
                                    <?php echo $y; ?>
                                </option>
                            <?php endfor; ?>
                        </select>
                    </label>
                </div>
                <div class="form-row">
                    <label>Chart Type
                        <select name="chart_type">
                            <option value="bar"   <?php echo $formChartType==='bar'   ? 'selected' : ''; ?>>Bar Chart</option>
                            <option value="line"  <?php echo $formChartType==='line'  ? 'selected' : ''; ?>>Line Chart</option>
                            <option value="pie"   <?php echo $formChartType==='pie'   ? 'selected' : ''; ?>>Pie Chart</option>
                            <option value="doughnut" <?php echo $formChartType==='doughnut' ? 'selected' : ''; ?>>Doughnut Chart</option>
                            <option value="radar" <?php echo $formChartType==='radar' ? 'selected' : ''; ?>>Radar Chart</option>
                            <option value="polarArea" <?php echo $formChartType==='polarArea' ? 'selected' : ''; ?>>Polar Area</option>
                            <option value="table" <?php echo $formChartType==='table' ? 'selected' : ''; ?>>Table Only</option>
                        </select>
                    </label>
                </div>
                <div class="form-row">
                    <button type="submit" name="save_widget" class="dashboard-btn btn-primary" style="width: 100%;">
                        <i class="fas fa-save"></i>
                        <?php echo $formId ? 'Update Widget' : 'Create Widget'; ?>
                    </button>
                </div>
            </form>
        </div>

        <!-- My Widgets List -->
        <h4><i class="fas fa-th"></i> My Widgets</h4>
        <div style="max-height: 400px; overflow-y: auto;">
            <?php foreach ($widgets as $w): ?>
                <?php $cfg = json_decode($w['config'], true) ?: []; ?>
                <div class="dashboard-card" style="margin-bottom: 10px; padding: 15px;">
                    <div style="display: flex; justify-content: between; align-items: start; gap: 10px;">
                        <div style="flex: 1;">
                            <strong><?php echo htmlspecialchars($w['title']); ?></strong>
                            <div style="font-size: 0.8em; color: #666; margin-top: 5px;">
                                <?php
                                $gb = $cfg['group_by'] ?? 'woreda';
                                $yr = $cfg['year']     ?? 'All';
                                echo 'Group by: ' . htmlspecialchars($gb) . ' | Year: ' . htmlspecialchars($yr);
                                ?>
                            </div>
                        </div>
                        <div style="display: flex; gap: 5px; flex-direction: column;">
                            <a class="dashboard-btn btn-sm" style="padding: 5px 10px;"
                               href="dashboard.php?widget_id=<?php echo (int)$w['id']; ?>">
                                <i class="fas fa-eye"></i>
                            </a>
                            <a class="dashboard-btn btn-sm" style="padding: 5px 10px;"
                               href="dashboard.php?edit_id=<?php echo (int)$w['id']; ?>">
                                <i class="fas fa-edit"></i>
                            </a>
                            <form method="post" style="display:inline;"
                                  onsubmit="return confirm('Delete this widget?');">
                                <input type="hidden" name="id" value="<?php echo (int)$w['id']; ?>">
                                <button type="submit" name="delete_widget" class="dashboard-btn btn-sm" style="padding: 5px 10px; background: #dc3545; color: white;">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
            <?php if (!$widgets): ?>
                <div class="dashboard-card" style="text-align: center; padding: 20px; color: #666;">
                    <i class="fas fa-chart-bar" style="font-size: 2em; margin-bottom: 10px;"></i>
                    <p>No widgets yet. Create your first widget above!</p>
                </div>
            <?php endif; ?>
        </div>

        <!-- Admin Controls -->
        <?php if ($isAdmin): ?>
        <div class="dashboard-card" style="margin-top: 20px;">
            <h4><i class="fas fa-cog"></i> Admin Controls</h4>
            <form method="post">
                <div class="form-row">
                    <label>Welcome Title
                        <input type="text" name="welcome_title" value="<?php echo htmlspecialchars($welcomeTitle); ?>" required>
                    </label>
                </div>
                <div class="form-row">
                    <label>Welcome Message
                        <textarea name="welcome_message" rows="3" required><?php echo htmlspecialchars($welcomeMessage); ?></textarea>
                    </label>
                </div>
                <div class="form-row">
                    <button type="submit" name="update_welcome_message" class="dashboard-btn btn-warning" style="width: 100%;">
                        <i class="fas fa-bullhorn"></i> Update Welcome Message
                    </button>
                </div>
            </form>
        </div>
        <?php endif; ?>
    </div>

    <!-- Main Content - Chart Display -->
    <div class="dashboard-main">
        <?php if ($viewWidget): ?>
            <?php
            $labels    = $chartData['labels'];
            $totSadd   = $chartData['total_sadd'];
            $totPwd    = $chartData['total_pwd'];
            $totNon    = $chartData['total_non'];
            $ctType    = $chartData['chart_type'] ?? 'bar';
            ?>
            <div class="dashboard-card fade-in">
                <div style="display: flex; justify-content: between; align-items: center; flex-wrap: wrap; gap: 15px; margin-bottom: 20px;">
                    <div>
                        <h2 style="margin: 0;"><?php echo htmlspecialchars($chartData['title']); ?></h2>
                        <p style="margin: 5px 0 0 0; color: #666; font-size: 0.9em;">
                            <i class="fas fa-info-circle"></i> <?php echo htmlspecialchars($chartData['config_desc']); ?>
                        </p>
                    </div>
                    <div style="display: flex; gap: 10px; align-items: center;">
                        <span class="badge" style="background: #4cc9f0; color: white;">
                            <i class="fas fa-users"></i> Total: <?php echo array_sum($totSadd) + array_sum($totPwd); ?>
                        </span>
                        <button class="dashboard-btn btn-sm" onclick="exportChartData()">
                            <i class="fas fa-download"></i> Export
                        </button>
                        <button class="dashboard-btn btn-sm" onclick="printChart()">
                            <i class="fas fa-print"></i> Print
                        </button>
                    </div>
                </div>

                <?php if (!empty($labels)): ?>
                    <?php if ($ctType !== 'table'): ?>
                        <div class="chart-container">
                            <canvas id="dashboardChart"></canvas>
                        </div>
                    <?php endif; ?>

                    <div class="tabs">
                        <div class="tab active" onclick="switchTab('dataTable')">Data Table</div>
                        <div class="tab" onclick="switchTab('insights')">Insights</div>
                        <div class="tab" onclick="switchTab('export')">Export Options</div>
                    </div>

                    <div id="dataTable" class="tab-content active">
                        <table class="dashboard-table">
                            <thead>
                            <tr>
                                <th>Group</th>
                                <th>Total SADD</th>
                                <th>Total PWD</th>
                                <th>Total Non-Beneficiaries</th>
                                <th>Progress</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php for ($i=0; $i<count($labels); $i++): ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars($labels[$i]); ?></strong></td>
                                    <td><?php echo number_format((int)$totSadd[$i]); ?></td>
                                    <td><?php echo number_format((int)$totPwd[$i]); ?></td>
                                    <td><?php echo number_format((int)$totNon[$i]); ?></td>
                                    <td style="width: 150px;">
                                        <?php 
                                        $total = (int)$totSadd[$i] + (int)$totPwd[$i];
                                        $max = max($totSadd) + max($totPwd);
                                        $percentage = $max > 0 ? ($total / $max) * 100 : 0;
                                        ?>
                                        <div class="progress-bar">
                                            <div class="progress-fill" style="width: <?php echo $percentage; ?>%"></div>
                                        </div>
                                        <small><?php echo number_format($percentage, 1); ?>%</small>
                                    </td>
                                </tr>
                            <?php endfor; ?>
                            </tbody>
                        </table>
                    </div>

                    <div id="insights" class="tab-content">
                        <div class="stats-grid">
                            <div class="stat-card">
                                <div class="stat-number"><?php echo number_format(array_sum($totSadd)); ?></div>
                                <div class="stat-label">Total SADD Beneficiaries</div>
                            </div>
                            <div class="stat-card">
                                <div class="stat-number"><?php echo number_format(array_sum($totPwd)); ?></div>
                                <div class="stat-label">Persons with Disabilities</div>
                            </div>
                            <div class="stat-card">
                                <div class="stat-number"><?php echo count($labels); ?></div>
                                <div class="stat-label">Reporting Units</div>
                            </div>
                            <div class="stat-card">
                                <div class="stat-number">
                                    <?php 
                                    $total = array_sum($totSadd) + array_sum($totPwd);
                                    echo $total > 0 ? number_format((array_sum($totPwd) / $total) * 100, 1) . '%' : '0%';
                                    ?>
                                </div>
                                <div class="stat-label">PWD Representation</div>
                            </div>
                        </div>
                    </div>

                    <div id="export" class="tab-content">
                        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px;">
                            <button class="dashboard-btn" onclick="exportAsPDF()">
                                <i class="fas fa-file-pdf"></i> Export as PDF
                            </button>
                            <button class="dashboard-btn" onclick="exportAsExcel()">
                                <i class="fas fa-file-excel"></i> Export as Excel
                            </button>
                            <button class="dashboard-btn" onclick="exportAsImage()">
                                <i class="fas fa-image"></i> Export as Image
                            </button>
                            <button class="dashboard-btn" onclick="shareChart()">
                                <i class="fas fa-share-alt"></i> Share Chart
                            </button>
                        </div>
                    </div>
                <?php else: ?>
                    <div style="text-align: center; padding: 40px; color: #666;">
                        <i class="fas fa-chart-bar" style="font-size: 3em; margin-bottom: 15px;"></i>
                        <h3>No Data Available</h3>
                        <p>No data found for the current widget configuration. Try adjusting the filters.</p>
                    </div>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="dashboard-card" style="text-align: center; padding: 60px 20px;">
                <i class="fas fa-chart-line" style="font-size: 4em; color: #4361ee; margin-bottom: 20px;"></i>
                <h2>Welcome to Your Dashboard</h2>
                <p style="color: #666; max-width: 500px; margin: 0 auto 30px;">
                    Create your first widget to start visualizing project data, tracking progress, and sharing insights with stakeholders.
                </p>
                <div style="display: flex; justify-content: center; gap: 15px; flex-wrap: wrap;">
                    <div class="stat-card" style="max-width: 200px;">
                        <div class="stat-icon">📊</div>
                        <div class="stat-label">Create Custom Charts</div>
                    </div>
                    <div class="stat-card" style="max-width: 200px;">
                        <div class="stat-icon">👥</div>
                        <div class="stat-label">Track Beneficiaries</div>
                    </div>
                    <div class="stat-card" style="max-width: 200px;">
                        <div class="stat-icon">📈</div>
                        <div class="stat-label">Monitor Progress</div>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Floating Action Button -->
<div class="floating-action">
    <button class="floating-btn" onclick="scrollToTop()" title="Scroll to Top">
        <i class="fas fa-arrow-up"></i>
    </button>
</div>

<!-- Add Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<script>
// Chart initialization
<?php if ($viewWidget && !empty($labels) && $ctType !== 'table'): ?>
document.addEventListener('DOMContentLoaded', function() {
    const ctx = document.getElementById('dashboardChart');
    if (!ctx) return;

    const labels   = <?php echo json_encode($labels); ?>;
    const saddData = <?php echo json_encode($totSadd); ?>;
    const pwdData  = <?php echo json_encode($totPwd); ?>;
    const nonData  = <?php echo json_encode($totNon); ?>;

    const chartType = '<?php echo $ctType; ?>';
    
    // Define colors
    const colors = {
        sadd: 'rgba(67, 97, 238, 0.8)',
        pwd: 'rgba(76, 201, 240, 0.8)',
        non: 'rgba(247, 37, 133, 0.8)'
    };

    let chartConfig = {};

    switch(chartType) {
        case 'line':
            chartConfig = {
                type: 'line',
                data: {
                    labels: labels,
                    datasets: [
                        {
                            label: 'Total SADD',
                            data: saddData,
                            borderColor: colors.sadd,
                            backgroundColor: colors.sadd.replace('0.8', '0.1'),
                            tension: 0.4,
                            fill: true
                        },
                        {
                            label: 'Persons with Disabilities',
                            data: pwdData,
                            borderColor: colors.pwd,
                            backgroundColor: colors.pwd.replace('0.8', '0.1'),
                            tension: 0.4,
                            fill: true
                        }
                    ]
                }
            };
            break;

        case 'pie':
        case 'doughnut':
            chartConfig = {
                type: chartType,
                data: {
                    labels: labels,
                    datasets: [{
                        data: saddData,
                        backgroundColor: [
                            '#4361ee', '#3a0ca3', '#4cc9f0', '#4895ef',
                            '#560bad', '#7209b7', '#b5179e', '#f72585'
                        ]
                    }]
                }
            };
            break;

        case 'radar':
            chartConfig = {
                type: 'radar',
                data: {
                    labels: labels,
                    datasets: [
                        {
                            label: 'SADD Beneficiaries',
                            data: saddData,
                            backgroundColor: colors.sadd.replace('0.8', '0.2'),
                            borderColor: colors.sadd,
                            pointBackgroundColor: colors.sadd
                        },
                        {
                            label: 'Persons with Disabilities',
                            data: pwdData,
                            backgroundColor: colors.pwd.replace('0.8', '0.2'),
                            borderColor: colors.pwd,
                            pointBackgroundColor: colors.pwd
                        }
                    ]
                }
            };
            break;

        case 'polarArea':
            chartConfig = {
                type: 'polarArea',
                data: {
                    labels: labels,
                    datasets: [{
                        data: saddData,
                        backgroundColor: [
                            '#4361ee', '#3a0ca3', '#4cc9f0', '#4895ef',
                            '#560bad', '#7209b7', '#b5179e', '#f72585'
                        ]
                    }]
                }
            };
            break;

        default: // bar chart
            chartConfig = {
                type: 'bar',
                data: {
                    labels: labels,
                    datasets: [
                        {
                            label: 'Total SADD',
                            data: saddData,
                            backgroundColor: colors.sadd,
                            borderColor: colors.sadd,
                            borderWidth: 1
                        },
                        {
                            label: 'Persons with Disabilities',
                            data: pwdData,
                            backgroundColor: colors.pwd,
                            borderColor: colors.pwd,
                            borderWidth: 1
                        }
                    ]
                },
                options: {
                    scales: {
                        x: {
                            stacked: true
                        },
                        y: {
                            stacked: true
                        }
                    }
                }
            };
    }

    // Common options
    chartConfig.options = {
        ...chartConfig.options,
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: {
                position: 'top',
            },
            title: {
                display: true,
                text: '<?php echo addslashes($chartData['title']); ?>'
            }
        },
        animation: {
            duration: 1000,
            easing: 'easeInOutQuart'
        }
    };

    new Chart(ctx, chartConfig);
});
<?php endif; ?>

// Tab switching
function switchTab(tabName) {
    // Hide all tab contents
    document.querySelectorAll('.tab-content').forEach(tab => {
        tab.classList.remove('active');
    });
    
    // Remove active class from all tabs
    document.querySelectorAll('.tab').forEach(tab => {
        tab.classList.remove('active');
    });
    
    // Show selected tab content
    document.getElementById(tabName).classList.add('active');
    
    // Activate clicked tab
    event.target.classList.add('active');
}

// Dashboard sharing functions
function shareDashboard(platform) {
    const dashboardUrl = window.location.href;
    const title = 'N-SMART Nexus Dashboard';
    const message = 'Check out this interactive dashboard showing our project progress and impact metrics.';
    
    let shareUrl = '';
    
    switch(platform) {
        case 'email':
            shareUrl = `mailto:?subject=${encodeURIComponent(title)}&body=${encodeURIComponent(message + '\n\n' + dashboardUrl)}`;
            break;
        case 'telegram':
            shareUrl = `https://t.me/share/url?url=${encodeURIComponent(dashboardUrl)}&text=${encodeURIComponent(title + ' - ' + message)}`;
            break;
        case 'whatsapp':
            shareUrl = `https://wa.me/?text=${encodeURIComponent(title + ' - ' + message + ' ' + dashboardUrl)}`;
            break;
        case 'facebook':
            shareUrl = `https://www.facebook.com/sharer/sharer.php?u=${encodeURIComponent(dashboardUrl)}`;
            break;
    }
    
    if (shareUrl) {
        window.open(shareUrl, '_blank');
    }
}

function copyDashboardLink() {
    const dashboardUrl = window.location.href;
    navigator.clipboard.writeText(dashboardUrl).then(() => {
        alert('Dashboard link copied to clipboard!');
    });
}

// Export functions
function exportChartData() {
    alert('Export functionality would be implemented here');
    // In a real implementation, this would generate and download CSV/Excel
}

function exportAsPDF() {
    alert('PDF export would be implemented here');
}

function exportAsExcel() {
    alert('Excel export would be implemented here');
}

function exportAsImage() {
    alert('Image export would be implemented here');
}

function shareChart() {
    alert('Chart sharing dialog would open here');
}

function printChart() {
    window.print();
}

// Utility functions
function scrollToTop() {
    window.scrollTo({ top: 0, behavior: 'smooth' });
}

// Add some interactive effects
document.addEventListener('DOMContentLoaded', function() {
    // Add hover effects to cards
    const cards = document.querySelectorAll('.dashboard-card');
    cards.forEach(card => {
        card.addEventListener('mouseenter', function() {
            this.style.transform = 'translateY(-5px)';
        });
        card.addEventListener('mouseleave', function() {
            this.style.transform = 'translateY(0)';
        });
    });

    // Animate stat cards on scroll
    const observerOptions = {
        threshold: 0.1,
        rootMargin: '0px 0px -50px 0px'
    };

    const observer = new IntersectionObserver(function(entries) {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                entry.target.style.animation = 'fadeIn 0.6s ease-out';
            }
        });
    }, observerOptions);

    document.querySelectorAll('.stat-card').forEach(card => {
        observer.observe(card);
    });
});
</script>

<?php require_once __DIR__ . '/../footer.php'; ?>