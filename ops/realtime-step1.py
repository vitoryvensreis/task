#!/usr/bin/env python3
"""Root-only provisioning/probe. Never prints secrets, raw errors or FCGI stderr."""
import argparse
import ctypes
import ctypes.util
import datetime
import grp
import json
import os
from pathlib import Path
import pwd
import re
import shlex
import shutil
import socket
import stat
import struct
import subprocess
import sys
import tempfile

PRIVATE_DIR = Path('/etc/taskesc')
PRIVATE_CONFIG = PRIVATE_DIR / 'realtime.json'
SOURCE = Path('/root/taskesc-realtime-secrets.env')


def need(condition, code):
    if not condition:
        raise RuntimeError(code)


def nginx_blocks(text, directive):
    for match in re.finditer(r'\b' + directive + r'\s*([^{};]*)\{', text):
        depth, end = 1, match.end()
        while end < len(text) and depth:
            depth += (text[end] == '{') - (text[end] == '}')
            end += 1
        if depth == 0:
            yield match.group(1).strip(), text[match.end():end - 1]


def discover(deploy_path):
    nginx = subprocess.run(['nginx', '-T'], capture_output=True, text=True, timeout=15)
    need(nginx.returncode == 0, 'nginx_inspection_failed')
    # Strip comments so other hostnames/comments cannot select the pool.
    text = re.sub(r'(?m)#.*$', '', nginx.stdout + '\n' + nginx.stderr)
    targets = set()
    for _, block in nginx_blocks(text, 'server'):
        names = re.findall(r'\bserver_name\s+([^;]+);', block)
        if not any('task.escotilhaprodutora.com' in n.split() for n in names):
            continue
        roots = re.findall(r'\broot\s+([^;]+);', block)
        if roots and not any(Path(r.strip().strip('"')).resolve() == deploy_path / 'public' for r in roots):
            continue
        targets.update(re.findall(r'\bfastcgi_pass\s+([^;]+);', block))
    upstreams = dict(nginx_blocks(text, 'upstream'))
    resolved = set()
    for target in targets:
        target = target.strip()
        if target in upstreams:
            resolved.update(re.findall(r'\bserver\s+([^\s;]+)', upstreams[target]))
        else:
            resolved.add(target)
    need(len(resolved) == 1, 'taskesc_fastcgi_not_unique')
    target = resolved.pop()
    normalized = target.removeprefix('unix:').rstrip('/')
    matches = []
    files = list(Path('/etc/php').glob('*/fpm/pool.d/*.conf'))
    files += list(Path('/etc/php-fpm.d').glob('*.conf'))
    for file in files:
        section, values = '', {}
        sections = []
        for line in file.read_text().splitlines():
            line = line.split(';', 1)[0].strip()
            if line.startswith('[') and line.endswith(']'):
                if section:
                    sections.append((section, values))
                section, values = line[1:-1], {}
            elif '=' in line:
                key, value = line.split('=', 1)
                values[key.strip()] = value.strip().strip('"\'').replace('$pool', section)
        if section:
            sections.append((section, values))
        for section, values in sections:
            listen = values.get('listen', '').removeprefix('unix:').rstrip('/')
            if listen == normalized:
                matches.append((file, section, values))
    need(len(matches) == 1, 'taskesc_fpm_pool_not_unique')
    file, pool, values = matches[0]
    user_name, group_name = values.get('user', ''), values.get('group', '')
    user = pwd.getpwuid(int(user_name)) if user_name.isdigit() else pwd.getpwnam(user_name)
    group = grp.getgrgid(int(group_name)) if group_name.isdigit() else grp.getgrnam(group_name)
    need(user.pw_uid != 0, 'root_fpm_pool_forbidden')
    # Verify running workers against pool configuration, when workers are present.
    workers = 0
    for process in Path('/proc').glob('[0-9]*'):
        try:
            cmd = (process / 'cmdline').read_bytes().replace(b'\0', b' ').decode().strip()
            if cmd != 'php-fpm: pool ' + pool:
                continue
            status = (process / 'status').read_text()
            uid = int(re.search(r'(?m)^Uid:\s+\d+\s+(\d+)', status).group(1))
            gid = int(re.search(r'(?m)^Gid:\s+\d+\s+(\d+)', status).group(1))
            need(uid == user.pw_uid and gid == group.gr_gid, 'fpm_worker_identity_mismatch')
            workers += 1
        except (FileNotFoundError, ProcessLookupError, PermissionError):
            continue
    need(not values.get('chroot'), 'fpm_chroot_requires_explicit_path')
    return {'pool': pool, 'user': user.pw_name, 'group': group.gr_name,
            'uid': user.pw_uid, 'gid': group.gr_gid, 'listen': target,
            'pool_config': str(file), 'running_workers': workers}


