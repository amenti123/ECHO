# How to Replace users.php and profile.php Files

## 📦 Files Included
- `pages/users.php` - Updated user management file
- `profile.php` - Updated profile page file

## 🔧 Step-by-Step Replacement Instructions

### Step 1: Backup Your Current Files
**IMPORTANT:** Always backup before replacing!

1. Navigate to your project folder:
   ```
   C:\xampp\htdocs\health_reporting_system
   ```

2. Backup the current files:
   - Copy `pages\users.php` to `pages\users.php.backup`
   - Copy `profile.php` to `profile.php.backup`

   **OR** use these commands in PowerShell (run from project folder):
   ```powershell
   copy pages\users.php pages\users.php.backup
   copy profile.php profile.php.backup
   ```

### Step 2: Extract the Zip File
1. Extract `users_profile_files.zip` to a temporary folder
2. You will see:
   - `pages\users.php`
   - `profile.php`

### Step 3: Replace the Files
1. Copy `pages\users.php` from the extracted zip
2. Paste it to: `C:\xampp\htdocs\health_reporting_system\pages\users.php`
   - **Replace** the existing file when prompted

3. Copy `profile.php` from the extracted zip
4. Paste it to: `C:\xampp\htdocs\health_reporting_system\profile.php`
   - **Replace** the existing file when prompted

### Step 4: Verify File Permissions
Make sure the files are readable and writable:
- Right-click each file → Properties
- Ensure "Read" and "Write" are checked (if applicable)

### Step 5: Test the Installation
1. Open your browser
2. Go to: `http://localhost/health_reporting_system/pages/users.php`
3. Verify:
   - ✅ Page loads without errors
   - ✅ Photo upload works
   - ✅ Edit functionality works
   - ✅ Export buttons are visible

4. Go to: `http://localhost/health_reporting_system/profile.php`
5. Verify:
   - ✅ Profile page loads
   - ✅ Photo upload works
   - ✅ Download link is visible
   - ✅ Export works

## 🎯 Quick Replacement (PowerShell Method)

If you prefer using PowerShell, run these commands from the project root:

```powershell
# Navigate to project folder
cd C:\xampp\htdocs\health_reporting_system

# Backup current files
copy pages\users.php pages\users.php.backup
copy profile.php profile.php.backup

# Extract and replace (assuming zip is in current folder)
Expand-Archive -Path users_profile_files.zip -DestinationPath temp_extract -Force
copy temp_extract\pages\users.php pages\users.php -Force
copy temp_extract\profile.php profile.php -Force
Remove-Item temp_extract -Recurse -Force

Write-Host "Files replaced successfully!" -ForegroundColor Green
```

## ✅ Verification Checklist

After replacement, check:
- [ ] No PHP errors appear
- [ ] Users page loads correctly
- [ ] Profile page loads correctly
- [ ] Photo upload works
- [ ] Edit functionality works
- [ ] Export/Download buttons work
- [ ] Download link works

## 🐛 Troubleshooting

### Issue: "Permission Denied"
- **Solution**: Right-click file → Properties → Uncheck "Read-only" → Apply

### Issue: "File not found"
- **Solution**: Verify you're in the correct directory and file paths are correct

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

**Original Files:**
- `C:\xampp\htdocs\health_reporting_system\pages\users.php`
- `C:\xampp\htdocs\health_reporting_system\profile.php`

**Backup Files (after backup):**
- `C:\xampp\htdocs\health_reporting_system\pages\users.php.backup`
- `C:\xampp\htdocs\health_reporting_system\profile.php.backup`

## 🔄 Rollback Instructions

If something goes wrong, restore from backup:

```powershell
copy pages\users.php.backup pages\users.php -Force
copy profile.php.backup profile.php -Force
```

## 📞 Need Help?

If you encounter issues:
1. Check PHP error logs in: `C:\xampp\php\logs\`
2. Check Apache error logs in: `C:\xampp\apache\logs\`
3. Verify file permissions
4. Ensure XAMPP is running

---

**Note**: Always backup before replacing files!

