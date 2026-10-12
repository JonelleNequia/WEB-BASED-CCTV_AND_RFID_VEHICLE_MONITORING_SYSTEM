"""
Zone (ROI) geometry and the vehicle pass.

ROI work: there is no trigger line any more. A vehicle is a PASS while its
track is in the zone:

    Detected   seen in the zone for a few frames: the pass opens, the RFID
               check starts ("Vehicle detected · checking RFID…")
    Recorded   the record is made once the vehicle moved far enough along
               the IN arrow AND the RFID window is over (even while it is
               still in the zone); it keeps being updated until it leaves
    Left zone  out of the zone, or not seen for a few frames: the pass ends

IN / OUT (into / out of campus) comes from the vehicle's path: the movement
of the bottom-centre of its box (where it touches the ground) along the IN
arrow drawn in Calibration, as a share of the zone's length along that
arrow.
"""

import cv2
import numpy as np


def clamp(value, lower, upper):
    return max(lower, min(value, upper))


def normalized_rect_to_pixels(rect, frame_width, frame_height):
    """
    Convert a saved normalized mask rectangle into pixel coordinates.
    """
    if not rect:
        return None

    x = clamp(int(rect["x"] * frame_width), 0, frame_width)
    y = clamp(int(rect["y"] * frame_height), 0, frame_height)
    width = clamp(int(rect["width"] * frame_width), 0, frame_width)
    height = clamp(int(rect["height"] * frame_height), 0, frame_height)

    return {
        "x": x,
        "y": y,
        "width": width,
        "height": height,
    }


def normalized_polygon_to_pixels(polygon, frame_width, frame_height):
    """
    Convert a saved normalized polygon ROI into pixel coordinates.
    """
    if not polygon:
        return None

    if isinstance(polygon, dict) and {"x", "y", "width", "height"}.issubset(polygon.keys()):
        rect = normalized_rect_to_pixels(polygon, frame_width, frame_height)

        if not rect:
            return None

        return [
            (rect["x"], rect["y"]),
            (rect["x"] + rect["width"], rect["y"]),
            (rect["x"] + rect["width"], rect["y"] + rect["height"]),
            (rect["x"], rect["y"] + rect["height"]),
        ]

    if not isinstance(polygon, list) or len(polygon) < 3:
        return None

    points = []

    for point in polygon:
        if not isinstance(point, dict) or "x" not in point or "y" not in point:
            return None

        points.append((
            clamp(int(float(point["x"]) * frame_width), 0, frame_width),
            clamp(int(float(point["y"]) * frame_height), 0, frame_height),
        ))

    return points


def normalized_arrow_to_pixels(arrow, frame_width, frame_height):
    """
    The IN arrow (tail x1,y1 -> head x2,y2, normalized 0-1) in pixels, or
    None when it is missing or has no length.
    """
    if not isinstance(arrow, dict):
        return None
    try:
        pixels = {
            "x1": float(arrow["x1"]) * frame_width,
            "y1": float(arrow["y1"]) * frame_height,
            "x2": float(arrow["x2"]) * frame_width,
            "y2": float(arrow["y2"]) * frame_height,
        }
    except (KeyError, TypeError, ValueError):
        return None
    if not np.hypot(pixels["x2"] - pixels["x1"], pixels["y2"] - pixels["y1"]):
        return None
    return pixels


def bbox_center(xyxy):
    """
    Resolve the center point of one bounding box.
    """
    x1, y1, x2, y2 = xyxy

    return ((x1 + x2) / 2.0, (y1 + y2) / 2.0)


def bbox_bottom_center(xyxy):
    """Where the vehicle touches the ground: the middle of the box's bottom edge."""
    x1, y1, x2, y2 = xyxy

    return ((x1 + x2) / 2.0, float(y2))


def point_in_polygon(point, polygon):
    """
    Use OpenCV to keep detections whose center point is inside the polygon ROI.
    """
    if not polygon or len(polygon) < 3:
        return False

    contour = np.array(polygon, dtype=np.int32)

    return cv2.pointPolygonTest(contour, (float(point[0]), float(point[1])), False) >= 0


def arrow_unit(arrow):
    """Unit vector of the IN arrow (pixels)."""
    dx, dy = arrow["x2"] - arrow["x1"], arrow["y2"] - arrow["y1"]
    length = float(np.hypot(dx, dy)) or 1.0
    return dx / length, dy / length


def zone_length_along(polygon, unit):
    """The zone's length along a direction: the spread of its corners projected on it."""
    projections = [point[0] * unit[0] + point[1] * unit[1] for point in polygon or []]
    return (max(projections) - min(projections)) if projections else 0.0


