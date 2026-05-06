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


def normalized_line_to_pixels(line, frame_width, frame_height):
    """
    Convert a saved normalized trigger line into pixel coordinates.
    """
    if not line:
        return None

    return {
        "x1": clamp(int(line["x1"] * frame_width), 0, frame_width),
        "y1": clamp(int(line["y1"] * frame_height), 0, frame_height),
        "x2": clamp(int(line["x2"] * frame_width), 0, frame_width),
        "y2": clamp(int(line["y2"] * frame_height), 0, frame_height),
    }


def bbox_center(xyxy):
    """
    Resolve the center point of one bounding box.
    """
    x1, y1, x2, y2 = xyxy

    return ((x1 + x2) / 2.0, (y1 + y2) / 2.0)


def _orientation(a, b, c):
    """
    Return the orientation for three points.
    """
    value = (b[1] - a[1]) * (c[0] - b[0]) - (b[0] - a[0]) * (c[1] - b[1])

    if value > 0:
        return 1

    if value < 0:
        return -1

    return 0


def _on_segment(a, b, c):
    """
    Check whether point b sits on line segment ac.
    """
    return (
        min(a[0], c[0]) <= b[0] <= max(a[0], c[0])
        and min(a[1], c[1]) <= b[1] <= max(a[1], c[1])
    )


def _segments_intersect(a, b, c, d):
    """
    Check whether segment ab intersects segment cd.
    """
    orientation_1 = _orientation(a, b, c)
    orientation_2 = _orientation(a, b, d)
    orientation_3 = _orientation(c, d, a)
    orientation_4 = _orientation(c, d, b)

    if orientation_1 != orientation_2 and orientation_3 != orientation_4:
        return True

    if orientation_1 == 0 and _on_segment(a, c, b):
        return True

    if orientation_2 == 0 and _on_segment(a, d, b):
        return True

    if orientation_3 == 0 and _on_segment(c, a, d):
        return True

    return orientation_4 == 0 and _on_segment(c, b, d)


def bbox_intersects_line(xyxy, line):
    """
    Treat a vehicle as triggered when its box touches the saved trigger line.
    This handles tracks that start on the line and never get a center-point
    sign flip.
    """
    if not line:
        return False

    x1, y1, x2, y2 = xyxy
    line_start = (line["x1"], line["y1"])
    line_end = (line["x2"], line["y2"])

    if (
        min(x1, x2) <= line_start[0] <= max(x1, x2)
        and min(y1, y2) <= line_start[1] <= max(y1, y2)
    ):
        return True

    if (
        min(x1, x2) <= line_end[0] <= max(x1, x2)
        and min(y1, y2) <= line_end[1] <= max(y1, y2)
    ):
        return True

    rect_edges = [
        ((x1, y1), (x2, y1)),
        ((x2, y1), (x2, y2)),
        ((x2, y2), (x1, y2)),
        ((x1, y2), (x1, y1)),
    ]

    return any(_segments_intersect(line_start, line_end, edge_start, edge_end) for edge_start, edge_end in rect_edges)


def point_in_mask(point, mask_rect):
    """
    Keep detections inside the saved mask rectangle only.
    """
    if not mask_rect:
        return False

    x, y = point

    return (
        mask_rect["x"] <= x <= mask_rect["x"] + mask_rect["width"]
        and mask_rect["y"] <= y <= mask_rect["y"] + mask_rect["height"]
    )


def point_in_polygon(point, polygon):
    """
    Use OpenCV to keep detections whose center point is inside the polygon ROI.
    """
    if not polygon or len(polygon) < 3:
        return False

    contour = np.array(polygon, dtype=np.int32)

    return cv2.pointPolygonTest(contour, (float(point[0]), float(point[1])), False) >= 0


def point_side_of_line(point, line):
    """
    Return which side of the trigger line the point is on.
    """
    if not line:
        return 0

    x, y = point
    line_value = (
        (line["x2"] - line["x1"]) * (y - line["y1"])
        - (line["y2"] - line["y1"]) * (x - line["x1"])
    )

    if line_value > 0:
        return 1

    if line_value < 0:
        return -1

    return 0


def crossed_line(previous_side, current_side):
    """
    Treat a sign flip as one valid line crossing.
    """
    if previous_side in (None, 0) or current_side == 0:
        return False

    return previous_side != current_side


def calibration_ready(camera_config):
    """
    The detector can trigger from either a saved ROI mask or a trigger line.
    """
    return bool(camera_config.get("calibration_mask")) or bool(camera_config.get("calibration_line"))
