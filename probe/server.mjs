import http from 'node:http';
import dns from 'node:dns/promises';
import crypto from 'node:crypto';
import net from 'node:net';
import os from 'node:os';
import tls from 'node:tls';
import { readFileSync } from 'node:fs';
import { chromium } from 'playwright';

const port = Number(process.env.PORT || 3000);
const secret = process.env.PROBE_SHARED_KEY || '';
const timeout = Math.min(Number(process.env.PROBE_TIMEOUT_MS || 8000), 20000);
// After the page has loaded, wait this long for late content (fonts, images, scripts) before the screenshot.
const settle = Math.min(Number(process.env.PROBE_SETTLE_MS || 1500), 5000);
const blockPattern = new RegExp(process.env.BLOCK_PAGE_PATTERN || 'blocked|access denied|not permitted|content filter|unifi', 'i');
const list = value => (value || '').split(',').map(item => item.trim().toLowerCase()).filter(Boolean);
const allowedPrivateHosts = new Set(list(process.env.ALLOWED_PRIVATE_HOSTS));
const blockPageAddresses = new Set(list(process.env.BLOCK_PAGE_ADDRESSES));
const dnsServers = list(process.env.PROBE_DNS_SERVERS);
const maxBatch = 10;
const maxHosts = 60;

if (secret.length < 24) throw new Error('PROBE_SHARED_KEY must contain at least 24 characters.');

/**
 * DNS freshness
 * -------------
 * Every check run must see the network's *current* DNS answer, otherwise a
 * re-test after IT changes a UniFi list can show a stale result.
 *
 * - When PROBE_DNS_SERVERS is set, lookups go straight to those resolvers via
 *   a c-ares Resolver, which keeps no cache. Point it at the VLAN gateway (the
 *   resolver UniFi filters on).
 * - A fresh Chromium process is started per run, so its in-process host cache
 *   never carries answers between runs.
 * - Chromium's Secure DNS (DoH) and built-in async resolver are disabled, so
 *   the browser resolves through the same system path as the check and cannot
 *   bypass a DNS-based content filter.
 */
const resolver = dnsServers.length ? new dns.Resolver({ timeout: 1500, tries: 1 }) : null;
if (resolver) resolver.setServers(dnsServers);

const chromiumArgs = [
  '--disable-dev-shm-usage',
  '--no-sandbox',
  '--disable-features=DnsOverHttps,AsyncDns,NetworkPrediction',
  '--dns-prefetch-disable',
];

let warmBrowser = null;

/** A browser is only reused between runs when explicitly allowed. */
const reuseBrowser = process.env.PROBE_REUSE_BROWSER === 'true';

async function launchBrowser() {
  return chromium.launch({ headless: true, args: chromiumArgs });
}

function authorised(value = '') {
  const supplied = Buffer.from(value);
  const expected = Buffer.from(secret);
  return supplied.length === expected.length && crypto.timingSafeEqual(supplied, expected);
}

function sinkholed(address) {
  return address === '0.0.0.0' || address === '::' || blockPageAddresses.has(address.toLowerCase());
}

function privateOrSpecial(address) {
  if (net.isIP(address) === 6) {
    const value = address.toLowerCase();
    return value === '::1' || value === '::' || value.startsWith('fe80:') || value.startsWith('fc') || value.startsWith('fd');
  }
  const octets = address.split('.').map(Number);
  return octets[0] === 0 || octets[0] === 10 || octets[0] === 127 ||
    (octets[0] === 169 && octets[1] === 254) ||
    (octets[0] === 172 && octets[1] >= 16 && octets[1] <= 31) ||
    (octets[0] === 192 && octets[1] === 168) || octets[0] >= 224;
}

/** Resolve without any cache in this process. */
async function resolve(hostname) {
  if (!resolver) {
    const records = await dns.lookup(hostname, { all: true, verbatim: true });
    return { addresses: records.map(record => record.address), resolver: 'system' };
  }

  const settled = await Promise.allSettled([resolver.resolve4(hostname), resolver.resolve6(hostname)]);
  const addresses = settled.filter(result => result.status === 'fulfilled').flatMap(result => result.value);
  if (!addresses.length) {
    const reason = settled.find(result => result.status === 'rejected')?.reason;
    const error = new Error(reason?.code === 'ENOTFOUND' || reason?.code === 'ENODATA' ? 'The hostname did not resolve.' : 'DNS lookup failed.');
    error.code = reason?.code;
    throw error;
  }
  return { addresses, resolver: dnsServers.join(', ') };
}

