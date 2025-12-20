# Create zip file with all fixed files
Set-Location 'C:\xampp\htdocs\health_reporting_system'

$files = @(
    'pages\projects.php',
    'pages\planning.php',
    'pages\custom_report.php',
    'pages\enter_data.php',
    'pages\cfm.php',
    'pages\geography.php'
)

# Add optional files if they exist
if (Test-Path 'FIXES_README.md') {
    $files += 'FIXES_README.md'
}
if (Test-Path 'apply_fixes.bat') {
    $files += 'apply_fixes.bat'
}

# Check which files exist
$existing = $files | Where-Object { Test-Path $_ }

if ($existing.Count -eq 0) {
    Write-Host "ERROR: No files found to zip!"
    exit 1
}

# Create zip
Compress-Archive -Path $existing -DestinationPath 'health_reporting_system_fixes.zip' -Force

if (Test-Path 'health_reporting_system_fixes.zip') {
    $zip = Get-Item 'health_reporting_system_fixes.zip'
    Write-Host "SUCCESS! Zip file created at: $($zip.FullName)"
    Write-Host "Size: $([math]::Round($zip.Length/1KB, 2)) KB"
    Write-Host "Files included: $($existing.Count)"
} else {
    Write-Host "ERROR: Zip file not created"
    exit 1
}






