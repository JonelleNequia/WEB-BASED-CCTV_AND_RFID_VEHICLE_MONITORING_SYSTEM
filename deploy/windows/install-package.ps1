# Explicit offline ZIP entry point, also invoked by the Inno bootstrapper.
param([Parameter(Mandatory = $true)][string]$Payload, [string]$Root = 'C:\PHILCST-VMS',
 [switch]$AllowDevelopmentCandidate, [string]$ExpectedPublisherThumbprint = '', [string]$NetworkConfig = '')
$ErrorActionPreference = 'Stop'
. (Join-Path $PSScriptRoot 'lib\common.ps1')
try {
    if (Test-VmsLifecycleEnabled (Get-VmsPaths $Root)) { throw 'D5A reinstall/update requires the later certified lifecycle path. Existing installation retained.' }
    Assert-VmsInstallPreflight -Root $Root -Payload $Payload -AllowDevelopmentCandidate:$AllowDevelopmentCandidate -ExpectedPublisherThumbprint $ExpectedPublisherThumbprint | Out-Null
    # D4 name/mode conflicts fail before copying files or provisioning a database.
    $netScript=Join-Path $Payload 'app\deploy\network\server.py'
    $preflightCode='import sys,json;from pathlib import Path;sys.path.insert(0,sys.argv[1]);import server;print(json.dumps(server.preflight_configuration(server.layout(Path(sys.argv[2]),windows=True),json.loads(Path(sys.argv[3]).read_text()) if sys.argv[3] else None)))'
    Invoke-VmsNative -Exe (Join-Path $Payload 'runtime\python\python.exe') -Arguments @('-B','-c',$preflightCode,(Split-Path -Parent $netScript),$Root,$NetworkConfig) -Quiet | Out-Null
    $paths = Get-VmsPaths $Root
    if (Test-Path $paths.Artisan) {
        foreach ($taskName in $script:VmsTaskNames) {
            if (Get-ScheduledTask -TaskName $taskName -ErrorAction SilentlyContinue) { Disable-ScheduledTask -TaskName $taskName | Out-Null }
        }
        Stop-VmsServices $paths
    }
    # Copy only verified program files. Incoming package never contains installation data.
    $manifest = Get-Content (Join-Path $Payload 'CONTENT-MANIFEST.json') -Raw | ConvertFrom-Json
    foreach ($entry in $manifest.files.PSObject.Properties) {
        $destination = Join-Path $Root $entry.Name.Replace('/', '\')
        New-Item -ItemType Directory -Force -Path (Split-Path -Parent $destination) | Out-Null
        Copy-Item -LiteralPath (Join-Path $Payload $entry.Name.Replace('/', '\')) -Destination $destination -Force
    }
    Copy-Item (Join-Path $Payload 'CONTENT-MANIFEST.json') $Root -Force
    if (Test-Path (Join-Path $Payload 'CONTENT-SIGNATURE.json')) { Copy-Item (Join-Path $Payload 'CONTENT-SIGNATURE.json') $Root -Force }
    $arguments = @('-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', (Join-Path $Root 'app\deploy\windows\install.ps1'), '-Root', $Root)
    if ($AllowDevelopmentCandidate) { $arguments += '-AllowDevelopmentCandidate' }
    if ($ExpectedPublisherThumbprint) { $arguments += @('-ExpectedPublisherThumbprint', $ExpectedPublisherThumbprint) }
    if ($NetworkConfig) { $arguments += @('-NetworkConfig', [IO.Path]::GetFullPath($NetworkConfig)) }
    & powershell.exe @arguments
    if ($LASTEXITCODE -ne 0) { throw 'Installation did not complete. Services may remain stopped; data is retained. D5 rollback is not certified. Review logs and rerun the same verified package.' }
} catch { Write-Error $_.Exception.Message; exit 1 }
