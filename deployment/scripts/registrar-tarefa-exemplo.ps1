param(
    [Parameter(Mandatory = $true)]
    [string]$ProjectPath,
    [Parameter(Mandatory = $true)]
    [string]$PhpPath,
    [Parameter(Mandatory = $true)]
    [string]$TaskUser
)

$action = New-ScheduledTaskAction `
    -Execute $PhpPath `
    -Argument 'bin\cake.php import_pending_reports' `
    -WorkingDirectory $ProjectPath
$trigger = New-ScheduledTaskTrigger -Once -At (Get-Date).Date -RepetitionInterval (New-TimeSpan -Minutes 5)
$settings = New-ScheduledTaskSettingsSet -StartWhenAvailable -MultipleInstances IgnoreNew

Write-Host 'Exemplo preparado. Revise usuário, senha, caminhos e permissões antes de registrar.'
Write-Host "Register-ScheduledTask -TaskName 'PCM - Importar relatórios' -Action `$action -Trigger `$trigger -Settings `$settings -User '$TaskUser' -Password '<SOLICITAR_COM_SEGURANCA>'"
