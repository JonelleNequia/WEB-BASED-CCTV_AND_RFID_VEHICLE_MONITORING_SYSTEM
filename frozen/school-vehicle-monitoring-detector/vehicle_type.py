"""Confidence/frame votes over real model labels; no box-shape subtype inference."""

CAR = "Car"
MOTORCYCLE = "Motorcycle"
TRUCK_BUS = "Truck/Bus"
TRUCK = "Truck"
BUS = "Bus"
TYPES = (CAR, MOTORCYCLE, TRUCK, BUS, TRUCK_BUS, "E-bike", "E-trike", "E-car", "Tricycle",
         "Van", "SUV", "AUV", "MPV", "Pickup", "Jeepney", "Sedan", "Hatchback", "Scooter", "Electric scooter")

# A label is used only when advertised by the loaded model's names metadata.
LABEL_TYPES = {label.lower().replace("-", " "): label for label in TYPES}
LABEL_TYPES.update({
    "pickup truck": "Pickup", "motorbike": MOTORCYCLE, "motor cycle": MOTORCYCLE,
    "ebike": "E-bike", "electric bicycle": "E-bike", "electric bike": "E-bike",
    "etrike": "E-trike", "electric tricycle": "E-trike", "electric trike": "E-trike",
    "ecar": "E-car", "electric car": "E-car", "auto rickshaw": "Tricycle", "jeep": "Jeepney",
})

# Settings › Cameras (exported as system_settings.performance).
DEFAULTS = {
    "type_second_pass": 1,          # second check on the best full-resolution crop
    "type_model": "yolov8s.pt",     # model for the second check (once per vehicle; bigger, surer)
    "type_second_pass_imgsz": 960,  # input size of the second check
    "type_truck_min_height": 0.35,  # legacy configuration retained; no geometric relabelling
    "type_car_min_aspect": 1.05,    # legacy configuration retained; not a type decision
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

    leaders = [key for key, value in combined.items() if abs(value - max(combined.values())) < 1e-9]
    chosen = leaders[0] if len(leaders) == 1 else None
    rule = "votes" + (" + second check" if second_pass and second_pass.get("type") in TYPES else "")

    if chosen is None:
        rule = "uncertain model votes"

    return {
        "type": chosen,
        "scores": {key: round(value, 3) for key, value in combined.items()},
        "second_pass": second_pass,
        "rule": rule,
        "frames": vote.frames,
    }
