@echo off
REM Health Reporting System - Fixes Application Script
REM This script helps you apply the fixes to your system

echo ========================================
echo Health Reporting System - Fixes Installer
echo ========================================
echo.

set "TARGET_DIR=C:\xampp\htdocs\health_reporting_system"
set "BACKUP_DIR=%TARGET_DIR%\backup_%date:~-4,4%%date:~-7,2%%date:~-10,2%_%time:~0,2%%time:~3,2%%time:~6,2%"
set "BACKUP_DIR=%BACKUP_DIR: =0%"

echo Target Directory: %TARGET_DIR%
echo Backup Directory: %BACKUP_DIR%
echo.

REM Check if target directory exists
if not exist "%TARGET_DIR%" (
    echo ERROR: Target directory does not exist!
    echo Please update TARGET_DIR in this script to match your installation.
    pause
    exit /b 1
)

REM Create backup
echo Creating backup...
mkdir "%BACKUP_DIR%" 2>nul
mkdir "%BACKUP_DIR%\pages" 2>nul

if exist "%TARGET_DIR%\pages\projects.php" (
    copy "%TARGET_DIR%\pages\projects.php" "%BACKUP_DIR%\pages\" >nul
    echo [OK] Backed up projects.php
)

if exist "%TARGET_DIR%\pages\planning.php" (
    copy "%TARGET_DIR%\pages\planning.php" "%BACKUP_DIR%\pages\" >nul
    echo [OK] Backed up planning.php
)

if exist "%TARGET_DIR%\pages\custom_report.php" (
    copy "%TARGET_DIR%\pages\custom_report.php" "%BACKUP_DIR%\pages\" >nul
    echo [OK] Backed up custom_report.php
)

if exist "%TARGET_DIR%\pages\enter_data.php" (
    copy "%TARGET_DIR%\pages\enter_data.php" "%BACKUP_DIR%\pages\" >nul
    echo [OK] Backed up enter_data.php
)

if exist "%TARGET_DIR%\pages\cfm.php" (
    copy "%TARGET_DIR%\pages\cfm.php" "%BACKUP_DIR%\pages\" >nul
    echo [OK] Backed up cfm.php
)

if exist "%TARGET_DIR%\pages\geography.php" (
    copy "%TARGET_DIR%\pages\geography.php" "%BACKUP_DIR%\pages\" >nul
    echo [OK] Backed up geography.php
)

echo.
echo Backup created successfully!
echo.

REM Apply fixes
echo Applying fixes...
echo.

if exist "pages\projects.php" (
    copy "pages\projects.php" "%TARGET_DIR%\pages\" /Y >nul
    echo [OK] Applied projects.php
) else (
    echo [WARNING] pages\projects.php not found in current directory
)

if exist "pages\planning.php" (
    copy "pages\planning.php" "%TARGET_DIR%\pages\" /Y >nul
    echo [OK] Applied planning.php
) else (
    echo [WARNING] pages\planning.php not found in current directory
)

if exist "pages\custom_report.php" (
    copy "pages\custom_report.php" "%TARGET_DIR%\pages\" /Y >nul
    echo [OK] Applied custom_report.php
) else (
    echo [WARNING] pages\custom_report.php not found in current directory
)

if exist "pages\enter_data.php" (
    copy "pages\enter_data.php" "%TARGET_DIR%\pages\" /Y >nul
    echo [OK] Applied enter_data.php
) else (
    echo [WARNING] pages\enter_data.php not found in current directory
)

if exist "pages\cfm.php" (
    copy "pages\cfm.php" "%TARGET_DIR%\pages\" /Y >nul
    echo [OK] Applied cfm.php
) else (
    echo [WARNING] pages\cfm.php not found in current directory
)

if exist "pages\geography.php" (
    copy "pages\geography.php" "%TARGET_DIR%\pages\" /Y >nul
    echo [OK] Applied geography.php
) else (
    echo [WARNING] pages\geography.php not found in current directory
)

echo.
echo ========================================
echo Fixes applied successfully!
echo ========================================
echo.
echo Backup location: %BACKUP_DIR%
echo.
echo Please test your application and verify all fixes are working.
echo.
pause

