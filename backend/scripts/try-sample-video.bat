@echo off
chcp 65001 >nul
REM Rents the demo film as a demo customer (fake payment, sample video) and opens it in your browser.
REM Local testing only. Needs MySQL running in XAMPP. Close the "API server" window when you are done.
setlocal
cd /d "%~dp0.."
set "PHP=php"
where php >nul 2>nul || set "PHP=C:\xampp\php\php.exe"

"%PHP%" artisan db:seed --class=Database\Seeders\DemoContentSeeder --force || (echo Could not load demo content. & pause & exit /b 1)
start "API server (close when done)" "%PHP%" artisan serve --port=8000
timeout /t 3 >nul

set "LINK="
for /f "usebackq delims=" %%L in (`call "%PHP%" artisan demo:rental-link ^| findstr /C:"/api/v1/dev/player"`) do set "LINK=%%L"
if not defined LINK (
  echo Could not create the demo rental. Details:
  call "%PHP%" artisan demo:rental-link
  pause
  exit /b 1
)
echo Opening the rented film in your browser...
start "" "%LINK%"
echo.
echo Run this again any time for a fresh 10-minute playback link.
pause
