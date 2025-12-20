# Data Quality Module Upgrade - Version 3.0

## 📦 What's Included
- `pages/data_quality.php` - Fully upgraded data quality monitoring dashboard with proper linking

## 🎯 Key Upgrades

### 1. **Project Activity Reports Integration - FIXED**
- ✅ **Tables are now automatically initialized** when data_quality.php loads
- ✅ **Properly linked** with `project_activity_report.php`
- ✅ Pulls data from `project_activity_reports` and `project_activity_report_lines` tables
- ✅ Displays activity report statistics in the dashboard
- ✅ Integrated into all quality dimension calculations:
  - **Timeliness**: Includes submission and approval rates from activity reports
  - **Completeness**: Includes activity completion rates
  - **Accuracy**: Uses activity report data quality scores
- ✅ **Improved query performance** with separate queries for better data retrieval
- ✅ **Better error handling** with detailed logging

### 2. **Budget Module Integration - ENHANCED**
- ✅ **Explicitly linked** to budget data
- ✅ Pulls from `budget_lines` or `budgets` tables
- ✅ Displays budget records in data sources summary
- ✅ Integrated into completeness calculations
- ✅ Shows budget utilization and burn rates
- ✅ **Active badge** displayed when budget data is available

### 3. **Aggregation Module Integration - ENHANCED**
- ✅ **Explicitly linked** to aggregation/indicator results
- ✅ Pulls from `indicator_results` table
- ✅ Displays aggregation records in data sources summary
- ✅ Integrated into completeness calculations
- ✅ **Active badge** displayed when aggregation data is available

### 4. **Enhanced Data Sources Display**
- ✅ New row for "Project Activity Reports" in Data Sources table
- ✅ Shows active projects count, total entries, and last sync time
- ✅ Activity reports icon (📋) added to project data sources list
- ✅ **Activity Reports badge** added to data source indicators banner
- ✅ All data sources (Reporting, Enter Data, CFM, Budget, Aggregation, Activity Reports) properly displayed

### 5. **Improved Quality Calculations**
- ✅ Activity reports weighted at 25% in timeliness calculations
- ✅ Activity reports weighted at 25% in completeness calculations
- ✅ Activity reports weighted at 35% in accuracy calculations
- ✅ Budget and aggregation data included in completeness scoring
- ✅ All calculations properly handle missing data

### 6. **Table Initialization**
- ✅ Activity report tables are automatically created if they don't exist
- ✅ Proper indexes added for better query performance
- ✅ Safe initialization with error handling

## 🔧 Installation Instructions

### Step 1: Backup Current File
**IMPORTANT:** Always backup before replacing!

1. Navigate to your project folder:
   ```
   C:\xampp\htdocs\health_reporting_system
   ```

2. Backup the current file:
   ```powershell
   copy pages\data_quality.php pages\data_quality.php.backup
   ```

   **OR** manually:
   - Copy `pages\data_quality.php` to `pages\data_quality.php.backup`

### Step 2: Extract the Zip File
1. Extract `data_quality_upgrade.zip` to a temporary folder
2. You will see:
   - `pages\data_quality.php`
   - `DATA_QUALITY_UPGRADE_README.md` (this file)

### Step 3: Replace the File
1. Copy `pages\data_quality.php` from the extracted zip
2. Paste it to: `C:\xampp\htdocs\health_reporting_system\pages\data_quality.php`
   - **Replace** the existing file when prompted

### Step 4: Verify File Permissions
Make sure the file is readable and writable:
- Right-click the file → Properties
- Ensure "Read" and "Write" are checked (if applicable)

### Step 5: Test the Installation
1. Open your browser
2. Go to: `http://localhost/health_reporting_system/pages/data_quality.php`
3. Verify:
   - ✅ Page loads without errors
   - ✅ Activity Reports data source shows in the banner
   - ✅ Activity Reports row appears in Data Sources table
   - ✅ Budget and Aggregation modules show as active when data exists
   - ✅ All quality dimensions calculate correctly
   - ✅ Filters work properly

## ✅ Verification Checklist

After replacement, check:
- [ ] No PHP errors appear
- [ ] Data Quality page loads correctly
- [ ] Activity Reports data source badge is visible
- [ ] Activity Reports row appears in Data Sources table
- [ ] Budget module shows as active (if budget data exists)
- [ ] Aggregation module shows as active (if aggregation data exists)
- [ ] Quality dimension calculations work
- [ ] Filters (project, region, zone, woreda, time) work
- [ ] Export functions (PDF, Excel, Word, CSV, Image) work

## 🐛 Troubleshooting

### Issue: "Table doesn't exist" errors
- **Solution**: The tables should be created automatically. Check PHP error logs if issues persist.
- **Location**: `C:\xampp\php\logs\php_error_log`

### Issue: Activity Reports not showing data
- **Solution**: 
  1. Ensure you have created activity reports in `project_activity_report.php`
  2. Check that the project_id matches between reports and projects
  3. Verify the date range in filters includes the report dates
  4. Check browser console for JavaScript errors

### Issue: Budget/Aggregation not showing
- **Solution**:
  1. Verify budget/aggregation tables exist in your database
  2. Check that data exists for the selected projects
  3. Ensure the date range includes the data dates

### Issue: "Permission Denied"
- **Solution**: Right-click file → Properties → Uncheck "Read-only" → Apply

### Issue: Page shows errors after replacement
- **Solution**: 
  1. Restore from backup
  2. Check PHP error logs
  3. Verify file encoding is UTF-8
  4. Check that all code is intact (no corruption)

### Issue: Features not working
- **Solution**:
  1. Clear browser cache
  2. Check browser console for JavaScript errors
  3. Verify PHP version compatibility (PHP 7.4+)
  4. Check database connection

## 📝 File Locations

**Original File:**
- `C:\xampp\htdocs\health_reporting_system\pages\data_quality.php`

**Backup File (after backup):**
- `C:\xampp\htdocs\health_reporting_system\pages\data_quality.php.backup`

## 🔄 Rollback Instructions

If something goes wrong, restore from backup:

```powershell
copy pages\data_quality.php.backup pages\data_quality.php -Force
```

## 📊 What Changed

### Database Tables
- `project_activity_reports` - Automatically created if missing
- `project_activity_report_lines` - Automatically created if missing
- Both tables include proper indexes for performance

### Functions Updated
- `initialize_activity_report_tables()` - New function to ensure tables exist
- `getActivityReportData()` - Improved with better error handling and query optimization
- `getBudgetData()` - Already properly linked
- `getAggregationData()` - Already properly linked

### Display Updates
- Added Activity Reports badge to data source indicators
- Activity Reports row in Data Sources table
- All data sources properly linked and displayed

## 📞 Need Help?

If you encounter issues:
1. Check PHP error logs in: `C:\xampp\php\logs\`
2. Check Apache error logs in: `C:\xampp\apache\logs\`
3. Verify file permissions
4. Ensure XAMPP is running
5. Check database connection

---

**Note**: Always backup before replacing files!

**Version**: 3.0
**Date**: December 2025
**Compatibility**: PHP 7.4+, MySQL 5.7+
