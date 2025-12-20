<?php
require_once __DIR__ . '/../helpers.php';

header('Content-Type: application/vnd.ms-excel');
header('Content-Disposition: attachment; filename="cfm_reports_' . date('Y-m-d') . '.xls"');

$pdo = getPDO();
$reports = getCFMReports($pdo);

echo "<table border='1'>";
echo "<tr>
    <th>Project Title</th>
    <th>Reported By</th>
    <th>Position</th>
    <th>Date Feedback Received</th>
    <th>Date of Report</th>
    <th>Days Since Complaint</th>
    <th>Feedback Type</th>
    <th>Organization</th>
    <th>Region</th>
    <th>Zone</th>
    <th>Woreda</th>
    <th>Gender</th>
    <th>Age</th>
    <th>Community Type</th>
    <th>Vulnerability</th>
    <th>Language</th>
    <th>Actual Feedback</th>
    <th>Feedback Channel</th>
    <th>Feedback Category</th>
    <th>Feedback Concern</th>
    <th>Feedback Status</th>
    <th>Actions Taken</th>
    <th>Responsibility</th>
    <th>Expected Closure</th>
    <th>Reason if Delayed</th>
    <th>Recommendation</th>
</tr>";

foreach ($reports as $report) {
    $days_open = calculateDaysOpen($report['date_feedback_received']);
    
    echo "<tr>";
    echo "<td>" . h($report['project_title'] ?? 'N/A') . "</td>";
    echo "<td>" . h($report['reported_by']) . "</td>";
    echo "<td>" . h($report['position']) . "</td>";
    echo "<td>" . h($report['date_feedback_received']) . "</td>";
    echo "<td>" . h($report['date_of_report']) . "</td>";
    echo "<td>" . formatDays($days_open) . "</td>";
    echo "<td>" . h($report['feedback_type']) . "</td>";
    echo "<td>" . h($report['organization']) . "</td>";
    echo "<td>" . h($report['region_name'] ?? 'N/A') . "</td>";
    echo "<td>" . h($report['zone_name'] ?? 'N/A') . "</td>";
    echo "<td>" . h($report['woreda_name'] ?? 'N/A') . "</td>";
    echo "<td>" . h($report['gender']) . "</td>";
    echo "<td>" . h($report['age']) . "</td>";
    echo "<td>" . h($report['community_type']) . "</td>";
    echo "<td>" . h($report['vulnerability']) . "</td>";
    echo "<td>" . h($report['language']) . "</td>";
    echo "<td>" . h($report['actual_feedback']) . "</td>";
    echo "<td>" . h($report['feedback_channel']) . "</td>";
    echo "<td>" . h($report['feedback_category']) . "</td>";
    echo "<td>" . h($report['feedback_concern']) . "</td>";
    echo "<td>" . h($report['feedback_status']) . "</td>";
    echo "<td>" . h($report['actions_taken']) . "</td>";
    echo "<td>" . h($report['responsibility_follow_up']) . "</td>";
    echo "<td>" . h($report['expected_closure_date']) . "</td>";
    echo "<td>" . h($report['reason_closure_passed']) . "</td>";
    echo "<td>" . h($report['recommendation']) . "</td>";
    echo "</tr>";
}

echo "</table>";
?>