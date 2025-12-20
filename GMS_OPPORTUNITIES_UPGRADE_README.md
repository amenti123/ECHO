# GMS Opportunities Module - Enhanced Upgrade

## ✅ All Features Implemented

### 1. Fixed Errors
- ✅ Database schema updated with all new fields
- ✅ Form submission handlers updated
- ✅ All SQL queries fixed

### 2. Opportunity Types
- ✅ **CFP** - Call for Proposals
- ✅ **EOI** - Expression of Interest
- ✅ **RfP** - Request for Proposals
- ✅ **RfQ** - Request for Quotations
- ✅ **RFA** - Request for Applications
- ✅ **Other** - Other types

### 3. New Fields Added
- ✅ **Opportunity Type** (required dropdown)
- ✅ **Announcement Date** (required date field)
- ✅ **Opportunity Website URL** (URL field for where opportunity is posted)
- ✅ **Call Summary** (required textarea for summary of CFP/EOI)
- ✅ **Responsible Person Name** (shown when Go decision is made)
- ✅ **Responsible Person Position** (shown when Go decision is made)
- ✅ **Responsible Person Email** (shown when Go decision is made)

### 4. Go/No-Go Decision Enhancement
- ✅ When "Go" is selected, responsible person fields appear
- ✅ Responsible person fields are required when "Go" is selected
- ✅ Email notification sent to assigned person when "Go" decision is made
- ✅ Email includes:
  - Opportunity details
  - Submission deadline
  - Days remaining
  - Assignment notification

### 5. Days Remaining Calculation
- ✅ Automatically calculated from announcement date to submission deadline
- ✅ Displayed in opportunities table with color coding:
  - **Green**: More than 30 days remaining
  - **Yellow**: 7-30 days remaining (Pending)
  - **Red**: Less than 7 days or overdue (Urgent/Overdue)
- ✅ Updated daily in database
- ✅ Real-time display in table

### 6. Daily Email Notifications
- ✅ **Automatic daily updates** on days remaining
- ✅ **Notifications sent when**:
  - Days remaining <= 30 days
  - Daily until deadline
- ✅ **Recipients**:
  - Responsible person (if assigned)
  - All project staff from projects.php:
    - Program Manager
    - Executive Director
    - Finance Head
    - Project Coordinator
    - Operations Manager
    - MEAL Manager
    - Project Owner
- ✅ **Email includes**:
  - Opportunity code and title
  - Donor name
  - Opportunity type
  - Submission deadline
  - Days remaining
  - Call summary (first 200 characters)
  - Website link

### 7. Enhanced Opportunities Table
- ✅ **New columns**:
  - Opportunity Type
  - Announcement Date
  - Days Remaining (with color coding)
  - Responsible Person (name and position)
- ✅ **Visual indicators**:
  - Color-coded days remaining badges
  - Status badges
  - Go/No-Go decision badges

## 📋 Database Schema Updates

New columns added to `gms_funding_opportunities`:
- `opportunity_type` ENUM('CFP', 'EOI', 'RfP', 'RfQ', 'RFA', 'Other')
- `announcement_date` DATE
- `opportunity_website` VARCHAR(500)
- `call_summary` TEXT
- `responsible_person_name` VARCHAR(255)
- `responsible_person_position` VARCHAR(255)
- `responsible_person_email` VARCHAR(255)
- `last_notification_date` DATE
- `days_remaining` INT

## 🔧 Technical Implementation

### Email Notification System
- `send_opportunity_notification()` - Sends HTML emails
- `check_opportunity_deadlines()` - Checks deadlines daily and sends notifications
- Automatic execution on page load (can be moved to cron job)

### JavaScript Functions
- `toggleResponsiblePersonFields()` - Shows/hides responsible person fields based on Go/No-Go decision
- Auto-initializes on page load

### Form Validation
- Opportunity Type: Required
- Announcement Date: Required
- Call Summary: Required
- Responsible Person fields: Required when "Go" is selected

## 📧 Email Notification Details

### When "Go" Decision is Made
**Subject**: 🎯 Go Decision: Proposal Preparation Assigned - [Opportunity Code]

**Content**:
- Assignment notification
- Opportunity details
- Submission deadline
- Days remaining
- Action required

**Recipients**: Responsible person email

### Daily Deadline Reminders
**Subject**: ⏰ Funding Opportunity Deadline Reminder: [Code] - [X] days remaining

**Content**:
- Opportunity code and title
- Donor name
- Opportunity type
- Submission deadline
- Days remaining
- Call summary
- Website link
- Action reminder

**Recipients**: 
- Responsible person (if assigned)
- All project staff from projects.php

## 🎨 UI Enhancements

- **Responsible Person Section**: Appears when "Go" is selected
- **Days Remaining Display**: Color-coded badges in table
- **Enhanced Table**: More informative columns
- **Form Validation**: Real-time validation

## 🚀 Usage

### Adding an Opportunity
1. Click "➕ Add New Opportunity"
2. Fill in required fields:
   - Opportunity Type (CFP, EOI, etc.)
   - Announcement Date
   - Call Summary
   - Submission Deadline
3. If deciding to "Go":
   - Select "Go" in Go/No-Go Decision
   - Responsible Person fields will appear
   - Fill in Name, Position, and Email
4. Save - Email notification will be sent if "Go" is selected

### Daily Notifications
- System automatically checks opportunities daily
- Sends notifications when days remaining <= 30
- Updates days remaining in database
- Sends to all relevant staff

## ⚠️ Important Notes

1. **Database Migration**: New columns are added automatically on first run
2. **Email Configuration**: Ensure PHP mail() is configured
3. **Daily Checks**: Currently runs on page load; can be moved to cron job
4. **Days Remaining**: Calculated from announcement date to submission deadline
5. **Notifications**: Sent once per day per opportunity (when days <= 30)

## 🔄 Next Steps (Optional)

1. Set up cron job for daily deadline checks
2. Add email templates customization
3. Add notification preferences per user
4. Add opportunity-to-project linking

---

**Version**: 2.1 - Opportunities Module Enhanced
**Date**: December 2025
**Status**: ✅ Fully Functional

