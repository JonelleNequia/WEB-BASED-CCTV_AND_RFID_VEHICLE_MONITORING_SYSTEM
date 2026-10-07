<#
PHILCST VMS - a Chrome kiosk shortcut (full screen, no address bar) for one
gate's kiosk page, on the Desktop. Copy it to the gate PC (Chrome installed
there) or tick "open at sign-in" to put it in that user's Startup folder.
    gate-kiosk-shortcut.bat
    gate-kiosk-shortcut.ps1 -Gate gate-1 [-Server 192.168.1.10] [-Startup]
The first time, sign in once on the kiosk (a guard account); it stays signed in.
#>
param([string]$Gate = '', [string]$Server = '', [switch]$Startup)
$ErrorActionPreference = 'Stop'
. (Join-Path $PSScriptRoot 'lib\common.ps1')
$paths = Get-VmsPaths (Get-VmsRoot)
$port = Get-VmsWebPort $paths

$gates = @((Invoke-VmsArtisan $paths @('gates:list', '--json') -Quiet | Select-Object -Last 1) | ConvertFrom-Json)
if ($gates.Count -eq 0) { throw 'No gates yet: add them in Settings > Gates first.' }
if (-not $Gate) {
    for ($i = 0; $i -lt $gates.Count; $i++) { Write-Host ("  {0}. {1} ({2})" -f ($i + 1), $gates[$i].name, $gates[$i].code) }
    $choice = [int](Read-Host 'Which gate? (number)')
    if ($choice -lt 1 -or $choice -gt $gates.Count) { throw 'No such gate.' }
    $Gate = $gates[$choice - 1].code
}
$selected = $gates | Where-Object { $_.code -eq $Gate } | Select-Object -First 1
if (-not $selected) { throw "No gate '$Gate'." }

if (-not $Server) {
    $suggested = @(Get-VmsLanAddresses) | Select-Object -First 1
    if (-not $suggested) { $suggested = $env:COMPUTERNAME.ToLowerInvariant() + '.local' }
    $typed = Read-Host "Address of this server as the gate PC reaches it [$suggested]"
    $Server = if ($typed) { $typed } else { $suggested }
}
$url = (Format-VmsUrl $Server $port).TrimEnd('/') + $selected.kiosk_path

$chrome = @(
    (Join-VmsPath $env:ProgramFiles 'Google' 'Chrome' 'Application' 'chrome.exe'),
    (Join-VmsPath ${env:ProgramFiles(x86)} 'Google' 'Chrome' 'Application' 'chrome.exe'),
    (Join-VmsPath $env:LOCALAPPDATA 'Google' 'Chrome' 'Application' 'chrome.exe')
) | Where-Object { Test-Path -LiteralPath $_ } | Select-Object -First 1
if (-not $chrome) { $chrome = 'C:\Program Files\Google\Chrome\Application\chrome.exe'; Write-Host 'Google Chrome was not found on this PC; the shortcut uses its usual folder.' -ForegroundColor Yellow }

$folder = if ($Startup) { [Environment]::GetFolderPath('Startup') } else { [Environment]::GetFolderPath('Desktop') }
$shortcutPath = Join-VmsPath $folder ("PHILCST Kiosk - " + ($selected.name -replace '[\\/:*?"<>|]', '-') + '.lnk')
$shell = New-Object -ComObject WScript.Shell
$shortcut = $shell.CreateShortcut($shortcutPath)
$shortcut.TargetPath = $chrome
# Its own Chrome profile per gate: the kiosk keeps its sign-in apart from normal browsing.
$shortcut.Arguments = "--kiosk `"$url`" --user-data-dir=`"C:\PHILCST-Kiosk\$($selected.code)`" --no-first-run --disable-session-crashed-bubble --overscroll-history-navigation=0"
$shortcut.Description = "PHILCST VMS kiosk - $($selected.name)"
$shortcut.Save()
Write-VmsStep "Shortcut saved: $shortcutPath"
Write-Host "Kiosk page: $url"
Write-Host 'Close the kiosk with Alt+F4.'
