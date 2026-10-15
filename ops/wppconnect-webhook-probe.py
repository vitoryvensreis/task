#!/usr/bin/env python3
"""Read-only WPPConnect webhook connectivity probe. Never prints webhook token."""
import json
import os
from pathlib import Path
import subprocess
import sys
from urllib import request, error


def run(argv, timeout=10):
    try:
        return subprocess.run(
            argv, capture_output=True, text=True, timeout=timeout, check=False
        )
    except (OSError, subprocess.TimeoutExpired):
        return None


def sanitize_env(line):
    if not line or "=" not in line:
        return None
    key, value = line.split("=", 1)
    if key.strip() == "WEBHOOK_URL":
        value = value.strip()
        if len(value) >= 2 and value[0] in "\"'" and value[-1] == value[0]:
            value = value[1:-1]
        return value
    return None


def main():
    root = Path(__file__).resolve().parents[1]
    php = run([
        "php", "-r",
        'require "app/bootstrap.php"; echo WppConnect::webhookUrl();',
    ], timeout=12)
    if not php or php.returncode or not php.stdout.startswith("https://"):
        return {"status": "unavailable", "code": "expected_webhook_not_available"}
    expected = php.stdout.strip()
    expected_set = len(expected) > 60 and "token=" in expected
    dotenv = root / "ops" / "wppconnect" / ".env"
    dotenv_url = None
    dotenv_exists = dotenv.is_file()
    if dotenv_exists:
        for line in dotenv.read_text().splitlines():
            value = sanitize_env(line)
            if value is not None:
                dotenv_url = value
    dock = run(["docker", "inspect", "taskesc-wppconnect"], timeout=12)
    docker = {"available": bool(dock and dock.returncode == 0)}
    if docker["available"]:
        try:
            data = json.loads(dock.stdout)[0]
            state = data.get("State") or {}
            raw_env = (data.get("Config") or {}).get("Env") or []
            docker_env_url = next((e.split("=", 1)[1] for e in raw_env
                if e.startswith("WEBHOOK_URL=")), None)
            docker.update({
                "running": bool(state.get("Running")),
                "restart_count": int(data.get("RestartCount") or 0),
                "started_utc": state.get("StartedAt", "")[:19],
                "url_present": bool(docker_env_url),
                "matches_taskesc": bool(expected_set and docker_env_url == expected)
            })
        except (ValueError, TypeError, IndexError, KeyError):
            docker["inspect_parsing"] = "unavailable"
    ingress = {"status": "not_tested"}
    if expected_set:
        # Unknown event must be ignored; its HTTP status verifies external ingress
        # without entering the AI inbox or causing a business operation.
        ping = request.Request(expected, data=b'{"event":"taskesc-health-probe"}',
            method="POST", headers={"Content-Type":"application/json",
                                     "User-Agent":"TaskEsc-WppWebhookHealth/1"})
        try:
            with request.urlopen(ping, timeout=12) as response:
                payload = json.loads(response.read(1024))
                ingress = {
                    "http_status": response.status,
                    "accepted": bool(payload.get("ok")),
                    "ignored": payload.get("reason") == "event_ignored"
                }
        except error.HTTPError as exc:
            ingress = {"http_status": int(exc.code), "accepted": False}
        except Exception:
            ingress = {"status": "network_or_response_error"}
    return {
        "status": "ok",
        "expected_webhook_present": expected_set,
        "dotenv_exists": dotenv_exists,
        "dotenv_url_present": bool(dotenv_url),
        "dotenv_matches_taskesc": bool(expected_set and dotenv_url == expected),
        "docker": docker,
        "ingress": ingress
    }


if __name__ == "__main__":
    try:
        print(json.dumps(main(), separators=(",", ":")))
    except Exception:
        print(json.dumps({"status":"unavailable","code":"webhook_probe_failed"}))
        sys.exit(1)
