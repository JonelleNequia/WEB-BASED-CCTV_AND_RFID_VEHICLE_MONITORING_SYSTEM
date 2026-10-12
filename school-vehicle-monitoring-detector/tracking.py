"""
Zone (ROI) geometry and the vehicle pass.

ROI work: there is no trigger line and no IN arrow. A vehicle is a PASS
while its track is in the zone:

    Detected   seen in the zone for a few frames: the pass opens, the RFID
               check starts ("Vehicle detected · checking RFID…")
    Recorded   the record is made once it is clear whether the vehicle drives
               away from the camera or toward it AND the RFID window is over
               (even while it is still in the zone); it keeps being updated
               until it leaves
    Left zone  out of the zone, or not seen for a few frames: the pass ends

Away from the camera = the box shrinks AND its bottom-centre (where it
touches the ground) goes up the picture; toward the camera = it grows AND
goes down. Each gate says which of the two is IN (into campus): "a vehicle
moving away from the camera is IN / OUT". Until that is set every pass is
"Direction unknown".
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


def normalized_away_direction(value):
    """The gate's setting: "IN" / "OUT" for a vehicle moving away from the camera, or None (not set)."""
    value = str(value or "").strip().upper()
    return value if value in {"IN", "OUT"} else None


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


def zone_height(polygon):
    """The zone's height in the picture (pixels)."""
    ys = [point[1] for point in polygon or []]
    return (max(ys) - min(ys)) if ys else 0.0


def touches_frame_edge(xyxy, frame_size, margin=3):
    """The box reaches the edge of the picture: the vehicle is cut off, so its box size is not its real size."""
    if not frame_size:
        return False
    width, height = frame_size
    x1, y1, x2, y2 = xyxy
    return x1 <= margin or y1 <= margin or x2 >= width - margin or y2 >= height - margin


def _median_point(points):
    return (float(np.median([point[0] for point in points])), float(np.median([point[1] for point in points])))


# Below this share of the zone a pass never moved at all (a parked vehicle,
# a detection that flickered): it is not counted.
STILL_SHARE = 0.05
# Box height change (last frames against first) that counts as a clear
# "came closer" (grew) / "went away" (shrank).
SIZE_CHANGE = 0.20

OPPOSITE = {"IN": "OUT", "OUT": "IN"}


