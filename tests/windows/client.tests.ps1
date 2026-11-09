param([string]$RootCertificate='', [int]$HttpsPort=0)
$ErrorActionPreference='Stop'
. (Join-Path $PSScriptRoot '../../deploy/clients/client-common.ps1')
$checks=0
function Check($Value,[string]$Message) { if (-not $Value) { throw $Message };$script:checks++ }
function Reject([scriptblock]$Body) { $failed=$false;try { & $Body } catch { $failed=$true };Check $failed 'Expected refusal' }
$original="# fixture`r`n127.0.0.1 localhost`r`n::1 localhost`r`n10.0.0.2 school.internal`r`n"
$planned=Get-ClientHostsPlan $original '192.168.44.10'
Check ($planned -eq (Get-ClientHostsPlan $planned '192.168.44.10')) 'Idempotent hosts plan'
Check ((Get-ClientHostsPlan $planned) -eq $original) 'Unrelated hosts bytes preserved'
Reject { Get-ClientHostsPlan "::1 alias PHILCST.VMS.INTERNAL.`n" '192.168.44.10' }
Reject { Get-ClientHostsPlan "# BEGIN PHILCST-VMS-D4`n10.0.0.1 unrelated`n# END PHILCST-VMS-D4`n" '192.168.44.10' }
foreach ($bad in @('8.8.8.8','127.0.0.1','::1','192.168.001.2','0.0.0.0','224.0.0.1','169.254.1.1')) { Reject { Assert-ClientAddress $bad } }
Assert-ClientAddress '192.168.44.10';$checks++
if ($RootCertificate) {
 $pem=[IO.File]::ReadAllText($RootCertificate)
 $raw=[Convert]::FromBase64String(([regex]::Match($pem,'-----BEGIN CERTIFICATE-----([A-Za-z0-9+/=\s]+)-----END CERTIFICATE-----').Groups[1].Value -replace '\s',''))
 $cert=New-Object Security.Cryptography.X509Certificates.X509Certificate2 -ArgumentList @(,$raw)
 $fingerprint=Get-RootFingerprint $cert
 $read=Read-ClientRoot $RootCertificate $fingerprint
 Check ((Get-RootFingerprint $read) -eq $fingerprint) 'Verified public root'
 Reject { Read-ClientRoot $RootCertificate ('0'*64) }
 if ($HttpsPort) { Test-ClientHttps '127.0.0.1' $cert $HttpsPort;$checks++ }
}
Write-Output "$checks client policy/TLS checks passed; no OS mappings/trust/services changed."
. (Join-Path $PSScriptRoot '../../deploy/windows/lib/network-policy.ps1')
$config=@{mode='lan';address='192.168.44.10';interface='Ethernet';remote_scope='192.168.44.0/24'}
$root='C:\PHILCST-VMS'
$first=Get-VmsNetworkFirewallPlan $root $config @()
Check ($first.desired.Count -eq 3) 'Exactly browser and negotiated-media rules'
$owned=@($first.desired | ForEach-Object { [pscustomobject]@{Name=$_.name;Group=$_.group} })
$second=Get-VmsNetworkFirewallPlan $root $config $owned
Check (($first.desired | ConvertTo-Json -Depth 5) -eq ($second.desired | ConvertTo-Json -Depth 5)) 'Repeat firewall desired state unchanged'
$local=Get-VmsNetworkFirewallPlan $root @{mode='local'} ($owned+@([pscustomobject]@{Name='unrelated';Group='school'}))
Check ($local.desired.Count -eq 0 -and $local.remove.Count -eq 3) 'Local removes only the three owned rules'
Reject { Get-VmsNetworkFirewallPlan $root $config @([pscustomobject]@{Name=$owned[0].Name;Group='unowned'}) }
foreach ($rule in $first.desired) {
 Check ($rule.local_address -eq $config.address -and $rule.interface -eq 'Ethernet' -and $rule.remote_scope -eq $config.remote_scope) 'Narrow binding/scope'
 Check ($rule.port -in @(443,8555) -and ($rule.profiles -join ',') -eq 'Domain,Private') 'No raw internal API allowance'
}
Write-Output "$checks total client/firewall ownership checks passed. Native firewall mutation NOT RUN."
