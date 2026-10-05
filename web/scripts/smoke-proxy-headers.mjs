/**
 * Production-shaped smoke test for the request proxy (src/proxy.ts).
 *
 * The bug it guards: behind Apache, every request arrives with
 * `X-Forwarded-Proto: https` and the real school Host, while the standalone
 * Next server listens on plain http at 127.0.0.1. A proxy rewrite built from
 * request.url got a different origin from the server's own, so Next proxied
 * it as an external URL — a TLS request to the plain-http port — and every
 * signed-out visit to a school's address returned 500. Nothing in local dev
 * reproduces that, because there both origins are `localhost`.
 *
 * So this starts the built standalone server exactly as production does
 * (HOSTNAME=127.0.0.1, a port, no TLS) and sends the headers Apache sends.
 * It fails on any 5xx, and on any redirect that points at the server's own
 * loopback address instead of staying on the visitor's host.
 *
 *   npm run build && npm run test:smoke
 *
 * The API is deliberately unreachable: a school's public home must still
 * answer (it falls back to the sign-in page) when Laravel is down.
 */
import { spawn } from 'node:child_process';
import { existsSync } from 'node:fs';
import http from 'node:http';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const server = path.join(root, '.next/standalone/server.js');
const PORT = Number(process.env.SMOKE_PORT ?? 3997);

if (!existsSync(server)) {
  console.error('smoke: .next/standalone/server.js is missing — run `npm run build` first.');
  process.exit(1);
}

const child = spawn(process.execPath, [server], {
  cwd: path.dirname(server),
  env: {
    ...process.env,
    NODE_ENV: 'production',
    HOSTNAME: '127.0.0.1',
    PORT: String(PORT),
    // Nothing listens here: the pages must cope with the API being down.
    BACKEND_API_URL: 'http://127.0.0.1:9/api/v1',
  },
  stdio: ['ignore', 'pipe', 'pipe'],
});

let serverLog = '';
child.stdout.on('data', (d) => (serverLog += d));
child.stderr.on('data', (d) => (serverLog += d));

function request(pathname, host, headers = {}) {
  return new Promise((resolve, reject) => {
    const req = http.request(
      {
        host: '127.0.0.1',
        port: PORT,
        path: pathname,
        method: 'GET',
        headers: {
          Host: host,
          'X-Forwarded-Proto': 'https',
          'X-Forwarded-Host': host,
          'X-Forwarded-For': '203.0.113.7',
          Accept: 'text/html',
          ...headers,
        },
      },
      (res) => {
        res.resume();
        res.on('end', () => resolve({ status: res.statusCode, location: res.headers.location ?? null }));
      },
    );
    req.on('error', reject);
    req.setTimeout(20_000, () => req.destroy(new Error('timed out')));
    req.end();
  });
}

async function waitForServer() {
  for (let i = 0; i < 60; i++) {
    try {
      await request('/login', 'schoolpilot.org.ng');
      return;
    } catch {
      await new Promise((r) => setTimeout(r, 500));
    }
  }
  throw new Error(`server did not start:\n${serverLog}`);
}

const cases = [
  // The reported failure: the apex, signed out, at the root.
  { name: 'apex root, signed out', path: '/', host: 'schoolpilot.org.ng' },
  { name: 'school root, signed out', path: '/', host: 'greenfield.schoolpilot.org.ng' },
  { name: 'school sign-in page', path: '/login', host: 'greenfield.schoolpilot.org.ng', expect: [200] },
  { name: 'school online application', path: '/apply', host: 'greenfield.schoolpilot.org.ng' },
  { name: 'deep link, signed out', path: '/students', host: 'greenfield.schoolpilot.org.ng', expect: [302, 307, 308] },
  { name: 'school root, signed in', path: '/', host: 'greenfield.schoolpilot.org.ng', headers: { Cookie: 'sp_token=smoke; sp_role=school_admin' } },
];

let failed = 0;

try {
  await waitForServer();

  for (const c of cases) {
    const { status, location } = await request(c.path, c.host, c.headers);
    const problems = [];

    if (status >= 500) problems.push(`status ${status}`);
    if (c.expect && !c.expect.includes(status)) problems.push(`expected ${c.expect.join('/')}, got ${status}`);
    if (location && /localhost|127\.0\.0\.1|0\.0\.0\.0/.test(location)) {
      problems.push(`redirect leaks the server's own address: ${location}`);
    }
    if (location && /^https?:\/\//.test(location) && !location.includes(c.host)) {
      problems.push(`redirect leaves the visitor's host: ${location}`);
    }

    if (problems.length) {
      failed++;
      console.error(`FAIL  ${c.name} (${c.host}${c.path}): ${problems.join('; ')}`);
    } else {
      console.log(`ok    ${c.name} (${c.host}${c.path}) -> ${status}${location ? ` ${location}` : ''}`);
    }
  }

  if (/EPROTO|Failed to proxy/i.test(serverLog)) {
    failed++;
    console.error('FAIL  the server tried to proxy a request to itself:\n' + serverLog.split('\n').filter((l) => /EPROTO|Failed to proxy/i.test(l)).join('\n'));
  }
} catch (err) {
  failed++;
  console.error(`FAIL  ${err.message}`);
} finally {
  child.kill();
}

if (failed) {
  console.error(`\nsmoke: ${failed} failure(s).`);
  process.exit(1);
}
console.log('\nsmoke: all passed.');
