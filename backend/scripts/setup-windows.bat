@echo off
REM One-time local setup on Windows + XAMPP. Double-click it, or run scripts\setup-windows.bat.
REM Everything is logged to storage\logs\setup-run.log so problems can be diagnosed afterwards.
setlocal
cd /d "%~dp0.."
set "LOG=%CD%\storage\logs\setup-run.log"
echo Setup started %DATE% %TIME% in %CD% > "%LOG%"
call :main >> "%LOG%" 2>&1
set RC=%ERRORLEVEL%
echo Exit code %RC% >> "%LOG%"
type "%LOG%"
echo.
if "%RC%"=="0" (echo SETUP FINISHED OK) else (echo SETUP FAILED with code %RC% - the log is in storage\logs\setup-run.log)
pause
exit /b %RC%

:main
set "PHP=php"
where php >nul 2>nul || set "PHP=C:\xampp\php\php.exe"
echo == PHP ==
"%PHP%" -v || (echo PHP not found on PATH or at C:\xampp\php\php.exe & exit /b 10)
"%PHP%" -m

set "MYSQL=C:\xampp\mysql\bin\mysql.exe"
if not exist "%MYSQL%" (echo MySQL client not found at %MYSQL% & exit /b 11)
echo == MySQL ==
"%MYSQL%" -u root -e "SELECT VERSION();" || (echo MySQL is not running - start it in the XAMPP Control Panel & exit /b 12)
"%MYSQL%" -u root -e "CREATE DATABASE IF NOT EXISTS entertainment_marketplace CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; CREATE DATABASE IF NOT EXISTS entertainment_marketplace_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;" || exit /b 13

echo == Composer ==
where composer >nul 2>nul
if errorlevel 1 (
  if exist composer.phar (
    call "%PHP%" composer.phar install --no-interaction --no-progress || exit /b 14
  ) else (
    echo Composer not found. Install it from https://getcomposer.org/Composer-Setup.exe and run this again.
    exit /b 15
  )
) else (
  call composer --version
  call composer install --no-interaction --no-progress || exit /b 14
)

echo == Laravel ==
if not exist .env copy .env.example .env
"%PHP%" artisan key:generate --ansi || exit /b 16
"%PHP%" artisan migrate --seed --force || exit /b 17

echo == Tests ==
"%PHP%" artisan test || exit /b 20
exit /b 0
