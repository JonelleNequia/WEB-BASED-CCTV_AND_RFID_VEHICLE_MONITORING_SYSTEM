# Administrator-owned immutable program/capsule ACLs, independent of archive modes.
param([Parameter(Mandatory=$true)][string]$Path)
$ErrorActionPreference='Stop'
. (Join-Path $PSScriptRoot 'lib\common.ps1')
if ($env:OS -ne 'Windows_NT' -or -not (Test-VmsAdmin)) { throw 'Native administrator required.' }
$root=[IO.Path]::GetFullPath($Path)
if ($root.StartsWith('\\')) { throw 'Local fixed-disk program directory required.' }
$items=@((Get-Item -LiteralPath $root -Force))+@(Get-ChildItem -LiteralPath $root -Recurse -Force)
$webSid=([Security.Principal.NTAccount]'NT SERVICE\PHILCST-Web').Translate([Security.Principal.SecurityIdentifier]).Value
foreach ($item in $items) {
 if ($item.Attributes -band [IO.FileAttributes]::ReparsePoint) {throw 'Unexpected program redirect.'}
 $acl=if ($item.PSIsContainer) {New-Object Security.AccessControl.DirectorySecurity} else {New-Object Security.AccessControl.FileSecurity}
 $acl.SetAccessRuleProtection($true,$false);$acl.SetOwner((New-Object Security.Principal.SecurityIdentifier('S-1-5-32-544')))
 $inherit=if ($item.PSIsContainer) {[Security.AccessControl.InheritanceFlags]'ContainerInherit,ObjectInherit'} else {[Security.AccessControl.InheritanceFlags]::None}
 foreach ($entry in @(@('S-1-5-32-544','FullControl'),@('S-1-5-18','FullControl'),@('S-1-5-19','ReadAndExecute'),@($webSid,'ReadAndExecute'))) {
  $acl.AddAccessRule((New-Object Security.AccessControl.FileSystemAccessRule((New-Object Security.Principal.SecurityIdentifier($entry[0])),[Security.AccessControl.FileSystemRights]$entry[1],$inherit,[Security.AccessControl.PropagationFlags]::None,[Security.AccessControl.AccessControlType]::Allow)))
 }
 Set-Acl -LiteralPath $item.FullName -AclObject $acl
}
