param([string]$Root = '', [switch]$Inference, [switch]$VerifyContent, [switch]$PackageOnly, [switch]$Json)
$ErrorActionPreference = 'Stop'
. (Join-Path $PSScriptRoot 'lib\common.ps1')
if (-not $Root) { $Root = Get-VmsRoot }
$paths = Get-VmsPaths $Root
$result = [ordered]@{ application_ready = $false; source = $null; version = $null; mode = 'local-only'; device_readiness = 'not checked; independent of application readiness'; checks = @(); errors = @() }
try {
    $info = Get-Content -LiteralPath $paths.BuildInfo -Raw | ConvertFrom-Json
    $result.source = $info.source.source_sha256; $result.version = $info.version
    if ($VerifyContent) { Test-VmsPayload -Payload $Root -Installed -AllowDevelopmentCandidate | Out-Null; $result.checks += 'Content hashes passed' }
    $ini = $paths.PhpIni
    $temporary = $null
    if ($PackageOnly) {
        $temporary = Join-Path ([IO.Path]::GetTempPath()) ('philcst-php-' + [guid]::NewGuid().ToString('N') + '.ini')
        $text = Expand-VmsTemplate -Text (Get-Content (Join-Path $Root 'config/php.ini.template') -Raw) -Values @{ INSTALL_DIR = $Root }
        [IO.File]::WriteAllText($temporary, $text)
        $ini = $temporary
    }
    try {
        $arguments = @('-c', $ini, (Join-Path $Root 'checks/php-readiness.php'))
        if (-not $PackageOnly) { $arguments += $paths.Database }
        $check = Start-VmsProcess -Exe $paths.Php -Arguments $arguments
        if ($check.ExitCode -ne 0) { throw ('PHP/SQLite/Argon2id readiness failed: ' + (Hide-VmsSecrets (($check.Output | Select-Object -Last 2) -join ' '))) }
        $result.checks += ($check.Output -join ' ')
    } finally { if ($temporary) { Remove-Item -LiteralPath $temporary -Force -ErrorAction SilentlyContinue } }
    $arguments = @('-B', (Join-Path $Root 'checks/inference.py'), $Root)
    if (-not $Inference) { $arguments += '--imports-only' }
    $check = Start-VmsProcess -Exe $paths.Python -Arguments $arguments -TimeoutSeconds 300
    if ($check.ExitCode -ne 0) { throw ('Python CPU/OCR readiness failed; no download attempted: ' + (Hide-VmsSecrets (($check.Output | Select-Object -Last 2) -join ' '))) }
    $result.checks += ($check.Output -join ' ')
    if (-not $PackageOnly) {
        foreach ($directory in $paths.Storage, $paths.AppLogs, (Split-Path -Parent $paths.Database)) {
            $probe = Join-Path $directory ('.readiness-' + [guid]::NewGuid().ToString('N'))
            try { [IO.File]::WriteAllText($probe, 'isolated write probe'); $result.checks += 'Writable directory checked' } finally { Remove-Item -LiteralPath $probe -Force -ErrorAction SilentlyContinue }
        }
    }
    $result.application_ready = $true
} catch { $result.errors += $_.Exception.Message }
if ($Json) { $result | ConvertTo-Json -Depth 5 } else { $result | ConvertTo-Json -Depth 5 | Write-Host }
if (-not $result.application_ready) { exit 1 }
