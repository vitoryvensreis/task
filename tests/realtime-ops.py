"""Pre-deploy checks on a temporary fixture; no VPS files or real credentials."""
import importlib.util
import os
from pathlib import Path
import tempfile

spec = importlib.util.spec_from_file_location('realtime_ops', Path(__file__).parents[1] / 'ops/realtime-step1.py')
ops = importlib.util.module_from_spec(spec)
spec.loader.exec_module(ops)

with tempfile.TemporaryDirectory() as directory:
    source = Path(directory) / 'fixture.env'
    source.write_text('CENTRIFUGO_TOKEN_HMAC_SECRET_KEY="fixture-hmac"\nCENTRIFUGO_API_KEY=fixture-api\n')
    ops.SOURCE = source
    values = ops.source_values()
    assert values['REALTIME_ENABLED'] is False
    assert values['CENTRIFUGO_HMAC_SECRET'] == 'fixture-hmac'
    source.write_text(source.read_text() + 'HMAC_SECRET=conflict\n')
    try:
        ops.source_values()
        raise AssertionError('Conflicting credentials accepted')
    except RuntimeError as error:
        assert str(error) == 'required_secret_missing_or_ambiguous'
    private = Path(directory) / 'fixture.json'
    private.write_text('{}')
    private.chmod(0o600)
    ops.acl(private, 65534)
    assert private.stat().st_mode & 0o777 == 0o640
    assert os.access(private, os.R_OK)

nginx = 'server { server_name task.escotilhaprodutora.com; location ~ php { fastcgi_pass 127.0.0.1:18000; } } upstream fpm { server 127.0.0.1:18000; }'
assert len(list(ops.nginx_blocks(nginx, 'server'))) == 1
assert list(ops.nginx_blocks(nginx, 'upstream'))[0][0] == 'fpm'
print('Secret source conflicts, POSIX ACL and nested Nginx parser: OK')
