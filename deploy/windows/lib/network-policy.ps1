# Pure D4 ownership/desired-state contract. Native firewall changes live in network-adapter.ps1.
function Get-VmsNetworkRuleIdentity([string]$Root) {
 $hash=[Security.Cryptography.SHA256]::Create()
 try { $tag=-join ($hash.ComputeHash([Text.Encoding]::UTF8.GetBytes($Root.ToLowerInvariant())) | ForEach-Object { $_.ToString('x2') }) } finally { $hash.Dispose() }
 $group='PHILCST-D4-'+$tag.Substring(0,12)
 return @{group=$group;names=@(($group+'-HTTPS'),($group+'-WebRTC-TCP'),($group+'-WebRTC-UDP'))}
}
function Get-VmsNetworkFirewallPlan([string]$Root,$Config,[object[]]$Existing=@()) {
 $identity=Get-VmsNetworkRuleIdentity $Root
 $remove=@()
 foreach ($rule in $Existing) {
  if ($identity.names -contains $rule.Name) {
   if ($rule.Group -ne $identity.group) { throw 'Firewall name is owned by someone else. Nothing changed.' }
   $remove+=$rule.Name
  }
 }
 $desired=@()
 if ($Config.mode -eq 'lan') {
  $rows=@(@($identity.names[0],'TCP',443,'caddy\caddy.exe'),@($identity.names[1],'TCP',8555,'go2rtc\go2rtc.exe'),@($identity.names[2],'UDP',8555,'go2rtc\go2rtc.exe'))
  foreach ($row in $rows) {
   $desired+=@{name=$row[0];group=$identity.group;protocol=$row[1];port=$row[2];program=($Root.TrimEnd('\')+'\runtime\'+$row[3]);interface=$Config.interface;local_address=$Config.address;remote_scope=$Config.remote_scope;profiles=@('Domain','Private')}
  }
 } elseif ($Config.mode -ne 'local') { throw 'Unknown network mode' }
 return @{group=$identity.group;remove=$remove;desired=$desired}
}
