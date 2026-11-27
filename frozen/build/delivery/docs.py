"""Render current operator links for the assembled layout; no historical paths."""
import argparse,re
from pathlib import Path

def prepare(source,stage):
    source=Path(source);stage=Path(stage)
    text=(source/'OPERATOR-GUIDE.md').read_text()
    text=text.replace('Read\n[the D6 delivery report](DEPLOYMENT-D6.md) for actual artifacts and acceptance.', 'Read\n[BUILD-INFO.json](BUILD-INFO.json) for this payload identity and the separately\nsupplied ACCEPTANCE.json for artifact-bound results.')
    links={'DEPLOYMENT-D6.md':'BUILD-INFO.json','ACCOUNT-SECURITY.md':'app/ACCOUNT-SECURITY.md',
           'deploy/network/NETWORK-SETUP.md':'NETWORK-SETUP.md','deploy/clients/CLIENT-CONNECTION.md':'Connect-to-Server/CLIENT-CONNECTION.md',
           'deploy/lifecycle/MAINTENANCE.md':'MAINTENANCE.md','deploy/lifecycle/RECOVERY.md':'RECOVERY.md',
           'deploy/updates/UPDATES.md':'UPDATES.md','deploy/transfer/TRANSFER.md':'TRANSFER.md'}
    for old,new in links.items():text=text.replace(']('+old+')',']('+new+')')
    (stage/'OPERATOR-GUIDE.md').write_text(text)
    (stage/'README.md').write_text('# PHILCST complete offline candidate\n\nRead [OPERATOR-GUIDE.md](OPERATOR-GUIDE.md), [BUILD-INFO.json](BUILD-INFO.json) and the externally supplied acceptance ledger. No production approval is implied.\n')
    for name in ['README.md','DEPLOYMENT.md','OPERATOR-GUIDE.md']:
        (stage/'app'/name).write_text('# Packaged operation\n\nUse [the packaged operator guide](../OPERATOR-GUIDE.md). Development and historical deployment reports are not installed repair procedures.\n')
    (stage/'OFFLINE-INSTALL.md').write_text('# Complete offline candidate\n\nUse [the packaged operator guide](OPERATOR-GUIDE.md) and [exact build identity](BUILD-INFO.json). No target developer tools/downloads are needed. Native installation and publisher approval remain separate acceptance.\n')

def check_links(root):
    root=Path(root).resolve();bad=[]
    for file in root.rglob('*.md'):
        if 'licenses' in file.relative_to(root).parts or 'vendor' in file.relative_to(root).parts or 'runtime' in file.relative_to(root).parts:continue
        for target in re.findall(r'\]\(([^)]+)\)',file.read_text(errors='replace')):
            if '://' in target or target.startswith(('#','mailto:')):continue
            path=target.split('#',1)[0]
            if path and not (file.parent/path).exists():bad.append((str(file.relative_to(root)),target))
    return bad

if __name__=='__main__':
    p=argparse.ArgumentParser();p.add_argument('source',type=Path);p.add_argument('stage',type=Path);a=p.parse_args();prepare(a.source,a.stage)
