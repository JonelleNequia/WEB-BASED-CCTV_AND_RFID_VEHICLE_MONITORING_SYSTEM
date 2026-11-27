#!/bin/sh
# Pure helpers: safe to source in an isolated fixture. Uses only macOS built-in tools.
VMS_HOST=philcst.vms.internal
VMS_URL=https://philcst.vms.internal
fail() { echo "PHILCST: $*" >&2; return 1; }
validate_address() {
    awk -v ip="$1" 'BEGIN { n=split(ip,a,"."); if(n!=4)exit 1; for(i=1;i<=4;i++)if(a[i]!~/^[0-9]+$/||a[i]>255||(length(a[i])>1&&substr(a[i],1,1)=="0"))exit 1; if(a[1]==10||(a[1]==172&&a[2]>=16&&a[2]<=31)||(a[1]==192&&a[2]==168))exit 0;exit 1 }' || fail 'Use the main server private IPv4 LAN address. IPv6 sharing is not configured.'
}
root_fingerprint() { /usr/bin/openssl x509 -in "$1" -noout -fingerprint -sha256 | sed 's/.*=//;s/://g' | tr 'A-F' 'a-f'; }
validate_root_identity() {
    [ "${#2}" -eq 64 ] && ! printf '%s' "$2" | LC_ALL=C grep -q '[^a-fA-F0-9]' || { fail 'Provide the independently handed-off 64-digit SHA-256 root fingerprint.'; return 1; }
    [ -f "$1" ] && ! grep -q 'PRIVATE KEY' "$1" || { fail 'Select a public root certificate; never a private key.'; return 1; }
    [ "$(grep -c 'BEGIN CERTIFICATE' "$1")" -eq 1 ] || { fail 'Select exactly one PEM public root certificate.'; return 1; }
    actual=$(root_fingerprint "$1") || return 1
    [ "$actual" = "$(printf '%s' "$2" | tr 'A-F' 'a-f')" ] || { fail 'Root fingerprint mismatch. Nothing changed.'; return 1; }
    /usr/bin/openssl x509 -in "$1" -noout -text | grep -q 'CA:TRUE' || { fail 'Select the installation root CA.'; return 1; }
    subject=$(/usr/bin/openssl x509 -in "$1" -noout -subject | sed 's/^subject= *//')
    issuer=$(/usr/bin/openssl x509 -in "$1" -noout -issuer | sed 's/^issuer= *//')
    [ "$subject" = "$issuer" ] || { fail 'An intermediate/leaf cannot replace the public root.'; return 1; }
}
validate_root() {
    validate_root_identity "$1" "$2" || return 1
    /usr/bin/openssl verify -CAfile "$1" "$1" >/dev/null || { fail 'Root certificate/clock is invalid or expired. Do not regenerate it automatically.'; return 1; }
}
hosts_plan() {
    # Input is stdin. Preserve unrelated lines; both A and AAAA aliases conflict.
    awk -v addr="$1" -v host="$VMS_HOST" '
    BEGIN { begin="# BEGIN PHILCST-VMS-D4";end="# END PHILCST-VMS-D4" }
    { line=$0;sub(/\r$/,"",line)
      if(line==begin){if(inside||found){bad=1;exit};inside=1;found=1;next}
      if(line==end){if(!inside){bad=1;exit};inside=0;next}
      body=line;sub(/#.*/,"",body);n=split(body,a,/[ \t]+/);count=0;for(i=1;i<=n;i++)if(a[i]!="")b[++count]=a[i]
      if(inside){if(count&&(count!=2||tolower(b[2])!=host)){bad=1;exit};next}
      if(addr!="")for(i=2;i<=count;i++){alias=tolower(b[i]);sub(/\.$/,"",alias);if(alias==host){bad=1;exit}}
      print $0
    }
    END { if(bad||inside)exit 3;if(addr!=""){print begin;print addr "\t" host;print end} }'
}
probe_https() {
    /usr/bin/curl --noproxy '*' --proto '=https' --proto-redir '=https' --connect-timeout 5 --max-time 12 --fail --silent --show-error --cacert "$2" --resolve "$VMS_HOST:443:$1" "$VMS_URL/up" >/dev/null
}
root_present() { /usr/bin/security find-certificate -a -Z /Library/Keychains/System.keychain | tr 'A-F' 'a-f' | grep -q "$1"; }
