import http from 'node:http';
import dns from 'node:dns/promises';
import crypto from 'node:crypto';
import net from 'node:net';
import { chromium } from 'playwright';

const port = Number(process.env.PORT || 3000);
const secret = process.env.PROBE_SHARED_KEY || '';
const timeout = Math.min(Number(process.env.PROBE_TIMEOUT_MS || 2800), 5000);
const blockPattern = new RegExp(process.env.BLOCK_PAGE_PATTERN || 'blocked|access denied|not permitted|content filter|unifi', 'i');
const allowedPrivateHosts = new Set((process.env.ALLOWED_PRIVATE_HOSTS || '').split(',').map(value => value.trim().toLowerCase()).filter(Boolean));

if (secret.length < 24) throw new Error('PROBE_SHARED_KEY must contain at least 24 characters.');

const browser = await chromium.launch({ headless: true, args: ['--disable-dev-shm-usage', '--no-sandbox'] });

function authorised(value = '') {
  const supplied = Buffer.from(value);
  const expected = Buffer.from(secret);
  return supplied.length === expected.length && crypto.timingSafeEqual(supplied, expected);
}

function privateOrSpecial(address) {
  if (net.isIP(address) === 6) {
    const value = address.toLowerCase();
    return value === '::1' || value.startsWith('fe80:') || value.startsWith('fc') || value.startsWith('fd');
  }
  const octets = address.split('.').map(Number);
  return octets[0] === 0 || octets[0] === 10 || octets[0] === 127 ||
    (octets[0] === 169 && octets[1] === 254) ||
    (octets[0] === 172 && octets[1] >= 16 && octets[1] <= 31) ||
    (octets[0] === 192 && octets[1] === 168) || octets[0] >= 224;
}

async function validateTarget(rawUrl) {
  const url = new URL(rawUrl);
  if (!['http:', 'https:'].includes(url.protocol) || url.username || url.password) throw new Error('Only HTTP(S) URLs without credentials are allowed.');
  if (net.isIP(url.hostname)) throw new Error('IP-literal targets are not allowed.');
  const records = await dns.lookup(url.hostname, { all: true, verbatim: true });
  if (!records.length) throw new Error('The hostname did not resolve.');
  if (records.some(record => privateOrSpecial(record.address)) && !allowedPrivateHosts.has(url.hostname.toLowerCase())) {
    throw new Error('The hostname resolves to a private or special-use address not present in ALLOWED_PRIVATE_HOSTS.');
  }
  return { url, addresses: records.map(record => record.address) };
}

async function inspectAttempt(rawUrl, screenshot, ignoreHTTPSErrors = false) {
  const initial = await validateTarget(rawUrl);
  const started = performance.now();
  const context = await browser.newContext({ viewport: { width: 1120, height: 630 }, ignoreHTTPSErrors, serviceWorkers: 'block' });
  const page = await context.newPage();

  await page.route('**/*', async route => {
    const requestUrl = new URL(route.request().url());
    if (!['http:', 'https:'].includes(requestUrl.protocol)) return route.abort('blockedbyclient');
    try {
      await validateTarget(requestUrl.href);
      await route.continue();
    } catch {
      await route.abort('blockedbyclient');
    }
  });

  try {
    const response = await page.goto(initial.url.href, { waitUntil: 'domcontentloaded', timeout });
    const title = (await page.title()).slice(0, 300);
    const excerpt = (await page.locator('body').innerText({ timeout: 400 }).catch(() => '')).slice(0, 4000);
    const finalUrl = page.url();
    const statusCode = response?.status() ?? null;
    const blocked = blockPattern.test(`${title}\n${excerpt}\n${finalUrl}`);
    const image = screenshot ? await page.screenshot({ type: 'jpeg', quality: 55, fullPage: false }).then(buffer => buffer.toString('base64')) : null;

    return {
      status: blocked ? 'blocked' : (statusCode && statusCode >= 500 ? 'unavailable' : 'allowed'),
      http_status: statusCode,
      page_title: title,
      final_url: finalUrl,
      addresses: initial.addresses,
      duration_ms: Math.round(performance.now() - started),
      screenshot: image,
    };
  } finally {
    await context.close();
  }
}

async function inspect(rawUrl, screenshot) {
  try {
    return await inspectAttempt(rawUrl, screenshot, false);
  } catch (error) {
    const message = String(error?.message || error);
    if (!/net::ERR_CERT_(AUTHORITY_INVALID|COMMON_NAME_INVALID|DATE_INVALID|INVALID)/.test(message)) throw error;

    const result = await inspectAttempt(rawUrl, screenshot, true);
    const blocked = result.status === 'blocked';

    return {
      ...result,
      status: blocked ? 'blocked' : 'tls_error',
      tls_error: true,
      message: blocked
        ? 'A block page was captured after the network presented an untrusted certificate.'
        : 'The website presented an untrusted or intercepted TLS certificate.',
    };
  }
}

const server = http.createServer((request, response) => {
  response.setHeader('Content-Type', 'application/json');
  response.setHeader('Cache-Control', 'no-store');
  if (request.url === '/health') return response.end(JSON.stringify({ ok: true }));
  if (request.method !== 'POST' || request.url !== '/check') { response.statusCode = 404; return response.end(JSON.stringify({ message: 'Not found.' })); }
  if (!authorised(request.headers['x-probe-key'])) { response.statusCode = 401; return response.end(JSON.stringify({ message: 'Unauthorised.' })); }

  let body = '';
  request.on('data', chunk => {
    body += chunk;
    if (body.length > 8192) request.destroy();
  });
  request.on('end', async () => {
    try {
      const payload = JSON.parse(body);
      const result = await inspect(String(payload.url || ''), Boolean(payload.screenshot));
      response.end(JSON.stringify(result));
    } catch (error) {
      response.statusCode = 422;
      response.end(JSON.stringify({ status: 'probe_error', message: String(error.message || 'Check failed.').slice(0, 300) }));
    }
  });
});

server.listen(port, '0.0.0.0');

for (const signal of ['SIGTERM', 'SIGINT']) {
  process.on(signal, async () => { server.close(); await browser.close(); process.exit(0); });
}
