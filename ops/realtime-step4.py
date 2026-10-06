#!/usr/bin/env python3
"""Run the Step 4 authenticated WSS smoke as the detected TaskEsc PHP-FPM user."""
import argparse
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import re
import subprocess
import sys

SDK_VERSION = '5.7.4'


def need(condition, code):
    if not condition:
        raise RuntimeError(code)


def load_step1(deploy_path):
    path = deploy_path / 'ops' / 'realtime-step1.py'
    need(path.is_file(), 'step1_helper_missing')
    spec = importlib.util.spec_from_file_location('taskesc_realtime_step1_step4', path)
    need(spec is not None and spec.loader is not None, 'step1_helper_invalid')
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


def run_as(identity, command, env=None, timeout=45):
    def drop():
        os.initgroups(identity['user'], identity['gid'])
        os.setgid(identity['gid'])
        os.setuid(identity['uid'])
    return subprocess.run(command, capture_output=True, text=True, timeout=timeout,
                          preexec_fn=drop, env=env)


def verify_sdk(deploy_path):
    vendor = deploy_path / 'public' / 'assets' / 'vendor'
    js = vendor / f'centrifuge-{SDK_VERSION}.js'
    checksum = vendor / f'centrifuge-{SDK_VERSION}.sha256'
    license_file = vendor / 'centrifuge-js.LICENSE'
    need(js.is_file() and checksum.is_file() and license_file.is_file(), 'local_sdk_missing')
    line = checksum.read_text().strip().split()
    need(len(line) == 2 and line[1] == js.name and re.fullmatch(r'[a-f0-9]{64}', line[0]) is not None,
         'local_sdk_checksum_invalid')
    digest = hashlib.sha256(js.read_bytes()).hexdigest()
    need(digest == line[0], 'local_sdk_checksum_mismatch')
    index = (deploy_path / 'public' / 'index.php').read_text()
    need(f'/assets/vendor/centrifuge-{SDK_VERSION}.js' in index, 'local_sdk_not_loaded')
    need('unpkg.com/centrifuge' not in index and 'cdn.jsdelivr.net' not in index, 'realtime_cdn_forbidden')
    return digest


def parse_last_json(text):
    lines = [line.strip() for line in text.splitlines() if line.strip()]
    need(lines, 'realtime_smoke_empty')
    try:
        value = json.loads(lines[-1])
    except Exception:
        raise RuntimeError('realtime_smoke_invalid')
    need(isinstance(value, dict), 'realtime_smoke_invalid')
    return value


def smoke(identity, deploy_path):
    digest = verify_sdk(deploy_path)
    php = subprocess.run(['bash', '-lc', 'command -v php'], capture_output=True, text=True, timeout=5).stdout.strip()
    need(php, 'php_cli_missing')
    script = deploy_path / 'bin' / 'realtime-smoke.php'
    need(script.is_file(), 'realtime_smoke_missing')
    env = os.environ.copy()
    env['TASKESC_REALTIME_SMOKE'] = '1'
    probe = run_as(identity, [php, str(script)], env=env)
    data = parse_last_json(probe.stdout)
    safe = {
        'status': data.get('status'),
        'login': data.get('login'),
        'connection_jwt': data.get('connection_jwt'),
        'subscription_jwt': data.get('subscription_jwt'),
        'unauthorized_channel_denied': data.get('unauthorized_channel_denied'),
        'tokenless_subscribe_denied': data.get('tokenless_subscribe_denied'),
        'channel_mismatch_denied': data.get('channel_mismatch_denied'),
        'websocket_authenticated': data.get('websocket_authenticated'),
        'centrifugo_expected': data.get('centrifugo_expected'),
        'sdk_local': True,
        'sdk_sha256': digest,
    }
    print(json.dumps(safe, separators=(',', ':')))
    need(probe.returncode == 0 and all(safe.get(key) is True for key in [
        'login', 'connection_jwt', 'subscription_jwt', 'unauthorized_channel_denied',
        'tokenless_subscribe_denied', 'channel_mismatch_denied', 'websocket_authenticated',
    ]), 'realtime_smoke_failed')


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('mode', choices=['smoke'])
    parser.add_argument('--deploy-path', required=True)
    args = parser.parse_args()
    need(os.geteuid() == 0, 'root_required')
    deploy_path = Path(args.deploy_path).resolve(strict=True)
    step1 = load_step1(deploy_path)
    identity = step1.discover(deploy_path)
    smoke(identity, deploy_path)


if __name__ == '__main__':
    try:
        main()
    except Exception as error:
        code = str(error) if type(error) is RuntimeError and re.fullmatch(r'[a-z0-9_]+', str(error)) else 'realtime_step4_failed'
        print(json.dumps({'status': 'error', 'code': code}), file=sys.stderr)
        sys.exit(1)
