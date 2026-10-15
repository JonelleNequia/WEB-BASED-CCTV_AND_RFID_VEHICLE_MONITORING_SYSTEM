"""
One camera connection per stream, shared by every gate that uses it.

A camera used for two gates (testing, or one camera covering both lanes)
used to be opened once per gate: the VIGI-C240 accepts only 3 RTSP sessions
per stream (measured 2026-10-15: the 4th PLAY is answered "RTSP 429"), so
with the live view and the full-resolution snapshots the second gate was
refused and showed "Offline".

Now a stream (same address and login) is opened once. One reader thread
keeps its newest frame; every gate gets its own view (SharedCapture) that
waits for a frame newer than the last one *it* took. The connection closes
when the last gate lets go of it.

The reader object only needs: isOpened(), release(), wait_newer(after,
timeout) -> (sequence, frame) and the attributes too_slow / frame_time /
decoder_threads / roles (LatestFrameReader in detector_service.py).
"""

import threading


class SharedCapture:
    """One gate's view of a shared camera reader."""

    def __init__(self, hub, key, reader, role):
        self.hub = hub
        self.key = key
        self.reader = reader
        self.role = role
        self.consumed = 0
        self.released = False

    # The detector waits for frames on these (no fixed sleep between reads).
    waits_for_frames = True

    @property
    def too_slow(self):
        return getattr(self.reader, "too_slow", False)

    @property
    def frame_time(self):
        return getattr(self.reader, "frame_time", 0.0)

    @property
    def decoder_threads(self):
        return getattr(self.reader, "decoder_threads", None)

    def isOpened(self):
        return not self.released and self.reader.isOpened()

    def read_latest(self, timeout=None):
        """A frame newer than the last one this gate took (frames in between are skipped)."""
        sequence, frame = self.reader.wait_newer(self.consumed, timeout)
        if frame is None:
            return False, None
        self.consumed = sequence
        return True, frame

    def read(self):
        return self.read_latest()

    def get(self, prop):
        getter = getattr(self.reader, "get", None)
        return getter(prop) if getter else 0.0

    def release(self):
        if self.released:
            return
        self.released = True
        self.hub.release(self)


class CaptureHub:
    """
    key -> the reader currently serving it, and the gates' views of each
    reader. A key is the stream's address with its login (never logged).
    """

    def __init__(self):
        self.lock = threading.Lock()
        self.current = {}
        self.views = {}

    def acquire(self, key, role, factory, decoder_threads=None):
        """
        A view of the open reader for `key`, or of a new one from factory().
        factory() returns a reader, or anything not opened (returned as it is,
        not shared, so the caller reports the failure as before).
        """
        with self.lock:
            reader = self.current.get(key)
            if self._usable(reader, decoder_threads):
                return self._view(key, reader, role)

        # Open outside the lock: a camera can take seconds to answer.
        made = factory()
        if not hasattr(made, "wait_newer") or not made.isOpened():
            return made

        spare = None
        with self.lock:
            reader = self.current.get(key)
            if reader is not made and self._usable(reader, decoder_threads):
                spare = made  # another gate opened it meanwhile: use that one
            else:
                self.current[key] = made
                reader = made
            view = self._view(key, reader, role)
        if spare is not None:
            spare.release()
        return view

    def release(self, view):
        with self.lock:
            reader = view.reader
            views = [other for other in self.views.get(id(reader), []) if other is not view]
            roles = getattr(reader, "roles", None)
            if roles is not None and all(other.role != view.role for other in views):
                roles.discard(view.role)
            if views:
                self.views[id(reader)] = views
                return
            self.views.pop(id(reader), None)
            if self.current.get(view.key) is reader:
                self.current.pop(view.key, None)
        reader.release()

    def connections(self):
        """The open readers and the gates using each (status, tests)."""
        with self.lock:
            return [{"roles": sorted({view.role for view in self.views.get(id(reader), [])}), "users": len(self.views.get(id(reader), []))}
                    for reader in self.current.values()]

    def _view(self, key, reader, role):
        roles = getattr(reader, "roles", None)
        if roles is not None:
            roles.add(role)
        view = SharedCapture(self, key, reader, role)
        self.views.setdefault(id(reader), []).append(view)
        return view

    @staticmethod
    def _usable(reader, decoder_threads):
        if reader is None or not reader.isOpened():
            return False
        # One decoder thread fell behind: a gate asking for FFmpeg's own
        # threading gets a new connection, not the slow one again.
        return not (getattr(reader, "too_slow", False) and decoder_threads is not None
                    and decoder_threads != getattr(reader, "decoder_threads", None))


HUB = CaptureHub()
