<#
CI check of the real installer on a clean Windows runner (windows-kit.yml):
install silently, check the result, sign in, install AGAIN over it (the
path a technician takes when fixing something), check again, uninstall
with the data, check nothing is left. Prints the install log on failure.
    pwsh build/windows/ci/install-check.ps1 -Setup <PHILCST-VMS-Setup-*.exe>
#>
param([Parameter(Mandatory = $true)][string]$Setup, [string]$Dir = 'C:\PHILCST-VMS')
$ErrorActionPreference = 'Stop'

function Show-InstallLog {
    $log = Get-ChildItem (Join-Path $Dir 'logs') -Filter 'install-*.log' -ErrorAction SilentlyContinue | Sort-Object LastWriteTime | Select-Object -Last 1
    if ($log) { Write-Host "----- $($log.Name)"; Get-Content $log.FullName | Write-Host }
    foreach ($name in 'web.log', 'php-1.log') {
        $file = Join-Path (Join-Path $Dir 'logs') $name
        if (Test-Path $file) { Write-Host "----- $name"; Get-Content $file -Tail 30 | Write-Host }
    }
}

function Install-Once([int]$Round) {
    Write-Host "===== install, round $Round"
    $process = Start-Process -FilePath $Setup -ArgumentList '/VERYSILENT', '/SUPPRESSMSGBOXES', '/NORESTART', "/DIR=$Dir", '/TASKS=', "/LOG=$env:RUNNER_TEMP\setup-$Round.log" -Wait -PassThru
    Write-Host "Setup exit code: $($process.ExitCode)"
    $info = Get-Content (Join-Path $Dir 'config\install.json') -Raw | ConvertFrom-Json
    Get-Content (Join-Path $Dir 'config\install-result.txt') | Where-Object { $_ -notmatch 'Password:' } | Write-Host
    if ($info.status -ne 'ok') { Show-InstallLog; throw "Install round $Round failed: $($info.error)" }
    $base = "http://127.0.0.1:$($info.web_port)"
    $up = Invoke-WebRequest "$base/up" -UseBasicParsing
    if ($up.StatusCode -ne 200) { throw "/up answered $($up.StatusCode)" }
    Get-Service 'PHILCST-*' | Format-Table Name, Status -AutoSize | Out-String | Write-Host
    return $info
}

$info = Install-Once 1

# Sign in with the first admin the installer made.
$password = (Select-String -Path (Join-Path $Dir 'config\first-admin.txt') -Pattern 'Password: (.+)').Matches[0].Groups[1].Value.Trim()
$base = "http://127.0.0.1:$($info.web_port)"
$login = Invoke-WebRequest "$base/login" -SessionVariable session -UseBasicParsing
$token = [regex]::Match($login.Content, 'name="_token" value="([^"]+)"').Groups[1].Value
$after = Invoke-WebRequest "$base/login" -Method Post -WebSession $session -UseBasicParsing -Body @{ _token = $token; email = $info.admin_email; password = $password }
if ($after.Content -match 'name="password"') { throw 'Signing in with the first admin did not work.' }
$proxy = Invoke-WebRequest "$base/live/proxy-auth" -WebSession $session -UseBasicParsing
Write-Host "Signed in as $($info.admin_email); /live/proxy-auth answered $($proxy.StatusCode)."

# The detector and device service run without a camera (no crash, a clear status).
Start-Sleep -Seconds 45
& powershell.exe -NoProfile -ExecutionPolicy Bypass -File (Join-Path $Dir 'app\deploy\windows\status.ps1')
foreach ($name in 'PHILCST-Detector', 'PHILCST-Devices', 'PHILCST-LiveView', 'PHILCST-Web', 'PHILCST-PHP1') {
    if ((Get-Service $name).Status -ne 'Running') { Show-InstallLog; throw "$name is not running." }
}

Install-Once 2 | Out-Null

Write-Host '===== uninstall (with the data)'
$uninstaller = Get-ChildItem (Join-Path $Dir 'uninstall') -Filter 'unins*.exe' | Select-Object -First 1
Start-Process -FilePath $uninstaller.FullName -ArgumentList '/VERYSILENT', '/SUPPRESSMSGBOXES', '/REMOVEDATA=yes' -Wait
Start-Sleep -Seconds 5
if (Get-Service 'PHILCST-*' -ErrorAction SilentlyContinue) { throw 'Services are left after uninstalling.' }
if (Get-ScheduledTask -TaskName 'PHILCST VMS*' -ErrorAction SilentlyContinue) { throw 'Scheduled tasks are left after uninstalling.' }
if (Get-NetFirewallRule -Group 'PHILCST VMS' -ErrorAction SilentlyContinue) { throw 'Firewall rules are left after uninstalling.' }
Write-Host 'Install, sign-in, reinstall and uninstall: OK'
