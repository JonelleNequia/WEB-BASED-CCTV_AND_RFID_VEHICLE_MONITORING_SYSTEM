# Pure helpers are also used by the server adapter. PowerShell 5.1/.NET built-ins only.
$script:VmsCanonicalHost = 'philcst.vms.internal'
$script:VmsCanonicalUrl = 'https://philcst.vms.internal'
function Assert-ClientAddress([string]$Address, [switch]$LocalServer) {
    if ($LocalServer -and $Address -eq '127.0.0.1') { return }
    $parsed=$null
    if ($Address -notmatch '^[0-9]{1,3}(\.[0-9]{1,3}){3}$' -or -not [Net.IPAddress]::TryParse($Address,[ref]$parsed) -or $parsed.ToString() -ne $Address) { throw 'Enter a canonical IPv4 address.' }
    $b=$parsed.GetAddressBytes()
    if (-not ($b[0] -eq 10 -or ($b[0] -eq 172 -and $b[1] -ge 16 -and $b[1] -le 31) -or ($b[0] -eq 192 -and $b[1] -eq 168))) { throw 'Select the stable private LAN address of the main server. IPv6 sharing is not configured.' }
}
function Get-RootFingerprint($Certificate) {
    $hash=[Security.Cryptography.SHA256]::Create()
    try { return -join ($hash.ComputeHash($Certificate.RawData) | ForEach-Object { $_.ToString('x2') }) } finally { $hash.Dispose() }
}
function Read-ClientRoot([string]$Path,[string]$Fingerprint) {
    if ($Fingerprint -notmatch '^[a-fA-F0-9]{64}$') { throw 'Provide the independent 64-digit SHA-256 root fingerprint, without separators.' }
    $raw=[IO.File]::ReadAllBytes($Path);$pem=[Text.Encoding]::ASCII.GetString($raw)
    if ($pem -match 'PRIVATE KEY') { throw 'Private key material must never be delivered to a client.' }
    if ($pem -match 'BEGIN CERTIFICATE') {
        $matches=[regex]::Matches($pem,'-----BEGIN CERTIFICATE-----([A-Za-z0-9+/=\s]+)-----END CERTIFICATE-----')
        if ($matches.Count -ne 1) { throw 'Select exactly one public root certificate.' }
        $raw=[Convert]::FromBase64String(($matches[0].Groups[1].Value -replace '\s',''))
    }
    $cert=New-Object Security.Cryptography.X509Certificates.X509Certificate2 -ArgumentList @(,$raw)
    if ((Get-RootFingerprint $cert) -ne $Fingerprint.ToLowerInvariant()) { throw 'Root fingerprint mismatch. Nothing was trusted or mapped.' }
    $ca=@($cert.Extensions | Where-Object { $_.Oid.Value -eq '2.5.29.19' })
    if ($ca.Count -ne 1 -or -not $ca[0].CertificateAuthority -or $cert.Subject -ne $cert.Issuer) { throw 'Select the installation public root CA, not a leaf/intermediate certificate.' }
    if ((Get-Date) -lt $cert.NotBefore -or (Get-Date) -ge $cert.NotAfter) { throw 'Root certificate is not current. Check the clock and expiry; do not regenerate trust automatically.' }
    return $cert
}
function Get-ClientHostsPlan([string]$Original,[string]$Address='') {
    $begin='# BEGIN PHILCST-VMS-D4';$end='# END PHILCST-VMS-D4';$inside=$false;$found=$false;$out=New-Object Text.StringBuilder
    foreach ($line in [regex]::Matches($Original,'[^\r\n]*(?:\r\n|\n|\r|$)')) {
        if (-not $line.Length) { continue };$text=$line.Value;$trim=$text.Trim()
        if ($trim -eq $begin) { if ($inside -or $found) { throw 'Duplicate managed hosts block.' };$inside=$true;$found=$true;continue }
        if ($trim -eq $end) { if (-not $inside) { throw 'Malformed managed hosts block.' };$inside=$false;continue }
        $tokens=@(($text -split '#',2)[0].Trim() -split '\s+' | Where-Object { $_ })
        if ($inside) { if ($tokens.Count -and ($tokens.Count -ne 2 -or $tokens[1].ToLowerInvariant() -ne $script:VmsCanonicalHost)) { throw 'Unrelated content inside the managed hosts block; repair manually.' };continue }
        if ($Address -and @($tokens | Select-Object -Skip 1 | Where-Object { $_.ToLowerInvariant().TrimEnd('.') -eq $script:VmsCanonicalHost }).Count) { throw 'Unowned IPv4/IPv6 hosts mapping exists; resolve the conflict explicitly.' }
        [void]$out.Append($text)
    }
    if ($inside) { throw 'Unclosed managed hosts block.' }
    if ($Address) {
        $eol=if ($Original.Contains("`r`n")) { "`r`n" } else { "`n" }
        if ($out.Length -and -not $out.ToString().EndsWith("`n")) { [void]$out.Append($eol) }
        [void]$out.Append($begin+$eol+$Address+"`t"+$script:VmsCanonicalHost+$eol+$end+$eol)
    }
    return $out.ToString()
}
function Write-ClientFile([string]$Path,[string]$Text) {
    $temporary=Join-Path (Split-Path -Parent $Path) ('.philcst-'+[guid]::NewGuid().ToString('N'))
    try {
        [IO.File]::WriteAllText($temporary,$Text,(New-Object Text.UTF8Encoding($false)))
        if (Test-Path -LiteralPath $Path) { [IO.File]::Replace($temporary,$Path,$null) } else { [IO.File]::Move($temporary,$Path) }
    } finally { if (Test-Path -LiteralPath $temporary) { Remove-Item -LiteralPath $temporary -Force } }
}
function Assert-ClientAdmin {
    $id=[Security.Principal.WindowsIdentity]::GetCurrent()
    if (-not ([Security.Principal.WindowsPrincipal]$id).IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) { throw 'Run PowerShell as Administrator: the chosen action changes only the managed hosts block, selected public-root trust and shortcut.' }
}
function Get-RootStore([switch]$ReadOnly) { $store=New-Object Security.Cryptography.X509Certificates.X509Store('Root','LocalMachine');$store.Open($(if ($ReadOnly) { 'ReadOnly' } else { 'ReadWrite' }));return $store }
function Test-ClientHttps([string]$Address,$Root,[int]$Port=443) {
    # Per-connection validation against the independently pinned root. No global bypass.
    if (-not ('PhilcstPinnedTls' -as [type])) {
        Add-Type -TypeDefinition @'
using System;
using System.IO;
using System.Net.Sockets;
using System.Net.Security;
using System.Security.Authentication;
using System.Security.Cryptography;
using System.Security.Cryptography.X509Certificates;
using System.Text;
public static class PhilcstPinnedTls {
 public static void Check(string address,int port,X509Certificate2 root) {
  using(var tcp=new TcpClient()) {
   var pending=tcp.BeginConnect(address,port,null,null);
   if(!pending.AsyncWaitHandle.WaitOne(8000)) throw new Exception("Server connection timed out");
   tcp.EndConnect(pending); tcp.ReceiveTimeout=8000; tcp.SendTimeout=8000;
   RemoteCertificateValidationCallback verify=(sender,cert,supplied,errors)=> {
    if(cert==null || (errors & (SslPolicyErrors.RemoteCertificateNameMismatch|SslPolicyErrors.RemoteCertificateNotAvailable))!=0) return false;
    using(var chain=new X509Chain()) {
     chain.ChainPolicy.RevocationMode=X509RevocationMode.NoCheck;
     chain.ChainPolicy.VerificationFlags=X509VerificationFlags.AllowUnknownCertificateAuthority;
     chain.ChainPolicy.UrlRetrievalTimeout=TimeSpan.FromMilliseconds(1);
     chain.ChainPolicy.ExtraStore.Add(root);
     if(supplied!=null) foreach(var item in supplied.ChainElements) chain.ChainPolicy.ExtraStore.Add(item.Certificate);
     if(!chain.Build(new X509Certificate2(cert))) return false;
     foreach(var status in chain.ChainStatus) if(status.Status!=X509ChainStatusFlags.UntrustedRoot && status.Status!=X509ChainStatusFlags.NoError) return false;
     var last=chain.ChainElements[chain.ChainElements.Count-1].Certificate;
     return Convert.ToBase64String(last.RawData)==Convert.ToBase64String(root.RawData);
    }
   };
   using(var tls=new SslStream(tcp.GetStream(),false,verify)) {
    tls.ReadTimeout=8000; tls.WriteTimeout=8000;
    tls.AuthenticateAsClient("philcst.vms.internal",null,SslProtocols.Tls12,false);
    var request=Encoding.ASCII.GetBytes("GET /up HTTP/1.0\r\nHost: philcst.vms.internal\r\nConnection: close\r\n\r\n");
    tls.Write(request,0,request.Length);
    using(var reader=new StreamReader(tls)) {
     var status=reader.ReadLine();
     if(status==null || !status.Contains(" 200 ")) throw new Exception("TLS passed but server readiness failed");
    }
   }
  }
 }
}
'@
    }
    [PhilcstPinnedTls]::Check($Address,$Port,$Root)
}
