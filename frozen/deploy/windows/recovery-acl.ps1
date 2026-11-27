# Native D5B role ACLs. No archive ACL/service definition is imported.
param([Parameter(Mandatory=$true)][string]$Root,
 [Parameter(Mandatory=$true)][string]$Path,
 [ValidateSet('private','database','environment','storage','configuration','tls','network','tls_identity','public_root','server_trust','update_trust','setup','installation','delivery_identity','delivery','coordination','security')][string]$Role,
 [ValidateSet('Protect','Verify')][string]$Action, [switch]$ExistingPrivate)
$ErrorActionPreference='Stop'
. (Join-Path $PSScriptRoot 'lib\common.ps1')
if ($env:OS -ne 'Windows_NT' -or -not (Test-VmsAdmin)) { throw 'Native Windows administrator required.' }
$full=[IO.Path]::GetFullPath($Path);$prefix=[IO.Path]::GetFullPath($Root).TrimEnd('\')+'\'
if ($Role -ne 'private' -and -not $full.StartsWith($prefix,[StringComparison]::OrdinalIgnoreCase)) { throw 'Recovery permission target is outside the owned installation.' }
if ($full.StartsWith('\\')) { throw 'A local ACL-capable destination is required.' }
$members=@((Get-Item -LiteralPath $full -Force))
if ($members[0].PSIsContainer) { $members+=@(Get-ChildItem -LiteralPath $full -Recurse -Force -ErrorAction Stop) }
foreach ($item in $members) { if ($item.Attributes -band [IO.FileAttributes]::ReparsePoint) { throw 'State redirect refused.' } }
$hash=[Security.Cryptography.SHA1]::Create()
try { $bytes=$hash.ComputeHash([Text.Encoding]::Unicode.GetBytes('PHILCST-WEB')) } finally { $hash.Dispose() }
$webSid='S-1-5-80-'+((0..4 | ForEach-Object { [BitConverter]::ToUInt32($bytes,$_ * 4) }) -join '-')
$rights=@{'S-1-5-32-544'='FullControl';'S-1-5-18'='FullControl'}
if ($Role -eq 'tls') { $rights[$webSid]='Modify' }
elseif ($Role -notin @('private','setup')) {
 $rights['S-1-5-19']=if ($Role -in @('storage','database','delivery','coordination')) {'Modify'} else {'ReadAndExecute'}
 if ($Role -in @('security','delivery_identity')) { $rights[$webSid]='ReadAndExecute' }
}
function Verify-Private($Item) {
 $acl=Get-Acl -LiteralPath $Item.FullName
 # A newly created inspection directory may safely inherit only admin/SYSTEM
 # from the protected recovery parent; Protect below then freezes its DACL.
 foreach ($rule in $acl.Access) {
  if ($rule.IdentityReference.Translate([Security.Principal.SecurityIdentifier]).Value -notin @('S-1-5-32-544','S-1-5-18')) { throw 'Existing destination grants another principal access; choose a new private destination.' }
 }
}
foreach ($item in $members) {
 if ($ExistingPrivate) { Verify-Private $item }
 if ($Action -eq 'Protect') {
  $acl=if ($item.PSIsContainer) { New-Object Security.AccessControl.DirectorySecurity } else { New-Object Security.AccessControl.FileSecurity }
  $acl.SetOwner((New-Object Security.Principal.SecurityIdentifier('S-1-5-32-544')))
  $acl.SetAccessRuleProtection($true,$false)
  $inherit=if ($item.PSIsContainer) {[Security.AccessControl.InheritanceFlags]'ContainerInherit,ObjectInherit'} else {[Security.AccessControl.InheritanceFlags]::None}
  foreach ($sid in $rights.Keys) {
   $rule=New-Object Security.AccessControl.FileSystemAccessRule((New-Object Security.Principal.SecurityIdentifier($sid)),[Security.AccessControl.FileSystemRights]$rights[$sid],$inherit,[Security.AccessControl.PropagationFlags]::None,[Security.AccessControl.AccessControlType]::Allow)
   $acl.AddAccessRule($rule)
  }
  Set-Acl -LiteralPath $item.FullName -AclObject $acl
 }
 $actual=Get-Acl -LiteralPath $item.FullName
 if (-not $actual.AreAccessRulesProtected) { throw 'Recovery ACL inheritance is not protected.' }
 $seen=@{}
 foreach ($rule in $actual.Access) {
  $sid=$rule.IdentityReference.Translate([Security.Principal.SecurityIdentifier]).Value
  if ($rule.AccessControlType -ne 'Allow' -or -not $rights.ContainsKey($sid)) { throw 'Unexpected recovery ACL rule.' }
  $expected=[Security.AccessControl.FileSystemRights]$rights[$sid]
  $meaningful=[Security.AccessControl.FileSystemRights]($rule.FileSystemRights -band (-bnot [int][Security.AccessControl.FileSystemRights]::Synchronize))
  $expected=[Security.AccessControl.FileSystemRights]($expected -band (-bnot [int][Security.AccessControl.FileSystemRights]::Synchronize))
  if ($meaningful -ne $expected) { throw 'Recovery ACL rights mismatch.' }
  $seen[$sid]=$true
 }
 foreach ($sid in $rights.Keys) { if (-not $seen.ContainsKey($sid)) { throw 'A required recovery principal is missing.' } }
}
