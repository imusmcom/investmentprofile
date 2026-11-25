@echo off
rem Start the portfolio tracker. Usage: start-windows.bat [port]   (default 8000)
setlocal
set "HERE=%~dp0"
set "PORT=%~1"
if "%PORT%"=="" set "PORT=8000"

if defined PHP_HOME (
  set "PHP_BIN=%PHP_HOME%\php.exe"
) else if exist "%HERE%..\.tools\php\php.exe" (
  set "PHP_BIN=%HERE%..\.tools\php\php.exe"
) else (
  set "PHP_BIN=php"
)

echo Starting portfolio tracker on http://localhost:%PORT%
rem The two upload caps are for the settings page's restore-a-backup form: php.ini defaults to 2 MB,
rem which a year of daily quotes outgrows. Raise them here or edit php.ini, whichever you prefer.
"%PHP_BIN%" -d upload_max_filesize=64M -d post_max_size=64M -S localhost:%PORT% -t "%HERE%public"
