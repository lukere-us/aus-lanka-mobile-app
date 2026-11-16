@echo off
chcp 65001 >nul
REM After pulling new code: install packages, migrate the dev database, re-sync roles and currencies, run tests.
REM Logs to storage\logs\update-run.log. Double-click or run scripts\update.bat.
setlocal
cd /d "%~dp0.."
set "LOG=%CD%\storage\logs\update-run.log"
echo Update started %DATE% %TIME% > "%LOG%"
call :main >> "%LOG%" 2>&1
set RC=%ERRORLEVEL%
echo Exit code %RC% >> "%LOG%"
type "%LOG%"
pause
exit /b %RC%

:main
set "PHP=php"
where php >nul 2>nul || set "PHP=C:\xampp\php\php.exe"
set "COMPOSER_CMD=composer"
if exist "%ProgramData%\ComposerSetup\bin\composer.bat" set "COMPOSER_CMD=%ProgramData%\ComposerSetup\bin\composer.bat"
call "%COMPOSER_CMD%" install --no-interaction --no-progress || exit /b 14
"%PHP%" artisan config:clear || exit /b 16
"%PHP%" artisan migrate --force || exit /b 17
"%PHP%" artisan db:seed --class=Database\Seeders\ReferenceDataSeeder --force || exit /b 18
"%PHP%" artisan test --colors=never || exit /b 20
exit /b 0
