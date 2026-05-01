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
