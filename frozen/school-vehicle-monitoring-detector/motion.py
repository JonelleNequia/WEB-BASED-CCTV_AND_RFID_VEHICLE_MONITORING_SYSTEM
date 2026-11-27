"""
Phase 4: a cheap "something moves in the zone" check.

YOLO runs at the busy rate (detection_fps, e.g. 15 per second) while
something moves in the zone or a vehicle is in it, and at the idle rate
(detection_idle_fps, e.g. 5) when the zone is quiet: fast vehicles are seen
in more frames, and an empty gate costs little.

The check compares a tiny grey copy of the frame (160 px wide, blurred)
with the previous one, inside the zone only. It misses nothing important:
even at the idle rate YOLO still runs, a vehicle in the zone makes the gate
busy by itself, and a false alarm (rain, a shadow) only costs some CPU.
"""

import cv2
import numpy as np

SMALL_WIDTH = 160
# A pixel "moved" when its grey level changed more than this...
PIXEL_DELTA = 18
# ...and the zone moved when at least this share of its pixels did (about
# 5 pixels of a 160 x 90 zone: a small, far vehicle).
MOVING_SHARE = 0.0005
CHECK_INTERVAL_SECONDS = 0.1


class MotionGate:
    def __init__(self):
        self.previous = None
        self.mask = None
        self.mask_key = None
        self.checked_at = float("-inf")

    def _zone_mask(self, polygon, size):
        key = (tuple((round(p["x"], 4), round(p["y"], 4)) for p in polygon or []), size)
        if key != self.mask_key:
            width, height = size
            mask = np.zeros((height, width), np.uint8)
            points = np.array([[int(p["x"] * width), int(p["y"] * height)] for p in polygon or []], np.int32)
            if len(points) >= 3:
                cv2.fillPoly(mask, [points], 1)
            else:
                mask[:] = 1
            self.mask, self.mask_key, self.previous = mask.astype(bool), key, None
        return self.mask

    def moved(self, frame, polygon, now):
        """True when something moved in the zone since the last check (at most every 0.1 s)."""
        if now - self.checked_at < CHECK_INTERVAL_SECONDS:
            return None
        self.checked_at = now
        height, width = frame.shape[:2]
        size = (SMALL_WIDTH, max(1, round(SMALL_WIDTH * height / width)))
        small = cv2.GaussianBlur(cv2.cvtColor(cv2.resize(frame, size, interpolation=cv2.INTER_AREA), cv2.COLOR_BGR2GRAY), (3, 3), 0)
        mask = self._zone_mask(polygon, size)
        previous, self.previous = self.previous, small
        if previous is None:
            return False
        changed = (cv2.absdiff(small, previous) > PIXEL_DELTA) & mask
        return bool(changed.sum() >= max(1, MOVING_SHARE * mask.sum()))
