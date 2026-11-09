"""Create the build inventory without asserting an unreviewed redistribution grant."""
import json
from pathlib import Path
import sys
import shutil
root=Path(sys.argv[1]);source=Path(sys.argv[2])
frontend_lock=json.loads((source/'package-lock.json').read_text())
frontend=[{'path':name,'version':entry.get('version'),'license':entry.get('license','See package notice'),'dev':entry.get('dev',False)} for name,entry in frontend_lock['packages'].items() if name]
for notice in (source/'node_modules').rglob('*'):
    if notice.is_file() and notice.name.upper().startswith(('LICENSE','COPYING','NOTICE')):
        target=root/'licenses/frontend'/notice.relative_to(source/'node_modules')
        target.parent.mkdir(parents=True,exist_ok=True);shutil.copyfile(notice,target)
composer=json.loads((root/'app/composer.lock').read_text())['packages']
python=json.loads((root/'PYTHON-DEPENDENCIES.json').read_text())['packages']
inputs=json.loads((root/'RUNTIME-INPUTS.json').read_text())
data={'composer':[{'name':p['name'],'version':p['version'],'license':p.get('license',[])} for p in composer],
      'python':python,'local_browser_dependencies':[{'name':'HLS light player','path':'app/public/vendor/hls/hls.light.min.js','notice':'app/public/vendor/hls/LICENSE','license':'Apache-2.0; see retained notice'}],'frontend_and_build_dependencies':frontend,'frontend_delivery':'Only built assets are shipped; node_modules is excluded. Lock includes development and optional build-platform packages.','inputs':inputs,'redistribution_status':'Requires institutional review before release; metadata is not a rights grant'}
(root/'DEPENDENCY-LICENSES.json').write_text(json.dumps(data,indent=2)+'\n')
text=['# Dependency and model notices','', 'NON-RELEASE candidate until redistribution/signing/source/native acceptance gates pass.',
      'Composer package licenses remain in app/vendor. Python wheel licenses/NOTICE files remain in each .dist-info directory. Runtime archive notices are copied to licenses/.',
      'Ultralytics code/YOLO and the recorded fixture require AGPL review. Verify third-party model rights separately; a manifest description is not sufficient approval.',
      'RUNTIME-INPUTS.json records pinned supplier URLs/hashes; DEPENDENCY-LICENSES.json records the exact resolved package versions and declared license metadata.',
      'Frontend/build dependency metadata is included separately; available package notices are retained in licenses/frontend. go2rtc has its separately pinned upstream MIT notice.',
      'No Tesseract or standalone FFmpeg executable is shipped. Inspect bundled OpenCV/codec/native-library notices as well as package-level notices.', '', '## Production Composer packages']
text += ['- '+p['name']+' '+p['version']+' ('+', '.join(p.get('license',[]))+')' for p in composer]
text += ['', '## Python packages']+['- '+p['name']+' '+p['version']+'; see its wheel notices' for p in python]
text += ['', '## Frontend/build dependencies (built assets only)']+['- '+p['path']+' '+str(p['version'])+'; '+str(p['license']) for p in frontend]
(root/'DEPENDENCY-NOTICES.md').write_text('\n'.join(text)+'\n')
