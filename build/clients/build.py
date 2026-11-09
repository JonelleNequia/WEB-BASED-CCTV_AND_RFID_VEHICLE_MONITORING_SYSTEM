#!/usr/bin/env python3
"""Small client-only development bundles. No installation material/private CA is included."""
import argparse
import hashlib
import json
from pathlib import Path
import subprocess
import tempfile
import zipfile

ROOT=Path(__file__).resolve().parents[2]
FILES={'Windows':['Connect-to-PHILCST.ps1','client-common.ps1','CLIENT-CONNECTION.md'],
       'Mac':['Connect-to-PHILCST.command','client-common.sh','CLIENT-CONNECTION.md']}


def main():
    p=argparse.ArgumentParser();p.add_argument('--out',type=Path,required=True);a=p.parse_args();a.out.mkdir(parents=True,exist_ok=True)
    identities={name:hashlib.sha256((ROOT/'deploy/clients'/name).read_bytes()).hexdigest() for name in sorted(set(sum(FILES.values(),[])))}
    identity=hashlib.sha256(json.dumps(identities,sort_keys=True,separators=(',',':')).encode()).hexdigest()
    commit=subprocess.check_output(['git','-C',ROOT,'rev-parse','HEAD'],text=True).strip()
    results=[]
    for platform,names in FILES.items():
        output=a.out/f'Connect-to-PHILCST-{platform}-D4-dev.{identity[:12]}.zip'
        if output.exists():raise ValueError('Existing helper artifact preserved; choose a new output directory')
        manifest={n:{'sha256':identities[n],'bytes':(ROOT/'deploy/clients'/n).stat().st_size} for n in names}
        info={'product':'PHILCST browser client helper','development_candidate':True,'publisher_authenticated':False,'base_commit':commit,'helper_source_sha256':identity,'helper_files':identities,'platform':platform,'server_runtime_included':False,'installation_data_or_ca_included':False,'native_privileged_acceptance':'NOT RUN'}
        with zipfile.ZipFile(output,'x',compression=zipfile.ZIP_DEFLATED) as z:
            for name in names:z.write(ROOT/'deploy/clients'/name,'Connect-to-PHILCST/'+name)
            z.writestr('Connect-to-PHILCST/HELPER-INFO.json',json.dumps(info,indent=2)+'\n')
            z.writestr('Connect-to-PHILCST/CONTENT-MANIFEST.json',json.dumps(manifest,indent=2)+'\n')
        with zipfile.ZipFile(output) as z:
            assert z.testzip() is None
            for name,entry in manifest.items():assert hashlib.sha256(z.read('Connect-to-PHILCST/'+name)).hexdigest()==entry['sha256']
        digest=hashlib.sha256(output.read_bytes()).hexdigest();output.with_suffix('.zip.sha256').write_text(digest+'  '+output.name+'\n')
        results.append({'file':str(output),'bytes':output.stat().st_size,'sha256':digest,'helper_source_sha256':identity})
    print(json.dumps(results,indent=2))

if __name__=='__main__':main()
