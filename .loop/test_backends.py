#!/usr/bin/env python3
"""Run Redis contracts on an owned disposable process, never an existing endpoint."""
import argparse
import os
from pathlib import Path
import shutil
import socket
import subprocess
import tempfile
import time

ROOT = Path(__file__).resolve().parent.parent


def command(port, *args):
    with socket.create_connection(('127.0.0.1', port), timeout=2) as sock:
        raw = [str(arg).encode() for arg in args]
        sock.sendall(b'*' + str(len(raw)).encode() + b'\r\n' + b''.join(
            b'$' + str(len(arg)).encode() + b'\r\n' + arg + b'\r\n' for arg in raw))
        stream = sock.makefile('rb')

        def read():
            line = stream.readline()
            if not line:
                raise RuntimeError('Redis closed its readiness connection')
            kind, value = line[:1], line[1:-2]
            if kind == b'-':
                raise RuntimeError(value.decode())
            if kind == b'+':
                return value.decode()
            if kind == b':':
                return int(value)
            if kind == b'$':
                size = int(value)
                if size < 0:
                    return None
                result = stream.read(size)
                if stream.read(2) != b'\r\n':
                    raise RuntimeError('Invalid readiness frame')
                return result.decode()
            if kind == b'*':
                count = int(value)
                return None if count < 0 else [read() for _ in range(count)]
            raise RuntimeError('Unsupported Redis readiness frame')

        return read()


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--redis-server', default=shutil.which('redis-server'))
    parser.add_argument('--redis-module', action='append', default=[])
    parser.add_argument('--filter', default='RedisSaverIntegrationTest|RedisShallowSaverIntegrationTest|RedisStoreIntegrationTest')
    parser.add_argument('--timeout', type=int, default=240)
    parser.add_argument('--check-only', action='store_true')
    args = parser.parse_args()
    if not args.redis_server:
        parser.error('redis-server is required; no existing server is used')
    if not 1 <= args.timeout <= 1800:
        parser.error('timeout must be between 1 and 1800 seconds')
    with socket.socket() as listener:
        listener.bind(('127.0.0.1', 0))
        port = listener.getsockname()[1]
    log_dir = ROOT / 'build' / 'backend-harness'
    log_dir.mkdir(parents=True, exist_ok=True)
    log_path = log_dir / ('redis-' + str(time.time_ns()) + '.log')
    with tempfile.TemporaryDirectory(prefix='langchain-test-redis-') as directory, log_path.open('w') as log:
        argv = [args.redis_server, '--bind', '127.0.0.1', '--port', str(port),
                '--save', '', '--appendonly', 'no', '--dir', directory]
        for module in args.redis_module:
            argv.extend(['--loadmodule', str(Path(module).resolve())])
        process = subprocess.Popen(argv, stdout=log, stderr=subprocess.STDOUT)
        try:
            deadline = time.monotonic() + 15
            while True:
                if process.poll() is not None:
                    raise RuntimeError('Disposable Redis exited; inspect ' + str(log_path))
                try:
                    if command(port, 'PING') != 'PONG':
                        raise RuntimeError('Redis readiness PING failed')
                    info = dict(line.split(':', 1) for line in command(port, 'INFO', 'server').splitlines()
                                if line and not line.startswith('#') and ':' in line)
                    if int(info.get('process_id', -1)) != process.pid:
                        raise RuntimeError('Endpoint is not the Redis process owned by this harness')
                    break
                except (OSError, RuntimeError):
                    if time.monotonic() >= deadline:
                        raise
                    time.sleep(0.1)
            required = command(port, 'COMMAND', 'INFO', 'JSON.GET', 'FT.SEARCH')
            if not isinstance(required, list) or len(required) != 2 or any(item is None for item in required):
                raise RuntimeError('Disposable Redis requires RedisJSON and RediSearch; pass --redis-module paths')
            if command(port, 'DBSIZE') != 0:
                raise RuntimeError('Disposable Redis did not start empty')
            env = os.environ.copy()
            env['LANGGRAPH_REDIS_URL'] = 'redis://127.0.0.1:' + str(port) + '/0'
            env['LANGGRAPH_REDIS_TEST_RUN_ID'] = info['run_id']
            env.pop('LANGGRAPH_MONGO_URI', None)
            env.pop('LANGGRAPH_PG_DSN', None)
            if args.check_only:
                print('Owned Redis process: PING, modules, process identity and empty database verified')
                return 0
            result = subprocess.run([str(ROOT / 'vendor/bin/phpunit'), '--colors=never', '--display-skipped',
                                     '--filter', args.filter], cwd=ROOT, env=env, timeout=args.timeout)
            return result.returncode
        finally:
            if process.poll() is None:
                process.terminate()
                try:
                    process.wait(timeout=5)
                except subprocess.TimeoutExpired:
                    process.kill()
                    process.wait(timeout=5)
            print('Disposable Redis stopped; log: ' + str(log_path))


if __name__ == '__main__':
    raise SystemExit(main())
