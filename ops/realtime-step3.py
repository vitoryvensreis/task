#!/usr/bin/env python3
"""Install/check the TaskEsc realtime worker without printing credentials."""
import argparse
import importlib.util
import json
import os
from pathlib import Path
import re
import shutil
import subprocess
import sys
import time

SERVICE = 'taskesc-realtime-worker.service'
UNIT_PATH = Path('/etc/systemd/system') / SERVICE


def need(condition, code):
    if not condition:
        raise RuntimeError(code)


def load_step1(deploy_path):
    path = deploy_path / 'ops' / 'realtime-step1.py'
    need(path.is_file(), 'step1_helper_missing')
    spec = importlib.util.spec_from_file_location('taskesc_realtime_step1', path)
    need(spec is not None and spec.loader is not None, 'step1_helper_invalid')
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


def run_as(identity, command, timeout=30):
    def drop():
        os.initgroups(identity['user'], identity['gid'])
        os.setgid(identity['gid'])
        os.setuid(identity['uid'])
    return subprocess.run(command, capture_output=True, text=True, timeout=timeout, preexec_fn=drop)


def worker_php(deploy_path):
    php = shutil.which('php')
    need(php is not None, 'php_cli_missing')
    worker = deploy_path / 'bin' / 'realtime-worker.php'
    need(worker.is_file(), 'worker_script_missing')
    return php, worker


def install(identity, deploy_path):
    php, worker = worker_php(deploy_path)
    template = deploy_path / 'ops' / 'taskesc-realtime-worker.service'
    need(template.is_file(), 'worker_unit_template_missing')
    text = template.read_text()
    replacements = {
        '@TASKESC_USER@': identity['user'], '@TASKESC_GROUP@': identity['group'],
        '@DEPLOY_PATH@': str(deploy_path), '@PHP_BIN@': php,
    }
    for key, value in replacements.items():
        need('\n' not in value and '\r' not in value, 'worker_unit_value_invalid')
        text = text.replace(key, value)
    need('@' not in text, 'worker_unit_placeholder_remaining')
    temp = UNIT_PATH.with_suffix('.tmp')
    temp.write_text(text)
    os.chmod(temp, 0o644)
    os.replace(temp, UNIT_PATH)
    subprocess.run(['systemctl', 'daemon-reload'], check=True, timeout=20)
    subprocess.run(['systemctl', 'enable', SERVICE], check=True, timeout=20, capture_output=True)
    subprocess.run(['systemctl', 'restart', SERVICE], check=True, timeout=20)
    deadline = time.time() + 20
    last_safe = {'active': 'unknown', 'health': 'unknown'}
    while time.time() < deadline:
        active = subprocess.run(['systemctl', 'is-active', SERVICE], capture_output=True, text=True).stdout.strip()
        last_safe['active'] = active
        if active == 'active':
            probe = run_as(identity, [php, str(worker), '--health'], timeout=10)
            try:
                data = parse_single_json(probe.stdout)
                last_safe = {
                    'active': active,
                    'health': data.get('status'),
                    'worker': data.get('worker'),
                    'queue': data.get('queue'),
                    'alerts': data.get('alerts'),
                    'realtime': data.get('realtime'),
                }
            except Exception:
                last_safe = {'active': active, 'health': 'probe_invalid'}
            if probe.returncode == 0:
                return
        time.sleep(1)
    print(json.dumps({'worker_install_probe': last_safe}, separators=(',', ':')))
    raise RuntimeError('worker_start_failed')


def parse_single_json(text):
    lines = [line.strip() for line in text.splitlines() if line.strip()]
    need(lines, 'worker_probe_empty')
    try:
        value = json.loads(lines[-1])
    except Exception:
        raise RuntimeError('worker_probe_invalid')
    need(isinstance(value, dict), 'worker_probe_invalid')
    return value


def check(identity, deploy_path):
    php, worker = worker_php(deploy_path)
    active = subprocess.run(['systemctl', 'is-active', SERVICE], capture_output=True, text=True).stdout.strip()
    enabled = subprocess.run(['systemctl', 'is-enabled', SERVICE], capture_output=True, text=True).stdout.strip()
    probe = run_as(identity, [php, str(worker), '--health'], timeout=15)
    data = parse_single_json(probe.stdout)
    safe = {
        'service': SERVICE,
        'active': active,
        'enabled': enabled,
        'health': data.get('status'),
        'worker': data.get('worker'),
        'queue': data.get('queue'),
        'realtime': data.get('realtime'),
    }
    print(json.dumps(safe, separators=(',', ':')))
    need(active == 'active' and enabled in ('enabled', 'enabled-runtime') and probe.returncode == 0, 'worker_health_failed')


def diagnostic(identity, deploy_path):
    php, worker = worker_php(deploy_path)
    probe = run_as(identity, [php, str(worker), '--diagnostic', '--once', '--batch=20'], timeout=30)
    data = parse_single_json(probe.stdout)
    safe = {'diagnostic': data.get('diagnostic'), 'event_id': data.get('event_id'), 'attempts': data.get('attempts')}
    print(json.dumps(safe, separators=(',', ':')))
    need(probe.returncode == 0 and data.get('diagnostic') == 'published', 'worker_diagnostic_failed')


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('mode', choices=['install', 'check', 'diagnostic'])
    parser.add_argument('--deploy-path', required=True)
    args = parser.parse_args()
    need(os.geteuid() == 0, 'root_required')
    deploy_path = Path(args.deploy_path).resolve(strict=True)
    step1 = load_step1(deploy_path)
    identity = step1.discover(deploy_path)
    if args.mode == 'install':
        install(identity, deploy_path)
        print(json.dumps({'service': SERVICE, 'installed': True, 'user': identity['user']}))
    elif args.mode == 'check':
        check(identity, deploy_path)
    else:
        diagnostic(identity, deploy_path)


if __name__ == '__main__':
    try:
        main()
    except Exception as error:
        code = str(error) if type(error) is RuntimeError and re.fullmatch(r'[a-z0-9_]+', str(error)) else 'worker_operation_failed'
        print(json.dumps({'status': 'error', 'code': code}), file=sys.stderr)
        sys.exit(1)
