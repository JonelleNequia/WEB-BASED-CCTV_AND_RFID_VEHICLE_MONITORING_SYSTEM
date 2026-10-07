<#
PHILCST VMS - install a newer release ZIP (PHILCST-VMS-<version>-win64.zip)
and keep the data.

    update.bat                    (pick the release ZIP)
    update.ps1 -Zip D:\PHILCST-VMS-2026.10.20-abc1234-win64.zip [-Yes]

1. Backup (backup:run --label=before-update).
2. Unpack the ZIP next to the install, stop the services.
3. app\ and runtime\ become app.previous\ and runtime.previous\; the new
   ones take their place; .env (APP_KEY), the database and storage\ move into
   the new app.
4. install.ps1 of the new release: migrations, caches, services, checks
   that the web answers.
5. If anything fails: the previous app and runtime come back, the database
   is restored from the backup of step 1, and the services start again.
The script runs from a copy in %TEMP% because app\ is replaced.
#>
param([string]$Zip = '', [string]$Root = '', [switch]$Yes, [switch]$FromTemp)
$ErrorActionPreference = 'Stop'
. (Join-Path $PSScriptRoot 'lib\common.ps1')
if (-not $Root) { $Root = Get-VmsRoot }
$forward = @('-Root', $Root); if ($Zip) { $forward += @('-Zip', $Zip) }; if ($Yes) { $forward += '-Yes' }
Assert-VmsAdmin -ScriptPath $PSCommandPath -Arguments $forward

if (-not $FromTemp) {
    $copy = Join-VmsPath ([IO.Path]::GetTempPath()) ('philcst-update-' + [guid]::NewGuid().ToString('N'))
    New-Item -ItemType Directory -Force -Path $copy | Out-Null
    Copy-Item -Path (Join-VmsPath $PSScriptRoot '*') -Destination $copy -Recurse -Force
    & powershell.exe -NoProfile -ExecutionPolicy Bypass -File (Join-VmsPath $copy 'update.ps1') @forward -FromTemp
    exit $LASTEXITCODE
}

$paths = Get-VmsPaths $Root
$logFile = Join-VmsPath $paths.Logs ('update-' + (Get-Date -Format 'yyyyMMdd-HHmmss') + '.log')
Start-Transcript -LiteralPath $logFile | Out-Null

if (-not $Zip) {
    Add-Type -AssemblyName System.Windows.Forms
    $dialog = New-Object System.Windows.Forms.OpenFileDialog
    $dialog.Title = 'Choose the new PHILCST-VMS release ZIP'
    $dialog.Filter = 'PHILCST-VMS release (PHILCST-VMS-*-win64.zip)|PHILCST-VMS-*-win64.zip|Zip files (*.zip)|*.zip'
    if ($dialog.ShowDialog() -ne [System.Windows.Forms.DialogResult]::OK) { Write-Host 'No update.'; Stop-Transcript | Out-Null; exit 0 }
    $Zip = $dialog.FileName
}

