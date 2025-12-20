
<?php
// File: scripts/notifications.php
function sendQualityNotifications($pdo) {
    // Get projects with poor quality scores
    $query = "
        SELECT p.id, p.title, u.email, p.notification_email,
               (SELECT AVG(completeness_score) FROM (...) ) as completeness,
               (SELECT COUNT(*) FROM reports WHERE project_id = p.id AND ...) as timeliness
        FROM projects p
        LEFT JOIN users u ON p.manager_id = u.id
        WHERE ... -- Add your quality threshold conditions
    ";
    
    // Implementation for sending email/telegram notifications
    // This would integrate with your email system and messaging APIs
}

function getReportingScheduleMessage($frequency) {
    $messages = [
        'weekly' => "📅 Weekly Reporting: Deadline every Monday for the previous week",
        'monthly' => "📅 Monthly Reporting: Submit between 1st-3rd of next month", 
        'quarterly' => "📅 Quarterly Reporting: Due 1st-3rd of first month next quarter",
        'annual' => "📅 Annual Reporting: Submit 1st-3rd of January next year"
    ];
    
    return $messages[$frequency] ?? "Check your reporting schedule";
}
?>