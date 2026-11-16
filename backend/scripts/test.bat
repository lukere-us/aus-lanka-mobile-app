@echo off
REM Runs the test suite and logs it to storage\logs\test-run.log. Double-click or run scripts\test.bat.
setlocal
cd /d "%~dp0.."
set "PHP=php"
where php >nul 2>nul || set "PHP=C:\xampp\php\php.exe"
"%PHP%" artisan config:clear > storage\logs\test-run.log 2>&1
"%PHP%" artisan test --colors=never >> storage\logs\test-run.log 2>&1
set RC=%ERRORLEVEL%
echo Exit code %RC% >> storage\logs\test-run.log
type storage\logs\test-run.log
pause
exit /b %RC%