class VehiclePass:
    """
    One vehicle's pass through the zone (one track = one pass).

    It keeps the bottom-centre of the box, the box height and whether the
    box touched the edge of the picture for every frame the vehicle is seen
    in the zone, and how many detection frames in a row it was missed (out
    of the zone, or not seen).
    """

    def __init__(self, xyxy, frame_size=None):
        self.frame_size = frame_size
        self.points = []
        self.heights = []
        self.clipped = []
        self.frames = 0
        self.missed = 0
        self.opened = False
        self.observe(xyxy)

    def observe(self, xyxy):
        self.points.append(bbox_bottom_center(xyxy))
        self.heights.append(float(xyxy[3] - xyxy[1]))
        self.clipped.append(touches_frame_edge(xyxy, self.frame_size))
        del self.points[:-240]
        del self.heights[:-240]
        del self.clipped[:-240]
        self.frames += 1
        self.missed = 0

    def miss(self):
        self.missed += 1
        return self.missed

    def _ends(self):
        count = min(3, max(1, len(self.points) // 3))
        return _median_point(self.points[:count]), _median_point(self.points[-count:])

    def _size(self):
        """Box height of the last frames against the first, from frames where the box is whole (None: cannot tell)."""
        whole = [height for height, clipped in zip(self.heights, self.clipped) if not clipped]
        if len(whole) < 2:
            return None
        count = min(3, max(1, len(whole) // 3))
        first = float(np.median(whole[:count])) or 1.0
        return float(np.median(whole[-count:])) / first

    def measure(self, polygon):
        """
        rise:  how far it went UP the picture as a share of the zone's
               height (+ = up = away from the camera, - = down = toward it)
        moved: how far it is from where it was first seen, any way (share; a
               vehicle that rocks or wobbles in place ends near where it began)
        size:  box height of the last frames against the first (1.0 = same;
               None when the box was cut off by the edge of the picture)
        """
        height = zone_height(polygon) or 1.0
        first, last = self._ends()
        rise = (first[1] - last[1]) / height
        moved = float(np.hypot(last[0] - first[0], last[1] - first[1])) / height
        return {"rise": rise, "moved": moved, "size": self._size()}

    def decide(self, polygon, min_move, away_direction=None, final=False):
        """
        Away from the camera or toward it, as IN / OUT with its confidence,
        or None while it is not sure yet.

        high:    moved at least `min_move` of the zone's height up or down,
                 and the box size changed the same way (shrank going up)
        medium:  moved that far but the size does not tell (cut off, about
                 the same); or moved less, but at least half of that, and
                 the size clearly agrees
        unknown: the movement and the size disagree (only when the pass
                 ends: more frames may still settle it), or the gate's
                 "away from the camera" is not set
        final:   the pass ended (left the zone, track lost): an undecided
                 pass becomes UNKNOWN, a pass that never moved is "still"
        """
        m = self.measure(polygon)
        rise, size = m["rise"], m["size"]
        way = "away" if rise > 0 else "toward"
        share = round(abs(rise) * 100)
        moved_text = f"went {'up' if rise > 0 else 'down'} {share}% of the zone"
        size_way = None
        if size is not None and size <= 1 - SIZE_CHANGE:
            size_way = "away"
        elif size is not None and size >= 1 + SIZE_CHANGE:
            size_way = "toward"
        if size is None:
            size_text = "box size unclear (cut off by the edge)"
        elif size_way is None:
            size_text = f"box size changed only {round(abs(size - 1) * 100)}%"
        else:
            size_text = f"box {'grew' if size > 1 else 'shrank'} {round(abs(size - 1) * 100)}%"
        result = {"direction": None, "confidence": None, "movement": None, "rise": round(rise, 3),
                  "moved": round(m["moved"], 3), "size": None if size is None else round(size, 3),
                  "reason": f"{moved_text}; {size_text}"}

        confidence = None
        if abs(rise) >= min_move:
            if size_way is None:
                confidence = "medium"
            elif size_way == way:
                confidence = "high"
            elif final:
                result.update(direction="UNKNOWN",
                              reason=f"Direction unknown: {moved_text} but the {size_text} (they disagree)")
                return result
        elif abs(rise) >= max(STILL_SHARE, min_move / 2) and size_way == way:
            confidence = "medium"

        if confidence:
            camera_way = "away from the camera" if way == "away" else "toward the camera"
            result.update(movement=way, confidence=confidence, reason=f"{camera_way}: {moved_text}; {size_text}")
            gate_in = normalized_away_direction(away_direction)
            if gate_in is None:
                result.update(direction="UNKNOWN", confidence=None,
                              reason=f"Direction unknown: {camera_way}, but this gate's direction is not set")
            else:
                result["direction"] = gate_in if way == "away" else OPPOSITE[gate_in]
            return result

        if final:
            if m["moved"] < STILL_SHARE:
                result.update(direction="STILL", reason="did not move")
            else:
                result.update(direction="UNKNOWN", reason=f"Direction unknown: {moved_text}; {size_text}")
        return result


def _segments_cross(a, b, c, d):
    def side(p, q, r):
        return (q[0] - p[0]) * (r[1] - p[1]) - (q[1] - p[1]) * (r[0] - p[0])
    d1, d2, d3, d4 = side(c, d, a), side(c, d, b), side(a, b, c), side(a, b, d)
    return ((d1 > 0) != (d2 > 0)) and ((d3 > 0) != (d4 > 0)) and 0 not in (d1, d2, d3, d4)


def calibration_problem(camera_config):
    """
    Why the saved zone cannot be used, or None when it is fine. The zone is
    saved as points 0-1 of the picture, so it fits any stream size (the
    detector scales it to the frame it reads).
    """
    mask = camera_config.get("calibration_mask")
    if not mask:
        return "No detection zone yet."
    if isinstance(mask, dict) and {"x", "y", "width", "height"} <= set(mask):
        mask = [{"x": mask["x"], "y": mask["y"]}, {"x": mask["x"] + mask["width"], "y": mask["y"]},
                {"x": mask["x"] + mask["width"], "y": mask["y"] + mask["height"]}, {"x": mask["x"], "y": mask["y"] + mask["height"]}]
    try:
        points = [(float(point["x"]), float(point["y"])) for point in mask]
    except (KeyError, TypeError, ValueError):
        return "The saved zone is not a list of points."
    if len(points) < 3:
        return "The saved zone has fewer than 3 points."
    outside = [point for point in points if not (-0.001 <= point[0] <= 1.001 and -0.001 <= point[1] <= 1.001)]
    if outside:
        return f"The saved zone has points outside the picture (x={outside[0][0]:g}, y={outside[0][1]:g}; points must be 0-1)."
    count = len(points)
    for i in range(count):
        for j in range(i + 1, count):
            if j == i + 1 or (i == 0 and j == count - 1):
                continue
            if _segments_cross(points[i], points[(i + 1) % count], points[j], points[(j + 1) % count]):
                return "The saved zone's edges cross each other."
    area = abs(sum(a[0] * b[1] - b[0] * a[1] for a, b in zip(points, points[1:] + points[:1]))) / 2
    if area < 0.01:
        return f"The saved zone covers only {area * 100:.1f}% of the picture."
    return None


def calibration_ready(camera_config):
    """
    Automatic records need a usable zone. (IN / OUT also needs the gate's
    "away from the camera" setting; without it every pass is Direction
    unknown, but it is still Detected and Recorded.)
    """
    return calibration_problem(camera_config) is None
