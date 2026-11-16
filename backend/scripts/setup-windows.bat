@echo off
REM One-time local setup on Windows + XAMPP. Run from the backend folder: scripts\setup-windows.bat
setlocal
set MYSQL=C:\xampp\mysql\bin\mysql.exe
if not exist "%MYSQL%" (echo MySQL client not found at %MYSQL% & exit /b 1)

echo Creating databases...
"%MYSQL%" -u root -e "CREATE DATABASE IF NOT EXISTS entertainment_marketplace CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; CREATE DATABASE IF NOT EXISTS entertainment_marketplace_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;" || exit /b 1

echo Installing PHP dependencies...
call composer install --no-interaction || exit /b 1

if not exist .env copy .env.example .env
php artisan key:generate --ansi || exit /b 1
php artisan migrate --seed --force || exit /b 1

echo Running tests...
php artisan test
endlocal
