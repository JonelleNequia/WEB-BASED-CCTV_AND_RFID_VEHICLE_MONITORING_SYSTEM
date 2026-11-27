"""Protected cross-host ownership receipt. Local enforcement, never a remote lease."""
import json
from pathlib import Path
import uuid


def check(base):
    base=Path(base);state=json.loads((base/'maintenance.json').read_text());path=base/'ownership.json'
    if not state.get('ownership_required') and not path.exists():return
    if not path.is_file():raise ValueError('Protected ownership receipt missing; keep all writers fenced')
    value=json.loads(path.read_text())
    if value.get('schema')!=1 or value.get('state')!='active' or type(value.get('epoch')) is not int or value['epoch']<0:raise ValueError('Server is retired, reserved or transfer-incomplete; ordinary admission/resume refused')
    uuid.UUID(value['owner']);uuid.UUID(value['deployment'])
    ident=json.loads((base/'identity.json').read_text())
    if value['deployment']!=ident['installation']:raise ValueError('Ownership/deployment identity mismatch')