/** @returns {{url: URL, addresses: string[], resolver: string, dnsBlocked: boolean}} */
async function validateTarget(rawUrl) {
  const url = new URL(rawUrl);
  if (!['http:', 'https:'].includes(url.protocol) || url.username || url.password) throw new Error('Only HTTP(S) URLs without credentials are allowed.');
  if (net.isIP(url.hostname.replace(/^\[|\]$/g, ''))) throw new Error('IP-literal targets are not allowed.');
  const { addresses, resolver: usedResolver } = await resolve(url.hostname);
  if (!addresses.length) throw new Error('The hostname did not resolve.');
  if (addresses.every(sinkholed)) return { url, addresses, resolver: usedResolver, dnsBlocked: true };
  if (addresses.some(privateOrSpecial) && !allowedPrivateHosts.has(url.hostname.toLowerCase())) {
    throw new Error('The hostname resolves to a private or special-use address not present in ALLOWED_PRIVATE_HOSTS.');
  }
  return { url, addresses, resolver: usedResolver, dnsBlocked: false };
}

async function inspectAttempt(browser, rawUrl, screenshot, ignoreHTTPSErrors = false) {
  const initial = await validateTarget(rawUrl);
  const started = performance.now();

  if (initial.dnsBlocked) {
    return {
      status: 'blocked',
      block_method: 'dns',
      message: 'DNS returned a sinkhole or block-page address for this hostname.',
      http_status: null,
      final_url: initial.url.href,
      addresses: initial.addresses,
      resolver: initial.resolver,
      hosts: [{ host: initial.url.hostname, requests: 0, failed: true, reason: 'dns_blocked' }],
      duration_ms: Math.round(performance.now() - started),
      screenshot: null,
    };
  }

  const context = await browser.newContext({ viewport: { width: 1120, height: 630 }, ignoreHTTPSErrors, serviceWorkers: 'block' });
  const page = await context.newPage();
  const hosts = new Map();
  const touch = (host, failed = false, reason = null) => {
    if (!host || (hosts.size >= maxHosts && !hosts.has(host))) return;
    const entry = hosts.get(host) || { host, requests: 0, failed: false, reason: null };
    if (!failed) entry.requests += 1;
    if (failed) { entry.failed = true; entry.reason = entry.reason || reason; }
    hosts.set(host, entry);
  };

  await page.route('**/*', async route => {
    const requestUrl = new URL(route.request().url());
    if (!['http:', 'https:'].includes(requestUrl.protocol)) return route.abort('blockedbyclient');
    touch(requestUrl.hostname);
    try {
      const target = await validateTarget(requestUrl.href);
      if (target.dnsBlocked) {
        touch(requestUrl.hostname, true, 'dns_blocked');
        return route.abort('namenotresolved');
      }
      await route.continue();
    } catch (error) {
      touch(requestUrl.hostname, true, /resolve/i.test(String(error?.message)) ? 'dns_failed' : 'refused');
      await route.abort('blockedbyclient');
    }
  });
  page.on('requestfailed', request => {
    try { touch(new URL(request.url()).hostname, true, (request.failure()?.errorText || 'failed').slice(0, 60)); } catch { /* ignore */ }
  });

  try {
    const response = await page.goto(initial.url.href, { waitUntil: 'domcontentloaded', timeout });
    if (screenshot) {
      // Give the page time to finish rendering, but never fail the check because a tracker kept loading.
      await page.waitForLoadState('load', { timeout: Math.max(1000, timeout / 2) }).catch(() => {});
      await page.waitForLoadState('networkidle', { timeout: settle }).catch(() => {});
    }
    const title = (await page.title()).slice(0, 300);
    const excerpt = (await page.locator('body').innerText({ timeout: 400 }).catch(() => '')).slice(0, 4000);
    const finalUrl = page.url();
    const statusCode = response?.status() ?? null;
    const blocked = blockPattern.test(`${title}\n${excerpt}\n${finalUrl}`);
    const image = screenshot ? await page.screenshot({ type: 'jpeg', quality: 55, fullPage: false }).then(buffer => buffer.toString('base64')) : null;

    return {
      status: blocked ? 'blocked' : (statusCode && statusCode >= 500 ? 'unavailable' : 'allowed'),
      block_method: blocked ? 'page' : null,
      http_status: statusCode,
      page_title: title,
      final_url: finalUrl,
      addresses: initial.addresses,
      resolver: initial.resolver,
      hosts: [...hosts.values()],
      duration_ms: Math.round(performance.now() - started),
      screenshot: image,
    };
  } finally {
    await context.close();
  }
}

