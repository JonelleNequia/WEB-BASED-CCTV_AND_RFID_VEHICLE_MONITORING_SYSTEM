# Client-only enrollment. Never installs/starts PHP, a database or collectors.
param([ValidateSet('Connect','Status','Repair','Remove')][string]$Action='Connect',
 [string]$ServerAddress='',[string]$RootCertificate='',[string]$RootFingerprint='',
 [ValidateSet('Hosts','DNS')][string]$NameResolution='Hosts')
$ErrorActionPreference='Stop'
. (Join-Path $PSScriptRoot 'client-common.ps1')
$stateDir=Join-Path $env:ProgramData 'PHILCST-VMS-Client'
$receiptFile=Join-Path $stateDir 'connection.json'
$hostsFile=Join-Path $env:SystemRoot 'System32\drivers\etc\hosts'
$shortcut=Join-Path ([Environment]::GetFolderPath('CommonDesktopDirectory')) 'Open PHILCST Server.url'
try {
    if ($env:OS -ne 'Windows_NT') { throw 'Native Windows client required.' }
    foreach ($path in @($stateDir,$receiptFile,$shortcut,$hostsFile)) {
        $candidate=$path
        while ($candidate -and $candidate.Length -gt 3) {
            if ((Test-Path -LiteralPath $candidate) -and ((Get-Item -Force -LiteralPath $candidate).Attributes -band [IO.FileAttributes]::ReparsePoint)) { throw 'Enrollment path redirects through a reparse point. Nothing changed.' }
            $candidate=Split-Path -Parent $candidate
        }
    }
    if (Test-Path -LiteralPath $stateDir) {
        foreach ($item in @($stateDir,$receiptFile,(Join-Path $stateDir 'root.crt'))) {
            if (-not (Test-Path -LiteralPath $item)) { continue }
            $acl=Get-Acl -LiteralPath $item
            $owner=$acl.GetOwner([Security.Principal.SecurityIdentifier]).Value
            if ($owner -notin @('S-1-5-32-544','S-1-5-18')) { throw 'Existing enrollment state is not Administrators/SYSTEM-owned. Nothing was trusted or removed.' }
            $writeMask=[Security.AccessControl.FileSystemRights]'WriteData,AppendData,Delete,DeleteSubdirectoriesAndFiles,ChangePermissions,TakeOwnership,WriteAttributes,WriteExtendedAttributes'
            foreach ($rule in $acl.Access) {
                $sid=$rule.IdentityReference.Translate([Security.Principal.SecurityIdentifier]).Value
                if ($rule.AccessControlType -eq 'Allow' -and ($rule.FileSystemRights -band $writeMask) -and $sid -notin @('S-1-5-32-544','S-1-5-18')) { throw 'Existing enrollment receipt is writable by an untrusted principal. Resolve its ACL explicitly.' }
            }
        }
    }
    $old=if (Test-Path $receiptFile) { Get-Content -LiteralPath $receiptFile -Raw | ConvertFrom-Json } else { $null }
    if ($Action -eq 'Status') {
        if (-not $old) { throw 'No helper-owned enrollment; use Connect with the administrator public root and independent fingerprint.' }
        $cert=Read-ClientRoot (Join-Path $stateDir 'root.crt') $old.fingerprint
        Test-ClientHttps $old.address $cert
        $dns=@([Net.Dns]::GetHostAddresses($script:VmsCanonicalHost) | ForEach-Object { $_.ToString() } | Sort-Object -Unique)
        if ($dns.Count -ne 1 -or $dns[0] -ne $old.address) { throw 'Name mapping has drifted; use Repair with the original verified root.' }
        $store=Get-RootStore -ReadOnly
        try { if (-not @($store.Certificates | Where-Object { (Get-RootFingerprint $_) -eq $old.fingerprint }).Count) { throw 'Trusted root is missing; use Repair.' } } finally { $store.Close() }
        Write-Output 'Ready: https://philcst.vms.internal (local server only; no internet needed)';exit 0
    }
    Assert-ClientAdmin
    $original=[IO.File]::ReadAllText($hostsFile)
    if ($Action -eq 'Remove') {
        if (-not $old) { Write-Output 'No owned enrollment to remove.';exit 0 }
        if ($old.hosts_added) { Write-ClientFile $hostsFile (Get-ClientHostsPlan $original) }
        if ($old.root_added) {
            $store=Get-RootStore
            try { foreach ($cert in @($store.Certificates)) { if ((Get-RootFingerprint $cert) -eq $old.fingerprint) { $store.Remove($cert) } } } finally { $store.Close() }
        }
        if ($old.shortcut_added -and (Test-Path $shortcut) -and [IO.File]::ReadAllText($shortcut) -eq $old.shortcut_text) { Remove-Item -LiteralPath $shortcut }
        Remove-Item -LiteralPath $stateDir -Recurse -Force
        & ipconfig.exe /flushdns | Out-Null
        Write-Output 'Removed only helper-owned mapping, trust and shortcut. Server and records are unchanged.';exit 0
    }
    if ($Action -eq 'Repair' -and $old) {
        if (-not $ServerAddress) { $ServerAddress=$old.address }
        if (-not $RootCertificate) { $RootCertificate=Join-Path $stateDir 'root.crt' }
        if (-not $RootFingerprint) { $RootFingerprint=$old.fingerprint }
        if (-not $PSBoundParameters.ContainsKey('NameResolution') -and $old.PSObject.Properties['name_resolution']) { $NameResolution=$old.name_resolution }
    }
    Assert-ClientAddress $ServerAddress
    $cert=Read-ClientRoot $RootCertificate $RootFingerprint
    Write-Host ('Verified public root SHA-256: '+(Get-RootFingerprint $cert))
    if ($old -and $old.fingerprint -ne (Get-RootFingerprint $cert)) { throw 'This client belongs to a different issuer. Remove its owned enrollment explicitly first.' }
    $planned=if ($NameResolution -eq 'Hosts') { Get-ClientHostsPlan $original $ServerAddress } else {
        $clean=Get-ClientHostsPlan $original
        $addresses=@([Net.Dns]::GetHostAddresses($script:VmsCanonicalHost) | ForEach-Object { $_.ToString() } | Sort-Object -Unique)
        if ($addresses.Count -ne 1 -or $addresses[0] -ne $ServerAddress) { throw 'DNS must resolve exclusively to the selected LAN IPv4 address; correct A/AAAA records first.' }
        $clean
    }
    # All wrong-address/root/hostname/fingerprint and hosts conflicts fail before mutation.
    Test-ClientHttps $ServerAddress $cert
    $shortcutText="[InternetShortcut]`r`nURL=$script:VmsCanonicalUrl`r`n"
    if ((Test-Path $shortcut) -and [IO.File]::ReadAllText($shortcut) -ne $shortcutText -and (-not $old -or [IO.File]::ReadAllText($shortcut) -ne $old.shortcut_text)) { throw 'The shortcut path is owned by someone else.' }
    if ((Test-Path $stateDir) -and -not $old -and @(Get-ChildItem $stateDir -Force).Count) { throw 'Unrecognized client state directory; nothing overwritten.' }
    $store=Get-RootStore;$added=$false;$hostsWritten=$false
    $shortcutCreated=-not (Test-Path -LiteralPath $shortcut)
    $directoryCreated=-not (Test-Path -LiteralPath $stateDir)
    $saved=@{}
    foreach ($file in @($receiptFile,(Join-Path $stateDir 'root.crt'),$shortcut)) { $saved[$file]=$(if (Test-Path -LiteralPath $file) { [IO.File]::ReadAllBytes($file) } else { $null }) }
    try {
        $present=@($store.Certificates | Where-Object { (Get-RootFingerprint $_) -eq $RootFingerprint.ToLowerInvariant() }).Count -gt 0
        if (-not $present) { $store.Add($cert);$added=$true }
        Write-ClientFile $hostsFile $planned;$hostsWritten=$true
        New-Item -ItemType Directory -Force -Path $stateDir | Out-Null
        & icacls.exe $stateDir /inheritance:r /grant:r '*S-1-5-32-544:(OI)(CI)F' '*S-1-5-18:(OI)(CI)F' '*S-1-5-32-545:(OI)(CI)RX' | Out-Null
        if ($LASTEXITCODE -ne 0) { throw 'Cannot protect enrollment ownership receipt.' }
        & icacls.exe $stateDir /setowner '*S-1-5-32-544' | Out-Null
        if ($LASTEXITCODE -ne 0) { throw 'Cannot protect enrollment ownership.' }
        [IO.File]::WriteAllBytes((Join-Path $stateDir 'root.crt'),$cert.Export('Cert'))
        & icacls.exe (Join-Path $stateDir 'root.crt') /setowner '*S-1-5-32-544' | Out-Null
        if ($LASTEXITCODE -ne 0) { throw 'Cannot protect public-root receipt ownership.' }
        Write-ClientFile $shortcut $shortcutText
        $receipt=@{schema=1;address=$ServerAddress;fingerprint=(Get-RootFingerprint $cert);root_added=($added -or ($old -and $old.root_added));hosts_added=($NameResolution -eq 'Hosts');name_resolution=$NameResolution;shortcut_text=$shortcutText;shortcut_added=($shortcutCreated -or ($old -and $old.shortcut_added))}
        Write-ClientFile $receiptFile ($receipt | ConvertTo-Json)
        & icacls.exe $receiptFile /setowner '*S-1-5-32-544' | Out-Null
        if ($LASTEXITCODE -ne 0) { throw 'Cannot protect connection receipt ownership.' }
    } catch {
        if ($hostsWritten) { Write-ClientFile $hostsFile $original }
        if ($added) { $store.Remove($cert) }
        foreach ($file in $saved.Keys) {
            if ($null -eq $saved[$file]) { if (Test-Path -LiteralPath $file) { Remove-Item -LiteralPath $file -Force } }
            else { [IO.File]::WriteAllBytes($file,$saved[$file]) }
        }
        if ($directoryCreated -and (Test-Path $stateDir) -and -not @(Get-ChildItem $stateDir -Force).Count) { Remove-Item -LiteralPath $stateDir }
        throw
    } finally { $store.Close() }
    & ipconfig.exe /flushdns | Out-Null
    Write-Output 'Connected. Open https://philcst.vms.internal. Firefox may need a separately verified public-root import; see CLIENT-CONNECTION.md.'
} catch { Write-Error $_.Exception.Message;exit 1 }
