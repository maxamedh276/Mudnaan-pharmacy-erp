@echo off
setlocal
cd /d "%~dp0"
if not exist ".tools\php84\php.exe" (
    echo PHP runtime missing. Install PHP 8.2 or newer to run the pharmacy.
    pause
    exit /b 1
)
echo Mudnaan Pharmacy: http://127.0.0.1:8085
echo Keep this window open while using the pharmacy. Press Ctrl+C to stop.
".tools\php84\php.exe" -S 127.0.0.1:8085 tools/dev_router.php
pause