async function inspect(browser, rawUrl, screenshot) {
  try {
    return await inspectAttempt(browser, rawUrl, screenshot, false);
  } catch (error) {
    const message = String(error?.message || error);
    if (!/net::ERR_CERT_(AUTHORITY_INVALID|COMMON_NAME_INVALID|DATE_INVALID|INVALID)/.test(message)) throw error;

    const result = await inspectAttempt(browser, rawUrl, screenshot, true);
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

async function safeInspect(browser, rawUrl, screenshot) {
  try {
    return await inspect(browser, String(rawUrl || ''), screenshot);
  } catch (error) {
    const message = String(error?.message || 'Check failed.');
    const dnsFailure = /did not resolve|ERR_NAME_NOT_RESOLVED|DNS lookup failed/i.test(message);
    return {
      status: dnsFailure ? 'dns_failed' : 'probe_error',
      message: (dnsFailure ? 'DNS could not resolve this hostname from this network. It may be filtered at DNS or not exist.' : message).slice(0, 300),
    };
  }
}

/*
 * At most PROBE_MAX_CONCURRENT check runs at once (each is a browser). Extra
 * runs wait their turn, first come first served, for up to
 * PROBE_QUEUE_TIMEOUT_MS; then they are turned away as busy rather than
 * piling up browsers until the host runs out of memory or CPU.
 */
const maxConcurrentRuns = Math.max(1, Number(process.env.PROBE_MAX_CONCURRENT || 6));
const queueTimeoutMs = Math.max(0, Number(process.env.PROBE_QUEUE_TIMEOUT_MS || 20000));
let activeRuns = 0;
const waitingRuns = [];

class ProbeBusy extends Error {}

function acquireRun() {
  if (activeRuns < maxConcurrentRuns) {
    activeRuns++;
    return Promise.resolve();
  }
  return new Promise((resolve, reject) => {
    const entry = { resolve, timer: null };
    entry.timer = setTimeout(() => {
      const index = waitingRuns.indexOf(entry);
      if (index >= 0) waitingRuns.splice(index, 1);
      reject(new ProbeBusy(`This probe is busy with ${activeRuns} other check(s). Try again in a moment.`));
    }, queueTimeoutMs);
    waitingRuns.push(entry);
  });
}

/** Hands the slot straight to the next waiting run, or frees it. */
function releaseRun() {
  const next = waitingRuns.shift();
  if (next) {
    clearTimeout(next.timer);
    next.resolve();
  } else {
    activeRuns--;
  }
}

/** Runs one check run with its own browser so DNS state cannot leak between runs. */
async function withRunBrowser(task) {
  await acquireRun();
  try {
    if (reuseBrowser) {
      warmBrowser ??= await launchBrowser();
      return await task(warmBrowser);
    }
    const browser = await launchBrowser();
    try {
      return await task(browser);
    } finally {
      await browser.close().catch(() => {});
    }
  } finally {
    releaseRun();
  }
}

function readJson(request, limit) {
  return new Promise((resolveBody, reject) => {
    let body = '';
    request.on('data', chunk => {
      body += chunk;
      if (body.length > limit) { request.destroy(); reject(new Error('Request too large.')); }
    });
    request.on('end', () => {
      try { resolveBody(JSON.parse(body || '{}')); } catch { reject(new Error('Invalid JSON.')); }
    });
    request.on('error', reject);
  });
}

/** The interface the default route leaves by: the probe's VLAN. */
function defaultRouteInterface() {
  try {
    const rows = readFileSync('/proc/net/route', 'utf8').trim().split('\n').slice(1).map(line => line.trim().split(/\s+/));
    const route = rows.find(columns => columns[1] === '00000000');
    if (!route) return null;
    const gateway = route[2].match(/../g).reverse().map(hex => parseInt(hex, 16)).join('.');
    return { interface: route[0], gateway };
  } catch {
    return null;
  }
}

/** Addresses and MACs, so IT can find the probe in UniFi. */
function identity() {
  const route = defaultRouteInterface();
  const interfaces = Object.entries(os.networkInterfaces())
    .filter(([name]) => name !== 'lo')
    .map(([name, addresses]) => ({
      name,
      mac: addresses.find(address => address.mac && address.mac !== '00:00:00:00:00:00')?.mac || null,
      ipv4: addresses.filter(address => address.family === 'IPv4' && !address.internal).map(address => `${address.address}/${address.netmask.split('.').reduce((bits, octet) => bits + Number(octet).toString(2).split('1').length - 1, 0)}`),
      default_route: route?.interface === name,
    }));
  return {
    hostname: os.hostname(),
    interfaces,
    gateway: route?.gateway || null,
    dns: resolver ? resolver.getServers() : dns.getServers(),
    dns_mode: resolver ? 'direct' : 'system',
  };
}

/** Whether the kernel has a completed ARP entry for an address (layer 2 works). */
function arpComplete(address) {
  try {
    return readFileSync('/proc/net/arp', 'utf8').split('\n').slice(1)
      .map(line => line.trim().split(/\s+/))
      .some(columns => columns[0] === address && columns[2] === '0x2');
  } catch {
    return false;
  }
}

function tcpReachable(host, port, ms) {
  return new Promise(resolve => {
    const socket = net.connect({ host, port, timeout: ms });
    const done = ok => { socket.destroy(); resolve(ok); };
    socket.on('connect', () => done(true));
    socket.on('timeout', () => done(false));
    socket.on('error', () => done(false));
  });
}

const CERT_ERRORS = new Set(['SELF_SIGNED_CERT_IN_CHAIN', 'DEPTH_ZERO_SELF_SIGNED_CERT', 'UNABLE_TO_VERIFY_LEAF_SIGNATURE', 'UNABLE_TO_GET_ISSUER_CERT_LOCALLY', 'CERT_UNTRUSTED', 'ERR_TLS_CERT_ALTNAME_INVALID']);

async function tryEndpoint(url) {
  const host = new URL(url).hostname;
  const controller = new AbortController();
  const timer = setTimeout(() => controller.abort(), 5000);
  try {
    const page = await fetch(url, { method: 'GET', redirect: 'manual', signal: controller.signal });
    return { host, ok: page.status > 0 && page.status < 500, status: page.status, error: null, issuer: null };
  } catch (error) {
    const code = String(error.cause?.code || error.name || error.message).slice(0, 80);
    return { host, ok: false, status: null, error: code, issuer: CERT_ERRORS.has(code) ? await certificateIssuer(host) : null };
  } finally {
    clearTimeout(timer);
  }
}

/** Who issued the certificate a host presents, read without trusting it (only to name it). */
function certificateIssuer(host) {
  return new Promise(resolve => {
    const socket = tls.connect({ host, port: 443, servername: host, rejectUnauthorized: false, timeout: 4000 }, () => {
      const issuer = socket.getPeerCertificate()?.issuer || {};
      socket.end();
      resolve([issuer.CN, issuer.O].filter(Boolean).join(', ').slice(0, 120) || 'unknown');
    });
    socket.on('timeout', () => { socket.destroy(); resolve('unknown'); });
    socket.on('error', () => resolve('unknown'));
  });
}

/**
 * Self-test for setup: can the probe reach its gateway (ARP), resolve through
 * its DNS server, and load a page on the internet? Each step is independent so
 * the app can say exactly which one fails.
 */
async function diagnose() {
  const info = identity();
  const result = { ...info, gateway_arp: null, gateway_tcp: null, dns_ok: null, dns_error: null, internet_ok: null, internet_error: null };
  if (info.gateway) {
    result.gateway_tcp = await tcpReachable(info.gateway, 53, 1500);
    result.gateway_arp = arpComplete(info.gateway);
  }
  try {
    const answers = resolver ? await resolver.resolve4('example.com') : (await dns.lookup('example.com', { all: true, family: 4 })).map(item => item.address);
    result.dns_ok = answers.length > 0;
  } catch (error) {
    result.dns_ok = false;
    result.dns_error = String(error.code || error.message).slice(0, 80);
  }
  // Several unrelated endpoints, so one host a filter acts on (UniFi intercepting
  // www.google.com for SafeSearch, say) does not read as "no internet". A
  // certificate failure is reported with the issuer: a gateway CA there means
  // the filter is inspecting or blocking that host, not that the network is down.
  const endpoints = ['https://www.gstatic.com/generate_204', 'https://cp.cloudflare.com/', 'https://example.com/', 'https://www.google.com/generate_204'];
  result.internet_results = await Promise.all(endpoints.map(url => tryEndpoint(url)));
  result.internet_ok = result.internet_results.some(item => item.ok);
  result.internet_error = result.internet_ok ? null : (result.internet_results[0]?.error ?? 'no answer');
  result.intercepted = result.internet_results.filter(item => item.issuer).map(item => ({ host: item.host, issuer: item.issuer, error: item.error }));
  return result;
}

const server = http.createServer(async (request, response) => {
  response.setHeader('Content-Type', 'application/json');
  response.setHeader('Cache-Control', 'no-store');
  if (request.url === '/health') {
    return response.end(JSON.stringify({ ok: true, dns: resolver ? 'direct' : 'system', fresh_browser_per_run: !reuseBrowser,
      runs: { active: activeRuns, waiting: waitingRuns.length, max: maxConcurrentRuns, queue_timeout_ms: queueTimeoutMs } }));
  }
  if (request.method === 'GET' && request.url === '/diagnose') {
    if (!authorised(request.headers['x-probe-key'])) { response.statusCode = 401; return response.end(JSON.stringify({ message: 'Unauthorised.' })); }
    return response.end(JSON.stringify(await diagnose()));
  }
  if (request.method === 'GET' && request.url === '/identity') {
    if (!authorised(request.headers['x-probe-key'])) { response.statusCode = 401; return response.end(JSON.stringify({ message: 'Unauthorised.' })); }
    return response.end(JSON.stringify(identity()));
  }
  if (request.method !== 'POST' || !['/check', '/check-batch'].includes(request.url)) {
    response.statusCode = 404;
    return response.end(JSON.stringify({ message: 'Not found.' }));
  }
  if (!authorised(request.headers['x-probe-key'])) { response.statusCode = 401; return response.end(JSON.stringify({ message: 'Unauthorised.' })); }

  try {
    const payload = await readJson(request, 16384);
    const screenshot = Boolean(payload.screenshot);

    if (request.url === '/check') {
      const result = await withRunBrowser(browser => safeInspect(browser, payload.url, screenshot));
      if (result.status === 'probe_error') response.statusCode = 422;
      return response.end(JSON.stringify(result));
    }

    const urls = Array.isArray(payload.urls) ? payload.urls.slice(0, maxBatch) : [];
    if (!urls.length) { response.statusCode = 422; return response.end(JSON.stringify({ message: 'No URLs supplied.' })); }
    const results = await withRunBrowser(browser => Promise.all(urls.map(url => safeInspect(browser, url, screenshot))));
    return response.end(JSON.stringify({ results }));
  } catch (error) {
    if (error instanceof ProbeBusy) {
      response.statusCode = 503;
      response.setHeader('Retry-After', '10');
      return response.end(JSON.stringify({ status: 'probe_busy', message: error.message }));
    }
    response.statusCode = 422;
    response.end(JSON.stringify({ status: 'probe_error', message: String(error.message || 'Check failed.').slice(0, 300) }));
  }
});

server.listen(port, '0.0.0.0');

for (const signal of ['SIGTERM', 'SIGINT']) {
  process.on(signal, async () => { server.close(); if (warmBrowser) await warmBrowser.close(); process.exit(0); });
}
