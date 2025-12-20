# Grant Management System (GMS) - Installation Guide

## Overview
The Grant Management System (GMS) is a comprehensive grant lifecycle management application aligned with UN agencies, INGOs, and major donors. It integrates seamlessly with your existing project management, planning, budget, and reporting systems.

## Features

### Core Modules
1. **Donor & Funding Opportunity Management**
   - Register and manage donors (UN agencies, bilateral donors, foundations, etc.)
   - Track funding opportunities with deadlines and submission tracking
   - Go/No-Go decision framework

2. **Grant Master & Agreement Management**
   - Complete grant registration with key terms and conditions
   - Agreement document management
   - Payment schedule tracking
   - Special conditions and compliance requirements

3. **Budget & Financial Tracking**
   - Grant budget line items
   - Budget vs. actuals monitoring
   - Disbursement tracking
   - Multi-currency support
   - Integration with budget.php

4. **Work Plans & Procurement Plans**
   - Activity-based work plans linked to grants
   - Procurement planning and tracking
   - Milestone and deliverable management
   - Integration with planning.php

5. **Partner & Sub-grant Management**
   - Partner profiles and due diligence
   - Capacity assessments
   - Sub-grant agreements and monitoring
   - Risk rating system

6. **Reporting & Deliverables**
   - Donor reporting schedule management
   - Narrative and financial report templates
   - Submission tracking and status monitoring

7. **Risk & Compliance**
   - Risk register with mitigation measures
   - Compliance issue tracking
   - Audit findings management
   - Corrective action tracking

8. **Integration Features**
   - Links grants to projects (projects.php)
   - Syncs with planning indicators (planning.php)
   - Financial data integration (budget.php)
   - Indicator tracking (indicators.php)

## Installation

### Step 1: Backup Your System
```powershell
# Backup your database
mysqldump -u root -p your_database > backup_before_gms.sql

# Backup your files
Copy-Item "pages" -Destination "pages_backup" -Recurse
```

### Step 2: Copy Files
1. Copy `pages/grants.php` to your `pages/` directory
2. Copy `header.php` to your root directory (this adds the Grants app to navigation)

### Step 3: Database Setup
The GMS will automatically create all required tables on first access. The system includes:
- 15+ database tables for comprehensive grant management
- Automatic schema initialization
- Foreign key relationships for data integrity

### Step 4: Access the System
1. Log in to your system
2. Navigate to the "Grant Management" app in the header navigation
3. The system will automatically initialize the database schema

## Database Tables Created

The GMS creates the following tables:
- `gms_donors` - Donor information
- `gms_funding_opportunities` - Funding opportunities tracking
- `gms_grants` - Grant master records
- `gms_grant_agreements` - Grant agreements and amendments
- `gms_grant_budgets` - Budget line items
- `gms_grant_disbursements` - Disbursement tracking
- `gms_work_plans` - Work plan activities
- `gms_procurement_plans` - Procurement planning
- `gms_partners` - Partner profiles
- `gms_sub_grants` - Sub-grant agreements
- `gms_reporting_schedule` - Reporting calendar
- `gms_risk_register` - Risk management
- `gms_compliance_issues` - Compliance tracking
- `gms_grant_indicators` - Indicator linkage
- `gms_documents` - Document management

## Integration Points

### With projects.php
- Grants can be linked to projects via `project_id`
- Project data is displayed in grant views
- Grant information appears in project details

### With planning.php
- Grant indicators sync with planning indicators
- Work plans link to planning activities
- Results framework alignment

### With budget.php
- Grant budgets integrate with project budgets
- Financial tracking across systems
- Budget vs. actuals reporting

### With indicators.php
- Grant indicators link to system indicators
- Indicator tracking and reporting
- Performance monitoring

## Usage Guide

### 1. Setting Up Donors
1. Navigate to Grants → Donors
2. Click "Add New Donor"
3. Fill in donor information (name, type, contact details)
4. Save

### 2. Creating a Grant
1. Navigate to Grants → Grants
2. Click "Add New Grant"
3. Select donor and link to project (if applicable)
4. Enter grant details (title, dates, budget, etc.)
5. Save

### 3. Managing Work Plans
1. Navigate to Grants → Work Plans
2. Select a grant
3. Add activities linked to budget lines
4. Track progress and milestones

### 4. Tracking Budgets
1. Navigate to Grants → Budgets
2. View budget vs. actuals
3. Monitor disbursements
4. Track burn rate

### 5. Managing Partners
1. Navigate to Grants → Partners
2. Register partner organizations
3. Complete due diligence
4. Create sub-grant agreements

## Key Features

### Compliance & Standards
- Aligned with UN agency requirements
- INGO best practices
- Major donor compliance standards
- Audit trail for all changes
- Document management system

### Reporting
- Donor-specific report templates
- Financial and narrative reports
- Automated reporting calendar
- Submission tracking

### Risk Management
- Risk register with categorization
- Mitigation measures tracking
- Compliance issue management
- Corrective action tracking

## Support & Customization

The GMS is designed to be:
- **Extensible**: Add custom fields and modules
- **Configurable**: Adapt to your organization's needs
- **Integrated**: Works seamlessly with existing apps
- **Compliant**: Meets global standards

## Version
- **Version:** 1.0
- **Date:** December 2025
- **Status:** Production Ready

## Next Steps

1. **Customize**: Adapt donor types, risk categories, and reporting templates to your needs
2. **Train Users**: Provide training on grant lifecycle management
3. **Integrate**: Connect with external finance systems if needed
4. **Enhance**: Add custom modules based on your requirements

## Troubleshooting

### Database Errors
- Ensure MySQL user has CREATE TABLE permissions
- Check database connection in `db.php`
- Review error logs for specific issues

### Integration Issues
- Verify projects exist before linking grants
- Check that planning and budget modules are accessible
- Ensure user permissions are set correctly

## Contact
For support or customization requests, refer to your system administrator.

