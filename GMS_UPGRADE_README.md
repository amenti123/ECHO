# Grant Management System (GMS) - Full Upgrade

## Overview
This upgrade makes ALL modules of the Grant Management System fully functional with complete integration, email notifications, and reporting timeliness tracking.

## What's Fixed & Added

### ✅ All Modules Now Functional
1. **Dashboard** - Statistics and recent grants overview
2. **Grants** - Full CRUD operations for grant management
3. **Donors** - Complete donor management
4. **Opportunities** - Funding opportunity tracking with Go/No-Go decisions
5. **Work Plans** - Activity-based work plan management
6. **Partners** - Partner profiles and sub-grant management
7. **Budgets** - Budget line items with budget vs. actuals tracking
8. **Reporting** - Reporting schedule with timeliness tracking
9. **Risks & Compliance** - Risk register and compliance issue management

### ✅ AJAX Handlers Fixed
- All AJAX endpoints now return proper data
- No more `{"success":false,"data":null,"message":""}` errors
- Complete data loading for all modules

### ✅ Email Notifications
- **Automatic Report Deadline Notifications**: System checks for upcoming reports (7 days before due date) and sends email notifications to:
  - Program Manager (PM_email)
  - Executive Director (ed_email)
  - Finance Head (finance_head_email)
  - Project Coordinator (project_officer_email)
  - Operations Manager (opm_email)
  - MEAL Manager (merl_email)
- **Report Submission Notifications**: When a report is submitted, all project staff are notified
- **Timeliness Tracking**: System automatically marks reports as "Overdue" when past due date

### ✅ Integration with Other Apps
- **projects.php**: Grants link to projects, staff emails pulled from project records
- **planning.php**: Work plans can link to planning activities and indicators
- **budget.php**: Budget lines integrate with project budgets
- **indicators.php**: Grant indicators sync with system indicators

### ✅ Reporting Timeliness Features
- Automatic calculation of days overdue/early
- Visual indicators for report status (On Time, Overdue, Early)
- Deadline reminders sent 7 days before due date
- Automatic status updates (Pending → Overdue)

## Installation

1. **Backup your current file:**
   ```powershell
   Copy-Item "pages\grants.php" -Destination "pages\grants_backup.php"
   ```

2. **Extract and replace:**
   - Extract `grant_management_system_upgraded.zip`
   - Copy `pages\grants.php` to your `pages/` directory

3. **Database will auto-update:**
   - The system automatically adds the `last_notification_date` column to `gms_reporting_schedule` table
   - All tables are created automatically if they don't exist

## Key Features

### Email Notification System
- Notifications sent to all registered project staff
- HTML-formatted emails with grant details
- Automatic deadline reminders (7 days before due date)
- Submission confirmations

### Reporting Timeliness
- **On Time**: Submitted within 7 days before or on due date
- **Early**: Submitted more than 7 days before due date
- **Late**: Submitted after due date
- **Overdue**: Past due date and not yet submitted

### Auto-Generated Reporting Schedule
When a grant status changes to "Active", the system automatically:
- Generates reporting schedule based on reporting frequency (Monthly, Quarterly, Semi-Annual, Annual)
- Creates report entries for the entire grant period
- Sets appropriate due dates

### Budget Tracking
- Budget vs. Committed vs. Spent tracking
- Automatic remaining balance calculation
- Category-wise budget organization
- Integration with project budgets

## Usage

### Setting Up Email Notifications
1. Ensure project staff emails are entered in `projects.php`:
   - PM_email (Program Manager)
   - ed_email (Executive Director)
   - finance_head_email (Finance Head)
   - project_officer_email (Project Coordinator)
   - opm_email (Operations Manager)
   - merl_email (MEAL Manager)

2. The system automatically sends notifications when:
   - Reports are due in 7 days
   - Reports are submitted
   - Reports become overdue

### Creating a Grant
1. Navigate to Grants → Grants
2. Click "Add New Grant"
3. Fill in grant details
4. Link to a project (optional but recommended for email notifications)
5. Set reporting frequency
6. Save - reporting schedule will be auto-generated if status is "Active"

### Managing Reports
1. Navigate to Grants → Reporting
2. View all upcoming and overdue reports
3. Click "View" to see report details
4. Update status when report is submitted
5. System automatically tracks timeliness

## Troubleshooting

### Email Not Sending
- Check that project staff emails are entered in projects.php
- Verify PHP mail() function is configured on your server
- Check server error logs for mail delivery issues
- Emails are sent but may not work on localhost/XAMPP without SMTP configuration

### Reports Not Showing
- Ensure grants are linked to projects
- Check that grant status is "Active" for auto-generated schedules
- Verify reporting frequency is set correctly

### AJAX Errors
- All AJAX endpoints are now functional
- Check browser console for specific error messages
- Verify database tables exist (they auto-create on first access)

## Version
- **Version:** 2.0 (Fully Functional)
- **Date:** December 9, 2025
- **Status:** Production Ready

## Support
For issues or questions, check:
1. Browser console for JavaScript errors
2. PHP error logs for server-side errors
3. Database connection status
4. Project staff email configuration in projects.php

