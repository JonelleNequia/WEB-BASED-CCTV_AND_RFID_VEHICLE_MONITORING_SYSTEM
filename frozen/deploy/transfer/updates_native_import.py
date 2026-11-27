"""Load the installed D5C module without a Python package/name collision."""
import importlib.util
from pathlib import Path
import sys
file=Path(__file__).resolve().parents[1]/'updates/native.py'
sys.path.insert(0,str(file.parent))
spec=importlib.util.spec_from_file_location('philcst_transfer_installed_update_native',file)
module=importlib.util.module_from_spec(spec);spec.loader.exec_module(module)
NativeUpdate=module.NativeUpdate
