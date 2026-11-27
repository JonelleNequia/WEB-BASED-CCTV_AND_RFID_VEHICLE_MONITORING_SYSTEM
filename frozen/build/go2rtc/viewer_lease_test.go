package webrtc

import (
 "testing"
 "time"
 corewebrtc "github.com/AlexxIT/go2rtc/pkg/webrtc"
 pion "github.com/pion/webrtc/v4"
)

func TestPhilcstViewerLease(t *testing.T) {
 if viewerLease != 30*time.Second { t.Fatal("Production viewer lease must remain bounded at 30s") }
 viewerLease = 100*time.Millisecond
 defer func(){ viewerLease=30*time.Second }()
 pc, err := pion.NewPeerConnection(pion.Configuration{ICEServers: []pion.ICEServer{}})
 if err != nil { t.Fatal(err) }
 defer pc.Close()
 conn := corewebrtc.NewConn(pc)
 applyViewerLease(conn)
 deadline := time.Now().Add(2*time.Second)
 for time.Now().Before(deadline) {
  if pc.ConnectionState()==pion.PeerConnectionStateClosed { return }
  time.Sleep(10*time.Millisecond)
 }
 t.Fatal("Server did not close the leased peer without browser cooperation")
}

func TestPhilcstViewerLeaseProductionBoundTwoPeers(t *testing.T) {
 if viewerLease != 30*time.Second { t.Fatal("Expected production lease") }
 peers := make([]*pion.PeerConnection,0,2)
 start := time.Now()
 for i:=0;i<2;i++ {
  pc,err:=pion.NewPeerConnection(pion.Configuration{ICEServers: []pion.ICEServer{}})
  if err!=nil { t.Fatal(err) }
  peers=append(peers,pc)
  defer pc.Close()
  applyViewerLease(corewebrtc.NewConn(pc))
 }
 deadline:=time.Now().Add(33*time.Second)
 for time.Now().Before(deadline) {
  if peers[0].ConnectionState()==pion.PeerConnectionStateClosed && peers[1].ConnectionState()==pion.PeerConnectionStateClosed {
   t.Logf("Both peers closed without browser cooperation after %s",time.Since(start));return
  }
  time.Sleep(20*time.Millisecond)
 }
 t.Fatal("Production viewer lease did not close both peers")
}
