import requests

from config import API_TIMEOUT_SECONDS


class LaravelEventClient:
    """
    Thin HTTP client for posting detected crossing events back into Laravel.
    
    Expected payload format:
    {
        "camera_id": int,
        "direction": "IN" | "OUT",
        "plate_number": str | null,
        "image_path": str
    }
    """

    def __init__(self, runtime_config):
        system_settings = runtime_config.get("system_settings", {})
        self.event_ingest_url = str(system_settings.get("event_ingest_url", "")).strip()
        self.guest_observation_url = str(system_settings.get("guest_observation_url", "")).strip()
        self.rfid_match_url = str(system_settings.get("rfid_match_url", "")).strip()
        self.api_key = str(system_settings.get("python_api_key", "")).strip()
        self.session = requests.Session()

    def submit_event(self, payload):
        """
        Submit one detected vehicle crossing into Laravel.

        Returns a small result payload so the detector can distinguish:
        - a newly created event
        - a duplicate event already stored in Laravel
        - a rejected or failed request
        
        Args:
            payload: dict with keys:
                - camera_id (int): Camera identifier
                - direction (str): 'IN' or 'OUT'
                - plate_number (str|None): License plate number
                - image_path (str): Path to saved vehicle image
                
        Returns:
            dict: {
                "accepted": bool,
                "created": bool,
                "duplicate": bool,
                "message": str
            }
        """
        if not self.event_ingest_url:
            return {
                "accepted": False,
                "created": False,
                "duplicate": False,
                "message": "Laravel event endpoint is not configured.",
            }

        if not self.api_key:
            return {
                "accepted": False,
                "created": False,
                "duplicate": False,
                "message": "Python API key is missing from Laravel settings.",
            }

        if payload.get("external_event_key") or payload.get("camera_role"):
            required_fields = [
                "external_event_key",
                "camera_role",
                "detected_vehicle_type",
                "event_time",
            ]
        else:
            required_fields = ["camera_id", "direction", "image_path"]

        missing_fields = [field for field in required_fields if field not in payload or payload[field] is None]
        
        if missing_fields:
            return {
                "accepted": False,
                "created": False,
                "duplicate": False,
                "message": f"Missing required fields: {', '.join(missing_fields)}",
            }

        # Validate direction value
        if payload.get("direction") is not None and payload.get("direction") not in ["IN", "OUT"]:
            return {
                "accepted": False,
                "created": False,
                "duplicate": False,
                "message": f"Invalid direction '{payload.get('direction')}'. Must be 'IN' or 'OUT'.",
            }

        try:
            response = self.session.post(
                self.event_ingest_url,
                json=payload,
                headers={
                    "Accept": "application/json",
                    "Content-Type": "application/json",
                    "X-Api-Key": self.api_key,
                    "X-Source-Name": "philcst-dual-camera-detector",
                },
                timeout=API_TIMEOUT_SECONDS,
            )
        except requests.RequestException as error:
            return {
                "accepted": False,
                "created": False,
                "duplicate": False,
                "message": f"Could not reach Laravel event endpoint: {error}",
            }

        try:
            body = response.json()
        except ValueError:
            body = {}

        if response.status_code in {200, 201, 202}:
            return {
                "accepted": True,
                "created": response.status_code == 201,
                "duplicate": bool(body.get("duplicate", response.status_code == 200)),
                "message": body.get("message", "Event accepted."),
                "body": body,
                "overlay": body.get("overlay"),
                "requires_capture": bool(body.get("requires_capture", False)),
            }

        return {
            "accepted": False,
            "created": False,
            "duplicate": False,
            "message": body.get("message", response.text),
            "body": body,
            "overlay": body.get("overlay"),
            "requires_capture": bool(body.get("requires_capture", False)),
        }

    def check_rfid_match(self, camera_role, event_time, window_seconds=5):
        """
        Poll Laravel for a verified RFID scan at one gate during the detector window.
        """
        if not self.rfid_match_url:
            return {
                "matched": False,
                "message": "Laravel RFID match endpoint is not configured.",
            }

        if not self.api_key:
            return {
                "matched": False,
                "message": "Python API key is missing from Laravel settings.",
            }

        try:
            response = self.session.get(
                self.rfid_match_url,
                params={
                    "camera_role": camera_role,
                    "event_time": event_time,
                    "window_seconds": window_seconds,
                },
                headers={
                    "Accept": "application/json",
                    "X-Api-Key": self.api_key,
                    "X-Source-Name": "philcst-dual-camera-detector",
                },
                timeout=1.0,
            )
        except requests.RequestException as error:
            return {
                "matched": False,
                "message": f"Could not check RFID match: {error}",
            }

        try:
            body = response.json()
        except ValueError:
            body = {}

        if response.status_code == 200:
            return {
                "matched": bool(body.get("matched", False)),
                "message": body.get("message", "RFID match checked."),
                "body": body,
                "overlay": body.get("overlay"),
                "scan": body.get("scan"),
            }

        return {
            "matched": False,
            "message": body.get("message", response.text),
            "body": body,
        }

    def submit_guest_observation(self, payload):
        """
        Submit an unregistered/guest detector capture after the RFID window expires.
        """
        if not self.guest_observation_url:
            return {
                "accepted": False,
                "created": False,
                "duplicate": False,
                "message": "Laravel guest observation endpoint is not configured.",
            }

        if not self.api_key:
            return {
                "accepted": False,
                "created": False,
                "duplicate": False,
                "message": "Python API key is missing from Laravel settings.",
            }

        try:
            response = self.session.post(
                self.guest_observation_url,
                json=payload,
                headers={
                    "Accept": "application/json",
                    "Content-Type": "application/json",
                    "X-Api-Key": self.api_key,
                    "X-Source-Name": "philcst-dual-camera-detector",
                },
                timeout=API_TIMEOUT_SECONDS,
            )
        except requests.RequestException as error:
            return {
                "accepted": False,
                "created": False,
                "duplicate": False,
                "message": f"Could not submit guest observation: {error}",
            }

        try:
            body = response.json()
        except ValueError:
            body = {}

        if response.status_code in {200, 201}:
            return {
                "accepted": True,
                "created": response.status_code == 201,
                "duplicate": bool(body.get("duplicate", response.status_code == 200)),
                "message": body.get("message", "Guest observation accepted."),
                "body": body,
                "overlay": body.get("overlay"),
            }

        return {
            "accepted": False,
            "created": False,
            "duplicate": False,
            "message": body.get("message", response.text),
            "body": body,
            "overlay": body.get("overlay"),
        }