def source_values():
    need(SOURCE.is_file() and not SOURCE.is_symlink(), 'secret_source_unavailable')
    values = {}
    for line in SOURCE.read_text().splitlines():
        line = line.strip()
        if not line or line.startswith('#'):
            continue
        match = re.fullmatch(r'(?:export\s+)?([A-Z][A-Z0-9_]*)\s*=\s*(.*)', line)
        need(match is not None, 'secret_source_invalid')
        key, value = match.groups()
        need(key not in values, 'secret_source_duplicate')
        if value.startswith(('"', "'")):
            parts = shlex.split(value, comments=True)
            need(len(parts) == 1, 'secret_source_invalid_quote')
            value = parts[0]
        else:
            value = re.split(r'\s+#', value, 1)[0].strip()
        values[key] = value

    def select(keys):
        candidates = {values[k] for k in keys if values.get(k)}
        need(len(candidates) == 1, 'required_secret_missing_or_ambiguous')
        value = candidates.pop()
        need(not any(ord(c) < 32 or ord(c) == 127 for c in value), 'invalid_secret_format')
        return value

    return {
        'REALTIME_ENABLED': True,
        'REDIS_HOST': '127.0.0.1', 'REDIS_PORT': 6379,
        'CENTRIFUGO_API_URL': 'http://127.0.0.1:8000/api',
        'CENTRIFUGO_WS_URL': 'wss://task.escotilhaprodutora.com/realtime/connection/websocket',
        'CENTRIFUGO_HMAC_SECRET': select(['CENTRIFUGO_HMAC_SECRET', 'CENTRIFUGO_HMAC_SECRET_KEY',
            'CENTRIFUGO_TOKEN_HMAC_SECRET_KEY', 'CENTRIFUGO_CLIENT_TOKEN_HMAC_SECRET_KEY',
            'CENTRIFUGO_TOKEN_HMAC_SECRET', 'TOKEN_HMAC_SECRET_KEY', 'HMAC_SECRET', 'HMAC_KEY']),
        'CENTRIFUGO_API_KEY': select(['CENTRIFUGO_API_KEY', 'CENTRIFUGO_HTTP_API_KEY', 'HTTP_API_KEY', 'API_KEY']),
    }


def acl(path, uid, directory=False):
    # libacl avoids installing packages or changing PHP-FPM group membership.
    library = ctypes.CDLL(ctypes.util.find_library('acl') or 'libacl.so.1', use_errno=True)
    library.acl_from_text.argtypes = [ctypes.c_char_p]
    library.acl_from_text.restype = ctypes.c_void_p
    library.acl_set_file.argtypes = [ctypes.c_char_p, ctypes.c_int, ctypes.c_void_p]
    library.acl_free.argtypes = [ctypes.c_void_p]
    permission = 'r-x' if directory else 'r--'
    entries = f'u::rwx,u:{uid}:{permission},g::---,m::{permission},o::---' if directory else f'u::rw-,u:{uid}:r--,g::---,m::r--,o::---'
    handle = library.acl_from_text(entries.encode())
    need(handle, 'private_acl_invalid')
    try:
        need(library.acl_set_file(os.fsencode(path), 0x8000, handle) == 0, 'private_acl_failed')
    finally:
        library.acl_free(handle)


def provision(identity):
    values = source_values()
    # Root-only snapshot precedes any change to private configuration.
    stamp = datetime.datetime.now(datetime.timezone.utc).strftime('%Y%m%dT%H%M%S%fZ')
    backup = Path('/var/backups/taskesc-realtime') / stamp
    backup.mkdir(parents=True, mode=0o700)
    os.chmod(backup.parent, 0o700)
    for path in [SOURCE, PRIVATE_CONFIG]:
        if path.exists():
            need(not path.is_symlink(), 'private_config_symlink_forbidden')
            shutil.copyfile(path, backup / path.name)
            os.chmod(backup / path.name, 0o600)
    need(not PRIVATE_DIR.is_symlink(), 'private_directory_symlink_forbidden')
    PRIVATE_DIR.mkdir(mode=0o700, exist_ok=True)
    os.chown(PRIVATE_DIR, 0, 0)
    os.chmod(PRIVATE_DIR, 0o700)
    acl(PRIVATE_DIR, identity['uid'], directory=True)
    fd, temp = tempfile.mkstemp(prefix='.realtime-', dir=PRIVATE_DIR)
    try:
        with os.fdopen(fd, 'w') as stream:
            json.dump(values, stream)
            stream.write('\n')
        os.chown(temp, 0, 0)
        os.chmod(temp, 0o600)
        acl(temp, identity['uid'])
        os.replace(temp, PRIVATE_CONFIG)
    finally:
        if os.path.exists(temp):
            os.unlink(temp)
    # Validate effective read access by the pool user and deny another UID.
    def readable(uid, gid):
        def drop():
            os.setgroups([])
            os.setgid(gid)
            os.setuid(uid)
        result = subprocess.run([sys.executable, '-c',
            'import os,sys; sys.exit(0 if os.access(sys.argv[1],os.R_OK) else 1)',
            str(PRIVATE_CONFIG)], preexec_fn=drop, capture_output=True, timeout=5)
        return result.returncode == 0
    need(readable(identity['uid'], identity['gid']), 'fpm_cannot_read_private_config')
    nobody = pwd.getpwnam('nobody')
    need(nobody.pw_uid != identity['uid'] and not readable(nobody.pw_uid, nobody.pw_gid), 'private_config_permissions_failed')
    return str(backup)


