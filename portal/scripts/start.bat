@echo off
REM Start the admin website after the first setup:  portal\scripts\start.bat
chcp 65001 >nul
cd /d "%~dp0.."
echo Website: http://localhost:3000   (the Laravel API must be running: cd backend, php artisan serve)
call npm run dev
