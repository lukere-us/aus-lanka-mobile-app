@echo off
REM First-time setup and start of the admin website on Windows.
REM Run from anywhere:  portal\scripts\setup-windows.bat
chcp 65001 >nul
setlocal
cd /d "%~dp0.."

where node >nul 2>nul
if errorlevel 1 (
  echo Node.js is not installed. Install the LTS version from https://nodejs.org and run this again.
  exit /b 1
)
for /f "delims=" %%v in ('node -v') do set NODE_VERSION=%%v
echo Node.js %NODE_VERSION%

if not exist ".env.local" (
  copy ".env.example" ".env.local" >nul
  echo Created portal\.env.local from .env.example
)

echo.
echo Installing packages (first time takes a minute or two)...
call npm install
if errorlevel 1 (
  echo npm install failed. See the messages above.
  exit /b 1
)

echo.
echo Checking the code...
call npm run typecheck
if errorlevel 1 (
  echo Type check found problems. Send me the messages above.
  exit /b 1
)

echo.
echo Starting the website at http://localhost:3000
echo Keep the Laravel API running too:  cd backend  then  php artisan serve
echo Press Ctrl+C to stop.
call npm run dev
