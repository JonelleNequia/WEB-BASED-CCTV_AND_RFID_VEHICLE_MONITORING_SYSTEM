"""
A2 (detection): one vehicle type per vehicle, decided from all its frames.

The gate camera films vehicles from behind (for the plate). From behind, an
SUV, AUV (Innova, Avanza), pickup or van looks like a box, and the COCO model
often calls it "truck". So the type is decided like this:

1. Three types only: Car (sedan, hatchback, SUV, AUV, pickup, van),
   Motorcycle (motorcycle, e-bike, tricycle) and Truck/Bus (truck, bus,
   jeepney). Bicycles are never counted.
2. Every frame of the same track votes, weighted by its confidence and by
   how big the vehicle is in that frame (a close, large view is surer).
3. A second check on the best full-resolution crop of the vehicle, with a
   larger input size, counts as much as all the frames together.
4. Size and shape: "Truck/Bus" is kept only when the vehicle's back is tall
   in the gate's zone or narrow like a truck's back; otherwise it is a Car.
   Both limits are settings (Settings › Cameras, later Advanced).
"""

CAR = "Car"
MOTORCYCLE = "Motorcycle"
TRUCK_BUS = "Truck/Bus"
TYPES = (CAR, MOTORCYCLE, TRUCK_BUS)

# Detector label -> type. Anything else (bicycle, person...) is not a vehicle here.
LABEL_TYPES = {
    "car": CAR, "suv": CAR, "van": CAR, "pickup": CAR, "pickup truck": CAR,
    "motorcycle": MOTORCYCLE, "motorbike": MOTORCYCLE, "motor cycle": MOTORCYCLE, "scooter": MOTORCYCLE,
    "electric scooter": MOTORCYCLE, "ebike": MOTORCYCLE, "e bike": MOTORCYCLE, "tricycle": MOTORCYCLE,
    "auto rickshaw": MOTORCYCLE,
    "truck": TRUCK_BUS, "bus": TRUCK_BUS, "jeepney": TRUCK_BUS, "jeep": TRUCK_BUS,  # PH "jeep" = jeepney
}

# Settings › Cameras (exported as system_settings.performance).
DEFAULTS = {
    "type_second_pass": 1,          # second check on the best full-resolution crop
    "type_model": "yolov8s.pt",     # model for the second check (once per vehicle; bigger, surer)
    "type_second_pass_imgsz": 960,  # input size of the second check
    "type_truck_min_height": 0.55,  # a truck's back is at least this share of the zone's height...
    "type_car_min_aspect": 1.25,    # ...or narrower (width / height) than this; else it is a Car
}

# The second check weighs as much as all the frame votes together.
SECOND_PASS_SHARE = 0.5


def type_for_label(label):
    normalized = " ".join(str(label or "").strip().lower().replace("_", " ").replace("-", " ").split())
    return LABEL_TYPES.get(normalized)


def frame_weight(confidence, xyxy, frame_area):
    """Confidence x square root of the share of the frame the vehicle covers."""
    width = max(0.0, float(xyxy[2]) - float(xyxy[0]))
    height = max(0.0, float(xyxy[3]) - float(xyxy[1]))
    share = (width * height) / frame_area if frame_area else 0.0
    return max(0.0, float(confidence)) * (max(share, 0.0) ** 0.5)


class TrackVote:
    """The votes of one track (every frame it was seen in the zone)."""

    def __init__(self):
        self.scores = {}
        self.frames = 0
        self.best = None  # (area, xyxy, frame_size) of the largest view

    def add(self, vehicle_type, confidence, xyxy, frame_size):
        if vehicle_type not in TYPES:
            return
        width, height = frame_size
        self.scores[vehicle_type] = self.scores.get(vehicle_type, 0.0) + frame_weight(confidence, xyxy, width * height)
        self.frames += 1
        area = max(0.0, xyxy[2] - xyxy[0]) * max(0.0, xyxy[3] - xyxy[1])
        if self.best is None or area > self.best[0]:
            self.best = (area, tuple(float(value) for value in xyxy), (int(width), int(height)))

    def best_box(self):
        return self.best[1] if self.best else None


def _normalized(scores):
    total = sum(scores.values())
    return {key: value / total for key, value in scores.items()} if total > 0 else {}


def looks_like_a_car(xyxy, zone_height, settings):
    """
    The size/shape rule: a truck's or bus's back is tall in the zone, or
    tall for its width. A wide, low back is a car (SUV, AUV, pickup, van).
    """
    width = max(1.0, float(xyxy[2]) - float(xyxy[0]))
    height = max(1.0, float(xyxy[3]) - float(xyxy[1]))
    tall_in_zone = zone_height > 0 and height / zone_height >= float(settings["type_truck_min_height"])
    narrow_like_a_truck = width / height < float(settings["type_car_min_aspect"])
    return not tall_in_zone and not narrow_like_a_truck


def decide(vote, second_pass=None, zone_height=0.0, settings=None, fallback=None):
    """
    The final type and why: {"type", "scores", "second_pass", "rule", "frames"}.

    second_pass: {"type", "confidence"} from the full-resolution crop, or None.
    fallback: the trigger frame's type when the track has no votes.
    """
    settings = {**DEFAULTS, **(settings or {})}
    frame_scores = _normalized(vote.scores)
    combined = {key: value * (1 - SECOND_PASS_SHARE if second_pass else 1.0) for key, value in frame_scores.items()}

    if second_pass and second_pass.get("type") in TYPES:
        share = SECOND_PASS_SHARE if frame_scores else 1.0
        combined[second_pass["type"]] = combined.get(second_pass["type"], 0.0) + share * min(1.0, float(second_pass.get("confidence") or 0.0) + 0.5)

    if not combined:
        chosen = fallback if fallback in TYPES else None
        return {"type": chosen, "scores": {}, "second_pass": second_pass, "rule": "trigger frame only", "frames": vote.frames}

    chosen = max(TYPES, key=lambda key: (combined.get(key, 0.0), key == CAR))
    rule = "votes" + (" + second check" if second_pass and second_pass.get("type") in TYPES else "")

    best = vote.best_box()
    if chosen == TRUCK_BUS and best is not None and looks_like_a_car(best, zone_height, settings):
        chosen, rule = CAR, rule + " + size/shape (car-sized back)"

    return {
        "type": chosen,
        "scores": {key: round(value, 3) for key, value in combined.items()},
        "second_pass": second_pass,
        "rule": rule,
        "frames": vote.frames,
    }