def describe_arrow(unit):
    """"down", "up", "right" or "left": where the IN arrow points in the picture."""
    if abs(unit[1]) >= abs(unit[0]):
        return "down" if unit[1] > 0 else "up"
    return "right" if unit[0] > 0 else "left"


def _median_point(points):
    return (float(np.median([point[0] for point in points])), float(np.median([point[1] for point in points])))


# Below this share of the zone a pass never moved at all (a parked vehicle,
# a detection that flickered): it is not counted.
STILL_SHARE = 0.05
# Box height change (last frames against first) that counts as a clear
# "came closer" / "went away" for a medium-confidence direction.
SIZE_CHANGE = 0.20


class VehiclePass:
    """
    One vehicle's pass through the zone (one track = one pass).

    It keeps the bottom-centre of the box and the box height of every frame
    the vehicle is seen in the zone, and how many detection frames in a row
    it was missed (out of the zone, or not seen).
    """

    def __init__(self, xyxy):
        self.points = [bbox_bottom_center(xyxy)]
        self.heights = [float(xyxy[3] - xyxy[1])]
        self.frames = 1
        self.missed = 0
        self.opened = False

    def observe(self, xyxy):
        self.points.append(bbox_bottom_center(xyxy))
        self.heights.append(float(xyxy[3] - xyxy[1]))
        del self.points[:-240]
        del self.heights[:-240]
        self.frames += 1
        self.missed = 0

    def miss(self):
        self.missed += 1
        return self.missed

    def _ends(self):
        count = min(3, max(1, len(self.points) // 3))
        return _median_point(self.points[:count]), _median_point(self.points[-count:])

    def measure(self, arrow, polygon):
        """
        along: movement along the IN arrow as a share of the zone's length
               along it (+ = with the arrow, - = against it)
        moved: how far it is from where it was first seen, any way (share; a
               vehicle that rocks or wobbles in place ends near where it began)
        size:  box height of the last frames against the first (1.0 = same)
        """
        unit = arrow_unit(arrow)
        length = zone_length_along(polygon, unit) or 1.0
        first, last = self._ends()
        along = ((last[0] - first[0]) * unit[0] + (last[1] - first[1]) * unit[1]) / length
        moved = float(np.hypot(last[0] - first[0], last[1] - first[1])) / length
        count = min(3, max(1, len(self.heights) // 3))
        size = float(np.median(self.heights[-count:])) / (float(np.median(self.heights[:count])) or 1.0)
        return {"along": along, "moved": moved, "size": size, "first": first, "last": last, "unit": unit}

    def decide(self, arrow, polygon, min_move, final=False):
        """
        IN / OUT with its confidence, or None while it is not sure yet.

        high:   moved at least `min_move` of the zone along the IN arrow
        medium: moved less, but at least half of that, and the box clearly
                grew while it went down the picture (or shrank going up):
                the movement is real, not jitter
        final:  the pass ended (left the zone, track lost): an undecided pass
                becomes UNKNOWN, a pass that never moved is "still"
        """
        m = self.measure(arrow, polygon)
        along, size = m["along"], m["size"]
        direction = "IN" if along > 0 else "OUT"
        share = round(abs(along) * 100)
        way = "with" if along > 0 else "against"
        result = {"direction": None, "confidence": None, "along": round(along, 3), "moved": round(m["moved"], 3),
                  "size": round(size, 3), "reason": f"moved {share}% of the zone along the IN arrow"}

        if abs(along) >= min_move:
            result.update(direction=direction, confidence="high", reason=f"moved {share}% of the zone {way} the IN arrow")
            return result

        went_down = m["last"][1] > m["first"][1]
        size_agrees = (size >= 1 + SIZE_CHANGE and went_down) or (size <= 1 - SIZE_CHANGE and not went_down)
        if abs(along) >= max(STILL_SHARE, min_move / 2) and size_agrees:
            change = "grew" if size > 1 else "shrank"
            result.update(direction=direction, confidence="medium",
                          reason=f"moved {share}% of the zone {way} the IN arrow; box {change} {round(abs(size - 1) * 100)}%")
            return result

        if final:
            if m["moved"] < STILL_SHARE:
                result.update(direction="STILL", reason="did not move")
            else:
                result.update(direction="UNKNOWN", reason=f"Direction unknown: moved only {share}% of the zone along the IN arrow")
        return result


def calibration_ready(camera_config):
    """
    Automatic records need the zone and the IN arrow.
    """
    return bool(camera_config.get("calibration_mask")) and bool(camera_config.get("calibration_direction"))
