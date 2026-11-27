"""Exercise the actual detector frame loop without loading models/cameras."""
import ast
from pathlib import Path
import types
import unittest

ROOT=Path(__file__).resolve().parents[2]

class StreamLease(unittest.TestCase):
    def method(self,packaged):
        tree=ast.parse((ROOT/'school-vehicle-monitoring-detector/detector_service.py').read_text())
        node=next(n for c in tree.body if isinstance(c,ast.ClassDef) for n in c.body if isinstance(n,ast.FunctionDef) and n.name=='_send_frames')
        clock=types.SimpleNamespace(value=0.0)
        class Condition:
            def __enter__(self):return self
            def __exit__(self,*args):pass
            def wait_for(self,predicate,timeout):clock.value+=timeout
        env={'time':types.SimpleNamespace(monotonic=lambda:clock.value),'os':types.SimpleNamespace(environ={'PHILCST_PACKAGED_OFFLINE':'1'} if packaged else {}),
             'STREAM_CONDITION':Condition(),'STREAM_FRAMES':{},'STREAM_FRAME_TIMES':{},'metrics':types.SimpleNamespace(rate=lambda *a:None,timing=lambda *a:None)}
        exec(compile(ast.Module(body=[node],type_ignores=[]),'<actual detector stream loop>','exec'),env)
        return env['_send_frames'],env,clock
    def test_packaged_connection_expires_even_when_no_frames_arrive(self):
        method,env,clock=self.method(True);method(types.SimpleNamespace(),'gate-1',None)
        self.assertGreaterEqual(clock.value,30);self.assertLessEqual(clock.value,31)
    def test_stalled_or_disconnected_write_ends_the_packaged_connection(self):
        method,env,clock=self.method(True);env['STREAM_FRAMES']['gate-1']=b'frame'
        class Broken:
            def write(self,*args):raise TimeoutError('fixture write deadline')
        method(types.SimpleNamespace(wfile=Broken()),'gate-1',None)
        self.assertEqual(clock.value,1)

if __name__=='__main__':unittest.main()
