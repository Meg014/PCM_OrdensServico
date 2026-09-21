$ErrorActionPreference = 'Stop'
$pcmProjectPath = Split-Path -Parent $PSScriptRoot
$pcmSecret = $null
try {
    $env:PCM_ADMIN_NAME = Read-Host 'Nome do primeiro administrador'
    $env:PCM_ADMIN_EMAIL = Read-Host 'E-mail'
    $pcmSecret = Read-Host 'Senha (12 a 72 caracteres; maximo 72 bytes)' -AsSecureString
    $env:PCM_ADMIN_PASSWORD = [System.Net.NetworkCredential]::new('', $pcmSecret).Password
    & php (Join-Path $PSScriptRoot 'cake.php') create_admin
    if ($LASTEXITCODE -ne 0) { throw 'O administrador nao foi criado. Confira a mensagem acima.' }
} finally {
    Remove-Item Env:PCM_ADMIN_NAME,Env:PCM_ADMIN_EMAIL,Env:PCM_ADMIN_PASSWORD -ErrorAction SilentlyContinue
    if ($null -ne $pcmSecret) { $pcmSecret.Dispose() }
}
