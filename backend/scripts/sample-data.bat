@echo off
chcp 65001 >nul
REM Loads sample customers, organizers, events, movies, orders, refunds and a payout into the local database.
REM   scripts\sample-data.bat           add the sample data (does nothing if it is already there)
REM   scripts\sample-data.bat fresh     WIPE the local database first, then load everything again
setlocal
cd /d "%~dp0.."
set "PHP=php"
where php >nul 2>nul || set "PHP=C:\xampp\php\php.exe"

if /i not "%~1"=="fresh" goto load
echo This deletes EVERYTHING in the local database and starts again.
set "ANSWER="
set /p ANSWER=Type YES to continue:
if not "%ANSWER%"=="YES" goto cancelled
"%PHP%" artisan migrate:fresh --seed --force
if errorlevel 1 goto failed

:load
"%PHP%" artisan config:clear >nul
"%PHP%" artisan db:seed --class=SampleDataSeeder --force
if errorlevel 1 goto failed
pause
exit /b 0

:cancelled
echo Cancelled. Nothing was changed.
pause
exit /b 1

:failed
echo Something went wrong. Send Claude a screenshot of the messages above.
pause
exit /b 1