Add-Type -AssemblyName System.IO.Compression.FileSystem
$archive = [IO.Compression.ZipFile]::OpenRead($Zip)
try {
    $names = @($archive.Entries | ForEach-Object { $_.FullName.Replace('\', '/') })
    $infoEntry = $archive.Entries | Where-Object { $_.FullName.Replace('\', '/') -eq 'PHILCST-VMS/BUILD-INFO.json' } | Select-Object -First 1
    if (-not $infoEntry -or $names -notcontains 'PHILCST-VMS/app/artisan') { throw "$Zip is not a PHILCST-VMS release ZIP." }
    $reader = New-Object IO.StreamReader($infoEntry.Open())
    $newInfo = $reader.ReadToEnd() | ConvertFrom-Json
    $reader.Close()
} finally {
    $archive.Dispose()
}
$current = if (Test-Path -LiteralPath $paths.BuildInfo) { (Get-Content -LiteralPath $paths.BuildInfo -Raw | ConvertFrom-Json).version } else { '?' }
Write-Host "Installed: $current    New: $($newInfo.version)"
if (-not $Yes -and (Read-Host 'Type YES to update') -ne 'YES') { Write-Host 'No update.'; Stop-Transcript | Out-Null; exit 0 }

$stamp = Get-Date -Format 'yyyyMMdd-HHmmss'
$staging = Join-VmsPath $Root "update-$stamp"
$previousApp = Join-VmsPath $Root 'app.previous'
$previousRuntime = Join-VmsPath $Root 'runtime.previous'
$backup = $null
$swapped = $false

try {
    Write-VmsStep 'Backup before the update'
    $output = Invoke-VmsArtisan $paths @('backup:run', '--label=before-update')
    $backup = (($output -join "`n") -replace '(?s).*Backup saved: (.+?) \(.*', '$1').Trim()
    if (-not (Test-Path -LiteralPath $backup)) { throw 'The backup before the update was not made; nothing changed.' }

    Write-VmsStep "Unpacking $Zip"
    [IO.Compression.ZipFile]::ExtractToDirectory($Zip, $staging)
    $new = Join-VmsPath $staging 'PHILCST-VMS'

    Stop-VmsServices $paths
    foreach ($old in $previousApp, $previousRuntime) { if (Test-Path -LiteralPath $old) { Remove-Item -LiteralPath $old -Recurse -Force } }
    # Unlink public\storage before moving the app (it points into storage\).
    $link = Join-VmsPath $paths.App 'public' 'storage'
    if (Test-Path -LiteralPath $link) { & cmd.exe /c rmdir "$link" 2>&1 | Out-Null }

    Write-VmsStep 'Replacing the program files (data kept)'
    Move-Item -LiteralPath $paths.App -Destination $previousApp
    Move-Item -LiteralPath $paths.Runtime -Destination $previousRuntime
    $swapped = $true
    Move-Item -LiteralPath (Join-VmsPath $new 'app') -Destination $paths.App
    Move-Item -LiteralPath (Join-VmsPath $new 'runtime') -Destination $paths.Runtime
    Copy-Item -Path (Join-VmsPath $new 'config' '*.template') -Destination $paths.Config -Force
    Copy-Item -LiteralPath (Join-VmsPath $new 'BUILD-INFO.json') -Destination $paths.BuildInfo -Force
    Move-VmsData -From $previousApp -To $paths.App

    Write-VmsStep 'Setting up the new version'
    & powershell.exe -NoProfile -ExecutionPolicy Bypass -File (Join-VmsPath $paths.Scripts 'install.ps1') -Root $Root
    if ($LASTEXITCODE -ne 0) { throw "The new version did not start (see $($paths.Logs))." }

    Remove-Item -LiteralPath $staging -Recurse -Force -ErrorAction SilentlyContinue
    Write-VmsStep "Updated to $($newInfo.version). The previous version is kept in app.previous and runtime.previous."
    Stop-Transcript | Out-Null
    exit 0
} catch {
    Write-Host "Update failed: $($_.Exception.Message)" -ForegroundColor Red
    if ($swapped) {
        Write-VmsStep 'Rolling back to the previous version'
        Stop-VmsServices $paths
        $failedApp = Join-VmsPath $Root "app.failed-$stamp"
        $link = Join-VmsPath $paths.App 'public' 'storage'
        if (Test-Path -LiteralPath $link) { & cmd.exe /c rmdir "$link" 2>&1 | Out-Null }
        if (Test-Path -LiteralPath $paths.App) {
            Move-Item -LiteralPath $paths.App -Destination $failedApp
            Move-VmsData -From $failedApp -To $previousApp
        }
        if (Test-Path -LiteralPath $paths.Runtime) { Move-Item -LiteralPath $paths.Runtime -Destination (Join-VmsPath $Root "runtime.failed-$stamp") }
        Move-Item -LiteralPath $previousApp -Destination $paths.App
        Move-Item -LiteralPath $previousRuntime -Destination $paths.Runtime
        # The new migrations may have changed the database: take it back from the backup.
        if ($backup -and (Test-Path -LiteralPath $backup)) {
            Invoke-VmsArtisan $paths @('backup:restore', $backup, '--force', '--no-safety-backup') | Out-Null
        }
        & powershell.exe -NoProfile -ExecutionPolicy Bypass -File (Join-VmsPath $paths.Scripts 'install.ps1') -Root $Root
        Write-VmsStep 'The previous version is back.'
    }
    Stop-Transcript | Out-Null
    exit 1
}
