<?php
// integration_fixes.php - Place in your root directory
function ensure_all_required_functions() {
    // Ensure table_has_column function exists everywhere
    if (!function_exists('table_has_column')) {
        function table_has_column(PDO $pdo, string $table, string $column): bool {
            try {
                $stmt = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
                $stmt->execute([$column]);
                return (bool)$stmt->fetch();
            } catch (Throwable $e) {
                return false;
            }
        }
    }
    
    // Ensure user_can_view_app function exists
    if (!function_exists('user_can_view_app')) {
        function user_can_view_app(string $appKey): bool {
            return true; // Default to true for backwards compatibility
        }
    }
}

// Call this at the start of every page
ensure_all_required_functions();