import json

import requests

from config import API_TIMEOUT_SECONDS, RFID_MATCH_TIMEOUT_SECONDS


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
        # Phase 2: every trigger-line crossing with its CCTV direction. Older
        # exports have no crossing_url; it sits next to the events endpoint.
        self.crossing_url = str(system_settings.get("crossing_url", "")).strip() or (
            self.event_ingest_url.rsplit("/", 1)[0] + "/crossings" if self.event_ingest_url else ""
        )
        self.api_key = str(system_settings.get("python_api_key", "")).strip()

    def integration_headers(self, include_json=False):
        """
        Build headers for Laravel integration calls.

        The local offline setup may run without a configured shared key. Laravel
        accepts that only for loopback requests, so the Python client should not
        fail locally before it can ask Laravel for the RFID match.
        """
        headers = {
            "Accept": "application/json",
            "X-Source-Name": "philcst-dual-camera-detector",
        }

        if include_json:
            headers["Content-Type"] = "application/json"

        if self.api_key:
            headers["X-Api-Key"] = self.api_key

        return headers

    def multipart_payload(self, payload):
        """
        Convert Python values into multipart-safe form fields.
        """
        data = {}

        for key, value in payload.items():
            if value is None:
                continue

            if isinstance(value, (dict, list)):
                data[key] = json.dumps(value)
                continue

            data[key] = str(value)

        return data

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
            response = requests.post(
                self.event_ingest_url,
                json=payload,
                headers=self.integration_headers(include_json=True),
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

    def check_rfid_match(self, camera_role, event_time, window_seconds=4, lookback_seconds=10, event_key=None):
        """
        Poll Laravel for a registered tag or guest pass read at one gate, from
        lookback_seconds before the crossing until the detector window ends.
        """
        if not self.rfid_match_url:
            return {
                "matched": False,
                "message": "Laravel RFID match endpoint is not configured.",
            }

        params = {
            "camera_role": camera_role,
            "event_time": event_time,
            "window_seconds": window_seconds,
            "lookback_seconds": lookback_seconds,
        }

        # Lets Laravel give one RFID read to one vehicle only.
        if event_key:
            params["event_key"] = event_key

        try:
            response = requests.get(
                self.rfid_match_url,
                params=params,
                headers=self.integration_headers(),
                timeout=RFID_MATCH_TIMEOUT_SECONDS,
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
                "status": body.get("status"),
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

    def submit_crossing(self, payload, image_bytes=None, filename=None):
        """
        Phase 2: store one crossing (gate, IN / OUT / UNKNOWN, time, track ID,
        confidence, snapshot). The same external_event_key twice is one row.
        """
        if not self.crossing_url:
            return {"accepted": False, "created": False, "message": "Laravel crossing endpoint is not configured."}

        try:
            response = requests.post(
                self.crossing_url,
                data=self.multipart_payload(payload),
                files={"snapshot": (filename or "crossing.jpg", image_bytes, "image/jpeg")} if image_bytes else None,
                headers=self.integration_headers(),
                timeout=API_TIMEOUT_SECONDS,
            )
        except requests.RequestException as error:
            return {"accepted": False, "created": False, "message": f"Could not submit crossing: {error}"}

        try:
            body = response.json()
        except ValueError:
            body = {}

        return {
            "accepted": response.status_code in {200, 201},
            "created": response.status_code == 201,
            "message": body.get("message", response.text),
            "body": body,
        }

    def submit_guest_observation(self, payload, image_bytes=None, filename=None):
        """
        Submit a "Vehicle with no pass" alert after the RFID window expires.
        """
        if not self.guest_observation_url:
            return {
                "accepted": False,
                "created": False,
                "duplicate": False,
                "message": "Laravel guest observation endpoint is not configured.",
            }

        try:
            if image_bytes:
                response = requests.post(
                    self.guest_observation_url,
                    data=self.multipart_payload(payload),
                    files={
                        "snapshot": (
                            filename or "guest_snapshot.jpg",
                            image_bytes,
                            "image/jpeg",
                        ),
                    },
                    headers=self.integration_headers(),
                    timeout=API_TIMEOUT_SECONDS,
                )
            else:
                response = requests.post(
                    self.guest_observation_url,
                    json=payload,
                    headers=self.integration_headers(include_json=True),
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
