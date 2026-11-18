#!/usr/bin/env node
'use strict';
const webpush = require('web-push');

// Explicit allowlist prevents a stored endpoint from being used as an SSRF proxy.
function allowedEndpoint(raw) {
  try {
    const u = new URL(raw), h = u.hostname.toLowerCase();
    return u.protocol === 'https:' && !u.username && !u.password && !u.hash && (!u.port || u.port === '443') &&
      (h === 'fcm.googleapis.com' || h === 'updates.push.services.mozilla.com' || /^(?:[a-z0-9-]+\.)+(?:push\.apple\.com|notify\.windows\.com|wns\.windows\.com)$/.test(h));
  } catch { return false; }
}
async function processOne(job, send, ack) {
  let outcome = 'retry';
  try {
    if (!allowedEndpoint(job.subscription.endpoint)) { outcome = 'gone'; }
    else {
      // The encrypted payload contains only an ID, never private task content.
      await send(job.subscription, JSON.stringify({ id: Number(job.notification_id) }), { TTL: 86400, timeout: 10000 });
      outcome = 'sent';
    }
  } catch (e) {
    if (e && (e.statusCode === 404 || e.statusCode === 410)) outcome = 'gone';
  }
  await ack({ id: job.id, lease: job.lease, result: outcome });
  return outcome;
}
function configFromEnv(env) {
  const base = new URL(env.PUSH_APP_URL || '');
  if (base.protocol !== 'https:' && !(base.protocol === 'http:' && ['localhost','127.0.0.1','[::1]'].includes(base.hostname))) throw new Error('PUSH_APP_URL must use HTTPS (or loopback for development).');
  if (base.username || base.password || base.search || base.hash) throw new Error('PUSH_APP_URL must not contain credentials, query or fragment.');
  if (!base.pathname.endsWith('/')) base.pathname += '/';
  if ((env.WEB_PUSH_WORKER_TOKEN || '').length < 32) throw new Error('WEB_PUSH_WORKER_TOKEN must contain at least 32 characters.');
  if (!env.WEB_PUSH_PUBLIC_KEY || !env.WEB_PUSH_PRIVATE_KEY || !env.WEB_PUSH_SUBJECT) throw new Error('WEB_PUSH_PUBLIC_KEY, WEB_PUSH_PRIVATE_KEY and WEB_PUSH_SUBJECT are required.');
  return {base,token:env.WEB_PUSH_WORKER_TOKEN,publicKey:env.WEB_PUSH_PUBLIC_KEY,privateKey:env.WEB_PUSH_PRIVATE_KEY,subject:env.WEB_PUSH_SUBJECT};
}
async function main() {
  const cfg = configFromEnv(process.env);
  webpush.setVapidDetails(cfg.subject, cfg.publicKey, cfg.privateKey);
  let stopped = false;
  process.on('SIGTERM', () => { stopped = true; });
  process.on('SIGINT', () => { stopped = true; });
  async function call(action, data) {
    const url = new URL('api.php?action=' + action, cfg.base);
    const res = await fetch(url, {method:'POST', redirect:'error', signal:AbortSignal.timeout(15000), headers:{'Authorization':'Bearer ' + cfg.token,'Content-Type':'application/json'}, body:JSON.stringify(data)});
    if (!res.ok) throw new Error('Queue API HTTP ' + res.status);
    const json = await res.json();
    if (!json.ok) throw new Error('Queue API rejected request');
    return json;
  }
  while (!stopped) {
    try {
      const {job} = await call('push_claim', {});
      if (job) { await processOne(job, webpush.sendNotification.bind(webpush), data => call('push_ack', data)); continue; }
    } catch (e) { console.error('Push worker: ' + (e.message || 'temporary error')); }
    await new Promise(resolve => setTimeout(resolve, 5000));
  }
}
if (require.main === module) main().catch(e => { console.error('Push worker setup: ' + e.message); process.exitCode=1; });
module.exports = {allowedEndpoint, processOne, configFromEnv};
