"""Recorded decoder and synthetic RFID framing. No readers, cameras or HTTP writes."""
import json
from pathlib import Path
import sys
import socket
root=Path(sys.argv[1]).resolve()
def offline(*_args,**_kwargs):raise RuntimeError('Network unavailable in evidence fixture')
socket.create_connection=offline;socket.socket.connect=offline
sys.path.insert(0,str(root/'app/school-vehicle-monitoring-detector'))
import cv2
from devices import uhf
uid='00000017221101441890ABCD'
epc=bytes.fromhex(uid);pc=((len(epc)//2)<<11).to_bytes(2,'big');data=b'\x01'+pc+epc+b'\x55';body=bytes([0xa0,len(data)+3,1,0x89])+data;frame=body+bytes([uhf.r2000_checksum(body)])
decoder=uhf.StreamDecoder();frames=decoder.feed(frame[:7])+decoder.feed(frame[7:])
if [x.epc for x in frames]!=[uid]:raise RuntimeError('RFID split-frame/literal UID fixture failed')
corrupt=bytearray(frame);corrupt[-1]^=0xff
if uhf.decode_once(bytes(corrupt),'r2000')[0]:raise RuntimeError('RFID checksum fixture failed')
reader=cv2.VideoCapture(str(root/'checks/fixtures/recorded-video.avi'));decoded=0;codec=''
try:
    if not reader.isOpened():raise RuntimeError('Packaged recorded-video decoder did not open the local fixture')
    code=int(reader.get(cv2.CAP_PROP_FOURCC));codec=''.join(chr((code>>(8*n))&255) for n in range(4))
    for _ in range(4):
        ok,image=reader.read()
        if not ok or image is None or not image.size:raise RuntimeError('Packaged recorded-video frame decode failed')
        decoded+=1
finally:reader.release()
print(json.dumps({'video':{'decoded_frames':decoded,'fourcc':codec,'source':'local OpenCV recorded AVI; no RTSP/H264/hardware claim'},'rfid':'R2000 split frame, leading-zero electronic UID and corrupt checksum passed; no hardware/API write'}))
