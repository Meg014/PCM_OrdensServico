@echo off
setlocal
set "PCM_NC_PHP=%~1"
if not defined PCM_NC_PHP set "PCM_NC_PHP=C:\xampp\php\php.exe"
if not exist "%PCM_NC_PHP%" exit /b 1
pushd "%~dp0..\.." || exit /b 1
if not exist "bin\cake.php" (
    popd
    exit /b 1
)
"%PCM_NC_PHP%" "bin\cake.php" nonconformity_alerts
set "PCM_NC_EXIT=%ERRORLEVEL%"
popd
exit /b %PCM_NC_EXIT%