def fcgi_probe(identity, deploy_path):
    def record(kind, content):
        padding = (-len(content)) % 8
        return struct.pack('!BBHHBB', 1, kind, 1, len(content), padding, 0) + content + bytes(padding)
    def length(value):
        return bytes([value]) if value < 128 else struct.pack('!I', value | 0x80000000)
    def receive(count):
        data = b''
        while len(data) < count:
            chunk = connection.recv(count - len(data))
            need(chunk, 'fpm_probe_incomplete')
            data += chunk
        return data
    target = identity['listen']
    if target.startswith('unix:') or target.startswith('/'):
        connection = socket.socket(socket.AF_UNIX, socket.SOCK_STREAM)
        address = target.removeprefix('unix:')
    else:
        host, port = target.rsplit(':', 1)
        need(host in ['127.0.0.1', 'localhost', '[::1]'], 'nonlocal_fpm_forbidden')
        connection = socket.socket(socket.AF_INET6 if host == '[::1]' else socket.AF_INET, socket.SOCK_STREAM)
        address = (host.strip('[]'), int(port))
    connection.settimeout(8)
    with connection:
        connection.connect(address)
        params = {'GATEWAY_INTERFACE': 'CGI/1.1', 'REQUEST_METHOD': 'GET',
                  'SCRIPT_FILENAME': str(deploy_path / 'bin/realtime-health.php'),
                  'SCRIPT_NAME': '/bin/realtime-health.php', 'REQUEST_URI': '/bin/realtime-health.php',
                  'DOCUMENT_ROOT': str(deploy_path / 'public'), 'REMOTE_ADDR': '127.0.0.1',
                  'SERVER_NAME': 'task.escotilhaprodutora.com', 'SERVER_PORT': '443',
                  'SERVER_PROTOCOL': 'HTTP/1.1', 'CONTENT_LENGTH': '0'}
        encoded = b''
        for key, value in params.items():
            key, value = key.encode(), value.encode()
            encoded += length(len(key)) + length(len(value)) + key + value
        connection.sendall(record(1, struct.pack('!HB5x', 1, 0)) + record(4, encoded) + record(4, b'') + record(5, b''))
        output = b''
        while True:
            version, kind, request, count, padding, _ = struct.unpack('!BBHHBB', receive(8))
            need(version == 1 and request == 1, 'fpm_probe_invalid_record')
            content = receive(count) if count else b''
            if padding:
                receive(padding)
            if kind == 6:
                output += content
                need(len(output) <= 16384, 'fpm_probe_response_too_large')
            if kind == 3:
                break
    need(b'\r\n\r\n' in output, 'fpm_probe_no_headers')
    response = json.loads(output.split(b'\r\n\r\n', 1)[1])
    # Strict allowlist: do not forward arbitrary backend response fields.
    result = {key: response.get(key) for key in ['status', 'enabled', 'config', 'redis', 'centrifugo', 'centrifugo_version', 'centrifugo_expected']}
    need(result['enabled'] is True, 'realtime_must_be_enabled')
    need(all(result[k] in ['ok', 'degraded', 'unavailable', 'invalid'] for k in ['status', 'config', 'redis', 'centrifugo']), 'fpm_probe_invalid_health')
    need(result['centrifugo_expected'] == '6.9.7', 'centrifugo_expected_version_invalid')
    if result['centrifugo_version'] not in (None, ''):
        need(str(result['centrifugo_version']).lstrip('v') == '6.9.7', 'centrifugo_api_version_mismatch')
    print(json.dumps({'fpm_health': result}))
    need(result['status'] == 'ok', 'realtime_health_failed')


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('mode', choices=['provision', 'check'])
    parser.add_argument('--deploy-path', required=True)
    args = parser.parse_args()
    need(os.geteuid() == 0, 'root_required')
    deploy_path = Path(args.deploy_path).resolve(strict=True)
    identity = discover(deploy_path)
    print(json.dumps({'php_fpm': identity}))
    if args.mode == 'provision':
        backup = provision(identity)
        print(json.dumps({'private_config': str(PRIVATE_CONFIG), 'owner': 'root:root',
                          'access': 'root rw, detected PHP-FPM user r via POSIX ACL; all other users denied',
                          'enabled': True, 'backup': backup}))
    else:
        fcgi_probe(identity, deploy_path)


if __name__ == '__main__':
    try:
        main()
    except Exception as error:
        # Only predefined error codes are safe; never log raw exception values.
        code = str(error) if type(error) is RuntimeError and re.fullmatch(r'[a-z_]+', str(error)) else 'realtime_operation_failed'
        print(json.dumps({'status': 'error', 'code': code}), file=sys.stderr)
        sys.exit(1)
