<?php
// profile.php – User profile (edit account for N-SMART)
require_once __DIR__ . '/helpers.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_login();
$pdo = getPDO();
$user = current_user();

if (!$user) {
    header('Location: login.php');
    exit;
}

// Handle profile export and download - MUST BE BEFORE header.php include
if (isset($_GET['action']) && ($_GET['action'] === 'export_profile' || $_GET['action'] === 'download_my_info')) {
    // Prevent header.php from outputting full HTML
    $forceHeader = false;
    $disableWelcomeAnimation = true;
    
    // Refresh user data to ensure we have the latest information
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$user['id']]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC) ?: $user;
    
    // Determine file format based on action
    $isDownload = ($_GET['action'] === 'download_my_info');
    $filename = $isDownload ? 'my_information_' . date('Y-m-d') : 'profile_export_' . date('Y-m-d');
    
    // Set proper headers for download
    if (!headers_sent()) {
        header('Content-Type: application/vnd.ms-excel; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '.xls"');
        header('Cache-Control: max-age=0');
        header('Pragma: public');
    }
    
    echo "<html><head><meta charset='UTF-8'><title>My Information - Health Reporting System</title>";
    echo "<style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        .header { background: #4361ee; color: white; padding: 20px; border-radius: 8px; margin-bottom: 20px; }
        .header h1 { margin: 0 0 10px 0; }
        table { border-collapse: collapse; width: 100%; margin: 20px 0; }
        th, td { border: 1px solid #ddd; padding: 12px; text-align: left; }
        th { background-color: #4361ee; color: white; font-weight: bold; }
        tr:nth-child(even) { background-color: #f9fafb; }
    </style></head><body>";
    
    echo "<div class='header'>";
    echo "<h1>🚀 Health Reporting System</h1>";
    echo "<p>My Personal Information Export</p>";
    echo "<p style='margin: 5px 0; font-size: 14px;'>Export Date: " . date('Y-m-d H:i:s') . "</p>";
    echo "</div>";
    
    echo "<h2>📋 My Account Information</h2>";
    echo "<table border='1'>";
    echo "<tr><th style='width: 30%;'>Field</th><th>Value</th></tr>";
    echo "<tr><td><strong>User ID</strong></td><td>" . htmlspecialchars($user['id'] ?? 'N/A') . "</td></tr>";
    echo "<tr><td><strong>Full Name</strong></td><td>" . htmlspecialchars($user['full_name'] ?? 'Not set') . "</td></tr>";
    echo "<tr><td><strong>Email Address</strong></td><td>" . htmlspecialchars($user['email'] ?? 'N/A') . "</td></tr>";
    echo "<tr><td><strong>Phone Number</strong></td><td>" . htmlspecialchars($user['phone'] ?? 'Not provided') . "</td></tr>";
    echo "<tr><td><strong>Role</strong></td><td>" . htmlspecialchars(ucfirst($user['role'] ?? 'user')) . "</td></tr>";
    echo "<tr><td><strong>Account Status</strong></td><td>" . (($user['is_active'] ?? 0) ? '✅ Active' : '❌ Inactive') . "</td></tr>";
    echo "<tr><td><strong>Email Verified</strong></td><td>" . (($user['email_verified'] ?? 0) ? '✅ Verified' : '❌ Not Verified') . "</td></tr>";
    echo "<tr><td><strong>Last Login</strong></td><td>" . htmlspecialchars($user['last_login_at'] ?? 'Never logged in') . "</td></tr>";
    echo "<tr><td><strong>Last Logout</strong></td><td>" . htmlspecialchars($user['last_logout_at'] ?? 'N/A') . "</td></tr>";
    echo "<tr><td><strong>Last Seen</strong></td><td>" . htmlspecialchars($user['last_seen_at'] ?? 'N/A') . "</td></tr>";
    echo "<tr><td><strong>Account Created</strong></td><td>" . htmlspecialchars($user['created_at'] ?? 'N/A') . "</td></tr>";
    echo "<tr><td><strong>Profile Photo</strong></td><td>" . htmlspecialchars($user['profile_photo'] ?? $user['photo_path'] ?? 'No photo uploaded') . "</td></tr>";
    echo "</table>";
    
    // Add additional user data if available
    try {
        // Get user's project access
        $stmt = $pdo->prepare("
            SELECT p.id, p.title, p.code, p.donor, p.status
            FROM user_projects up
            JOIN projects p ON up.project_id = p.id
            WHERE up.user_id = ?
            ORDER BY p.id
        ");
        $stmt->execute([$user['id']]);
        $projects = $stmt->fetchAll();
        
        if (!empty($projects)) {
            echo "<h2 style='margin-top: 30px;'>📁 My Project Access</h2>";
            echo "<table border='1'>";
            echo "<tr><th>Project ID</th><th>Code</th><th>Title</th><th>Donor</th><th>Status</th></tr>";
            foreach ($projects as $proj) {
                echo "<tr>";
                echo "<td>" . htmlspecialchars($proj['id']) . "</td>";
                echo "<td>" . htmlspecialchars($proj['code'] ?? 'N/A') . "</td>";
                echo "<td>" . htmlspecialchars($proj['title'] ?? 'N/A') . "</td>";
                echo "<td>" . htmlspecialchars($proj['donor'] ?? 'N/A') . "</td>";
                echo "<td>" . htmlspecialchars(ucfirst($proj['status'] ?? 'active')) . "</td>";
                echo "</tr>";
            }
            echo "</table>";
        }
    } catch (Throwable $e) {
        // Ignore errors
    }
    
    echo "<div style='margin-top: 30px; padding: 15px; background: #f0f9ff; border-radius: 8px; border-left: 4px solid #4361ee;'>";
    echo "<p><strong>Note:</strong> This document contains your personal information from the Health Reporting System.</p>";
    echo "<p style='font-size: 12px; color: #6b7280;'>Keep this information secure and do not share it with unauthorized parties.</p>";
    echo "</div>";
    
    echo "</body></html>";
    exit; // Stop execution here - don't include header/footer
}

/**
 * SIMPLIFIED column checking - no complex SQL that causes syntax errors
 */
if (!function_exists('users_has_column')) {
    function users_has_column(PDO $pdo, string $column): bool {
        static $columns = null;
        
        // Get all columns once and cache them
        if ($columns === null) {
            $columns = [];
            try {
                $stmt = $pdo->query("DESCRIBE users");
                $result = $stmt->fetchAll(PDO::FETCH_ASSOC);
                foreach ($result as $row) {
                    $columns[$row['Field']] = true;
                }
            } catch (PDOException $e) {
                error_log("Failed to get table structure: " . $e->getMessage());
                return false;
            }
        }
        
        return isset($columns[$column]);
    }
}

/**
 * Simple table operations
 */
if (!function_exists('table_has_column')) {
    function table_has_column(PDO $pdo, string $table, string $column): bool {
        return users_has_column($pdo, $column);
    }
}

if (!function_exists('ensure_column')) {
    function ensure_column(PDO $pdo, string $table, string $column, string $definition): void {
        if (!table_has_column($pdo, $table, $column)) {
            try {
                $pdo->exec("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
                // Clear the cache
                if (function_exists('users_has_column')) {
                    // Force refresh of cached columns
                    $columns = null;
                }
            } catch (PDOException $e) {
                error_log("Failed to add column $column: " . $e->getMessage());
            }
        }
    }
}

// Ensure basic columns exist (simplified - just try to add them)
try {
    $columns_to_ensure = [
        'full_name' => "VARCHAR(190) NULL",
        'email' => "VARCHAR(190) NOT NULL",
        'role' => "ENUM('admin','user','guest') NOT NULL DEFAULT 'user'",
        'password_hash' => "VARCHAR(255) NOT NULL",
        'profile_photo' => "VARCHAR(255) NULL"
    ];
    
    foreach ($columns_to_ensure as $column => $definition) {
        ensure_column($pdo, 'users', $column, $definition);
    }
} catch (Throwable $e) {
    // Continue anyway
    error_log("Schema check failed: " . $e->getMessage());
}

// Check which columns exist using our simplified function
$hasFullName   = users_has_column($pdo, 'full_name');
$hasRole       = users_has_column($pdo, 'role');
$hasPhotoCol   = users_has_column($pdo, 'profile_photo');
$hasPassHash   = users_has_column($pdo, 'password_hash');
$hasPlainPass  = users_has_column($pdo, 'password');
$hasEmail      = users_has_column($pdo, 'email');

// Determine user role and admin status
$isAdmin = false;
$currentRole = 'user';

if ($hasRole && !empty($user['role'])) {
    $currentRole = strtolower($user['role']);
    $isAdmin = ($currentRole === 'admin');
}

$message = '';
$error = '';

// Process form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $full_name        = trim($_POST['full_name'] ?? '');
    $email            = trim($_POST['email'] ?? '');
    $rolePosted       = trim($_POST['role'] ?? '');
    $remove_photo     = isset($_POST['remove_photo']) && $_POST['remove_photo'] === '1';
    $new_password     = $_POST['new_password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    // Basic validation
    if ($hasEmail && empty($email)) {
        $error = 'Email is required.';
    } elseif ($hasEmail && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } elseif (!empty($new_password) && $new_password !== $confirm_password) {
        $error = 'New password and confirmation do not match.';
    } elseif (!empty($new_password) && strlen($new_password) < 6) {
        $error = 'Password must be at least 6 characters long.';
    } else {
        $fields = [];
        $params = [];

        // Full name
        if ($hasFullName) {
            $fields[] = 'full_name = ?';
            $params[] = $full_name;
        }

        // Email
        if ($hasEmail) {
            $fields[] = 'email = ?';
            $params[] = $email;
        }

        // Role – only admins can change it
        if ($hasRole && $isAdmin) {
            $allowed_roles = ['admin', 'user', 'guest'];
            $role_norm = in_array(strtolower($rolePosted), $allowed_roles) ? strtolower($rolePosted) : 'user';
            $fields[] = 'role = ?';
            $params[] = $role_norm;
        }

        // Handle profile photo
        $new_photo_path = null;
        if ($hasPhotoCol && empty($error)) {
            // Get current photo (check both columns for compatibility)
            $current_photo = $user['profile_photo'] ?? $user['photo_path'] ?? null;
            
            if ($remove_photo) {
                // Remove photo
                if (!empty($current_photo) && file_exists(__DIR__ . '/' . $current_photo)) {
                    @unlink(__DIR__ . '/' . $current_photo);
                }
                $new_photo_path = null;
            } elseif (!empty($_FILES['photo_file']['name']) && 
                     $_FILES['photo_file']['error'] === UPLOAD_ERR_OK) {
                
                $tmp_name = $_FILES['photo_file']['tmp_name'];
                $file_name = $_FILES['photo_file']['name'];
                $file_size = $_FILES['photo_file']['size'];
                
                // Validate file
                $allowed_types = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'tif', 'tiff', 'jfif'];
                $ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
                
                if (!in_array($ext, $allowed_types)) {
                    $error = 'Invalid file type. Allowed: JPG, JPEG, PNG, GIF, WEBP, BMP, TIF/TIFF, JFIF.';
                } elseif ($file_size > 15000000) { // 15MB
                    $error = 'File too large. Maximum size is 15MB.';
                } else {
                    // Security: verify the uploaded file is a real image
                    if (@getimagesize($tmp_name) === false) {
                        $error = 'Invalid image file.';
                    } else {
                    // Create upload directory if needed (use profile instead of profile_photos for consistency)
                    $upload_dir = __DIR__ . '/uploads/profile';
                    if (!is_dir($upload_dir)) {
                        @mkdir($upload_dir, 0755, true);
                    }
                    
                    // Generate unique filename
                    $new_filename = 'user_' . $user['id'] . '_' . time() . '.' . $ext;
                    $dest_path = $upload_dir . '/' . $new_filename;
                    $rel_path = 'uploads/profile/' . $new_filename;
                    
                    }
                    
                    if (move_uploaded_file($tmp_name, $dest_path)) {
                        // Delete old photo
                        if (!empty($current_photo) && file_exists(__DIR__ . '/' . $current_photo)) {
                            @unlink(__DIR__ . '/' . $current_photo);
                        }
                        $new_photo_path = $rel_path;
                    } else {
                        $error = 'Failed to upload file. Check permissions.';
                    }
                }
            }
            
            if (empty($error)) {
                $fields[] = 'profile_photo = ?';
                $params[] = $new_photo_path;
                // Also update photo_path if it exists (for compatibility)
                if (users_has_column($pdo, 'photo_path')) {
                    $fields[] = 'photo_path = ?';
                    $params[] = $new_photo_path;
                }
            }
        }

        // Password change
        if (empty($error) && !empty($new_password)) {
            if ($hasPassHash) {
                $fields[] = 'password_hash = ?';
                $params[] = password_hash($new_password, PASSWORD_DEFAULT);
            }
        }

        // Update database
        if (empty($error) && !empty($fields)) {
            try {
                $params[] = $user['id'];
                $sql = "UPDATE users SET " . implode(', ', $fields) . " WHERE id = ?";
                $stmt = $pdo->prepare($sql);
                
                if ($stmt->execute($params)) {
                    $message = 'Profile updated successfully.';
                    
                    // Refresh user data
                    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
                    $stmt->execute([$user['id']]);
                    $updated_user = $stmt->fetch(PDO::FETCH_ASSOC);
                    if ($updated_user) {
                        $user = $updated_user;
                        // Update session with fresh data
                        $_SESSION['user'] = $user;
                        $_SESSION['name'] = $user['full_name'] ?? $user['email'];
                        
                        // Update profile photo in session immediately
                        if (!empty($user['profile_photo'])) {
                            $_SESSION['user_photo'] = $user['profile_photo'];
                            $_SESSION['photo_updated'] = time();
                            
                            // Clear old cache and set new one
                            if (isset($_SESSION[PROFILE_PHOTO_KEY])) {
                                unset($_SESSION[PROFILE_PHOTO_KEY][$user['id']]);
                            }
                            $_SESSION[PROFILE_PHOTO_KEY][$user['id']] = [
                                'path' => $user['profile_photo'],
                                'timestamp' => time(),
                                'cache_url' => $user['profile_photo'] . '?t=' . time()
                            ];
                            
                            // Update cross-app cache
                            if (function_exists('CrossAppCommunicator')) {
                                $crossApp = CrossAppCommunicator::getInstance();
                                $crossApp->setData('profile_photo', $user['profile_photo'], 'user_' . $user['id']);
                                $crossApp->setData('photo_updated', time(), 'user_' . $user['id']);
                            }
                        }
                        
                        // Update welcome session for next login
                        $_SESSION['welcome_user'] = [
                            'name' => $user['full_name'] ?? $user['email'],
                            'photo' => $user['profile_photo'] ?? null,
                            'show_welcome' => true,
                            'role' => $user['role'] ?? 'user'
                        ];
                        
                        // Force session write
                        session_write_close();
                        session_start();
                    }
                } else {
                    $error = 'Failed to update profile.';
                }
            } catch (PDOException $e) {
                error_log("Update error: " . $e->getMessage());
                $error = 'Database error occurred. Please try again.';
            }
        } elseif (empty($error)) {
            $message = 'No changes to update.';
        }
    }
}

require_once __DIR__ . '/header.php';
?>

<div class="card">
    <h1>My Profile</h1>

    <?php if ($error): ?>
        <div class="error-message"><?php echo htmlspecialchars($error); ?></div>
    <?php elseif ($message): ?>
        <div class="success-message"><?php echo htmlspecialchars($message); ?></div>
    <?php endif; ?>

    <form method="post" enctype="multipart/form-data">
        <div class="form-section">
            <?php if ($hasFullName): ?>
                <div class="form-group">
                    <label for="full_name">Full Name</label>
                    <input
                        type="text"
                        id="full_name"
                        name="full_name"
                        value="<?php echo htmlspecialchars($user['full_name'] ?? ''); ?>"
                        placeholder="Your full name">
                </div>
            <?php endif; ?>

            <?php if ($hasEmail): ?>
                <div class="form-group">
                    <label for="email">Email Address *</label>
                    <input
                        type="email"
                        id="email"
                        name="email"
                        required
                        value="<?php echo htmlspecialchars($user['email'] ?? ''); ?>"
                        placeholder="your.email@example.com">
                </div>
            <?php endif; ?>

            <?php if ($hasRole): ?>
                <div class="form-group">
                    <label for="role">Role</label>
                    <?php if ($isAdmin): ?>
                        <select id="role" name="role">
                            <option value="admin" <?php echo $currentRole === 'admin' ? 'selected' : ''; ?>>Admin</option>
                            <option value="user" <?php echo $currentRole === 'user' ? 'selected' : ''; ?>>User</option>
                            <option value="guest" <?php echo $currentRole === 'guest' ? 'selected' : ''; ?>>Guest</option>
                        </select>
                    <?php else: ?>
                        <input
                            type="text"
                            value="<?php echo htmlspecialchars(ucfirst($currentRole)); ?>"
                            disabled
                            style="background:#f5f5f5;">
                        <small>Only administrators can change roles</small>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>

        <?php if ($hasPhotoCol): ?>
            <div class="form-section">
                <h3>Profile Photo</h3>
                <div class="photo-section">
                    <div class="current-photo">
                        <?php 
                        // Check both profile_photo and photo_path columns for compatibility
                        $photoPath = $user['profile_photo'] ?? $user['photo_path'] ?? null;
                        $photoUrl = '';
                        if ($photoPath) {
                            // Handle both absolute and relative paths
                            if (strpos($photoPath, 'http') === 0) {
                                $photoUrl = $photoPath;
                            } elseif (strpos($photoPath, '/') === 0) {
                                $photoUrl = $photoPath;
                            } else {
                                // Build proper URL with BASE_URL
                                $baseUrl = '/health_reporting_system';
                                if (strpos($photoPath, 'http') === 0) {
                                    $photoUrl = $photoPath;
                                } elseif (strpos($photoPath, '/') === 0) {
                                    $photoUrl = $baseUrl . $photoPath;
                                } else {
                                    $photoUrl = $baseUrl . '/' . ltrim($photoPath, '/');
                                }
                            }
                            // Add cache busting
                            $photoUrl .= '?t=' . time();
                        }
                        ?>
                        <?php 
                        // Check if file exists (try multiple possible paths)
                        $photoExists = false;
                        $photoFile = null;
                        if ($photoPath) {
                            $possiblePaths = [
                                __DIR__ . '/' . ltrim($photoPath, '/'),
                                __DIR__ . '/uploads/profile/' . basename($photoPath),
                                __DIR__ . '/uploads/profile_photos/' . basename($photoPath)
                            ];
                            foreach ($possiblePaths as $path) {
                                if (file_exists($path)) {
                                    $photoExists = true;
                                    $photoFile = $path;
                                    break;
                                }
                            }
                        }
                        if ($photoExists): ?>
                            <img src="<?php echo htmlspecialchars($photoUrl); ?>" 
                                 alt="Profile Photo" class="profile-image"
                                 onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                            <div class="default-avatar" style="display:none;">
                                <?php echo getInitials($user); ?>
                            </div>
                        <?php else: ?>
                            <div class="default-avatar">
                                <?php echo getInitials($user); ?>
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <div class="photo-controls">
                        <div class="form-group">
                            <label for="photo_file">Upload New Photo</label>
                            <input type="file" id="photo_file" name="photo_file" accept="image/*">
                            <small>Max size: 5MB. Allowed: JPG, PNG, GIF, WEBP</small>
                        </div>
                        
                        <?php if (!empty($user['profile_photo'])): ?>
                            <div class="form-group">
                                <label class="checkbox-label">
                                    <input type="checkbox" name="remove_photo" value="1">
                                    Remove current photo
                                </label>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <div class="form-section">
            <h3>Change Password</h3>
            <p class="form-help">Leave blank to keep current password</p>
            
            <div class="form-row">
                <div class="form-group">
                    <label for="new_password">New Password</label>
                    <input
                        type="password"
                        id="new_password"
                        name="new_password"
                        autocomplete="new-password"
                        placeholder="Leave blank to keep current">
                </div>
                
                <div class="form-group">
                    <label for="confirm_password">Confirm New Password</label>
                    <input
                        type="password"
                        id="confirm_password"
                        name="confirm_password"
                        autocomplete="new-password"
                        placeholder="Repeat new password">
                </div>
            </div>
        </div>

        <div class="form-actions">
            <button type="submit" class="btn btn-primary">💾 Save Changes</button>
            <a href="dashboard.php" class="btn btn-secondary">Cancel</a>
            <button type="button" onclick="window.print()" class="btn btn-warning no-print">🖨️ Print</button>
            <a href="?action=export_profile" class="btn btn-info no-print">📥 Export Profile</a>
            <a href="?action=download_my_info" class="btn btn-success no-print">📥 Download My Information</a>
        </div>
    </form>
    
    <!-- Direct Download Link Section -->
    <div class="card" style="margin-top: 20px; background: #f0f9ff; border-left: 4px solid #4361ee;">
        <h3>📥 Download Your Information</h3>
        <p>Click the button below to download all your profile information in a downloadable format:</p>
        <div style="margin: 15px 0;">
            <a href="?action=download_my_info" class="btn btn-success" style="font-size: 16px; padding: 12px 24px;">
                📥 Download My Complete Information
            </a>
        </div>
        <p style="font-size: 12px; color: #6b7280; margin-top: 10px;">
            <strong>Direct Download Link:</strong><br>
            <code style="background: white; padding: 5px; border-radius: 4px; word-break: break-all;">
                <?php 
                $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
                $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
                $basePath = '/health_reporting_system';
                echo $scheme . '://' . $host . $basePath . '/profile.php?action=download_my_info';
                ?>
            </code>
        </p>
    </div>
</div>


<style>
@media print {
    .no-print, button, a.btn { display: none !important; }
    body::before {
        content: "Health Reporting System - My Profile";
        display: block;
        font-size: 18px;
        font-weight: bold;
        color: #4361ee;
        padding: 15px;
        border-bottom: 3px solid #4361ee;
        margin-bottom: 20px;
    }
}
</style>

<style>
.card {
    max-width: 800px;
    margin: 20px auto;
    padding: 20px;
    background: white;
    border-radius: 8px;
    box-shadow: 0 2px 10px rgba(0,0,0,0.1);
}

.form-section {
    margin-bottom: 30px;
    padding-bottom: 20px;
    border-bottom: 1px solid #eee;
}

.form-group {
    margin-bottom: 15px;
}

.form-group label {
    display: block;
    margin-bottom: 5px;
    font-weight: bold;
}

.form-group input,
.form-group select {
    width: 100%;
    padding: 8px 12px;
    border: 1px solid #ddd;
    border-radius: 4px;
    font-size: 14px;
}

.form-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 15px;
}

.photo-section {
    display: flex;
    gap: 20px;
    align-items: flex-start;
}

.current-photo {
    flex-shrink: 0;
    position: relative;
}

.profile-image {
    width: 100px;
    height: 100px;
    border-radius: 50%;
    object-fit: cover;
    border: 3px solid #e5e7eb;
}

.default-avatar {
    width: 100px;
    height: 100px;
    border-radius: 50%;
    background: #e5e7eb;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 24px;
    font-weight: bold;
    color: #6b7280;
}

.photo-controls {
    flex: 1;
}

.form-actions {
    text-align: center;
    margin-top: 30px;
}

.btn {
    padding: 10px 20px;
    border: none;
    border-radius: 4px;
    cursor: pointer;
    text-decoration: none;
    display: inline-block;
    font-size: 14px;
}

.btn-primary {
    background: #007bff;
    color: white;
}

.btn-secondary {
    background: #6c757d;
    color: white;
    margin-left: 10px;
}

.error-message {
    background: #f8d7da;
    color: #721c24;
    padding: 10px;
    border-radius: 4px;
    margin-bottom: 15px;
}

.success-message {
    background: #d1edff;
    color: #155724;
    padding: 10px;
    border-radius: 4px;
    margin-bottom: 15px;
}

.form-help {
    color: #6c757d;
    font-size: 12px;
    margin-top: -10px;
    margin-bottom: 15px;
}

.checkbox-label {
    display: flex;
    align-items: center;
    font-weight: normal;
}

.checkbox-label input {
    width: auto;
    margin-right: 8px;
}

.photo-preview {
    margin-top: 10px;
    text-align: center;
}

.photo-preview img {
    max-width: 150px;
    max-height: 150px;
    border-radius: 8px;
    border: 2px solid #ddd;
}
</style>

<script>
// Real-time photo preview
document.addEventListener('DOMContentLoaded', function() {
    const photoInput = document.getElementById('photo_file');
    const currentPhoto = document.querySelector('.current-photo');
    
    if (photoInput) {
        photoInput.addEventListener('change', function(e) {
            const file = e.target.files[0];
            if (file) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    // Create preview
                    let preview = currentPhoto.querySelector('.photo-preview');
                    if (!preview) {
                        preview = document.createElement('div');
                        preview.className = 'photo-preview';
                        currentPhoto.appendChild(preview);
                    }
                    preview.innerHTML = '<p><strong>New Photo Preview:</strong></p><img src="' + e.target.result + '" alt="Preview">';
                };
                reader.readAsDataURL(file);
            }
        });
    }
    
    // Handle remove photo checkbox
    const removeCheckbox = document.querySelector('input[name="remove_photo"]');
    if (removeCheckbox) {
        removeCheckbox.addEventListener('change', function() {
            if (this.checked) {
                const preview = currentPhoto.querySelector('.photo-preview');
                if (preview) {
                    preview.style.display = 'none';
                }
            }
        });
    }
});
</script>

<?php 
// Helper function to get user initials
function getInitials($user) {
    $initials = 'U';
    if (!empty($user['full_name'])) {
        $names = explode(' ', $user['full_name']);
        $initials = '';
        foreach ($names as $name) {
            if (!empty($name)) {
                $initials .= strtoupper($name[0]);
                if (strlen($initials) >= 2) break;
            }
        }
    } elseif (!empty($user['email'])) {
        $initials = strtoupper($user['email'][0]);
    }
    return htmlspecialchars($initials);
}
?>

<?php require_once __DIR__ . '/footer.php'; ?>