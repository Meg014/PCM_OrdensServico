@echo off
setlocal
if "%~1"=="" goto usage
if "%~2"=="" goto usage
cd /d "%~1" || exit /b 1
if not exist "bin\cake.php" exit /b 1
if not exist "logs" mkdir "logs"
"%~2" bin\cake.php import_pending_reports >> logs\agendador-importacao.log 2>&1
exit /b %errorlevel%

:usage
echo Uso: importar-relatorios.cmd "RAIZ_DO_PROJETO" "CAMINHO_DO_PHP.EXE"
exit /b 1
