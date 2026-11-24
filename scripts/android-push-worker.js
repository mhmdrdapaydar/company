#!/usr/bin/env node
'use strict';
// Optional server-side FCM HTTP v1 worker. Service-account credentials never leave this process.
const crypto = require('node:crypto');
const fs = require('node:fs');
function configFromEnv(env) {
  const base = new URL(env.PUSH_APP_URL || '');
  if (base.protocol !== 'https:' && !(base.protocol === 'http:' && ['localhost','127.0.0.1','[::1]'].includes(base.hostname))) throw Error('PUSH_APP_URL must use HTTPS except on loopback.');
  if (base.username || base.password || base.search || base.hash) throw Error('Invalid PUSH_APP_URL.');
  if (!base.pathname.endsWith('/')) base.pathname += '/';
  if ((env.ANDROID_PUSH_WORKER_TOKEN || '').length < 32) throw Error('ANDROID_PUSH_WORKER_TOKEN must contain at least 32 characters.');
  if (!env.FCM_SERVICE_ACCOUNT_FILE) throw Error('FCM_SERVICE_ACCOUNT_FILE is required.');
  const account = JSON.parse(fs.readFileSync(env.FCM_SERVICE_ACCOUNT_FILE, 'utf8'));
  if (!/^[a-z][a-z0-9-]{4,62}$/.test(account.project_id || '') || !/^[^\s@]+@[^\s@]+$/.test(account.client_email || '') || !String(account.private_key || '').includes('PRIVATE KEY')) throw Error('Invalid service account file.');
  return {base, token:env.ANDROID_PUSH_WORKER_TOKEN, account};
}
function jwt(account, now = Math.floor(Date.now()/1000)) {
  const encode = x => Buffer.from(JSON.stringify(x)).toString('base64url');
  const input = encode({alg:'RS256',typ:'JWT'}) + '.' + encode({iss:account.client_email,scope:'https://www.googleapis.com/auth/firebase.messaging',aud:'https://oauth2.googleapis.com/token',iat:now,exp:now+3600});
  return input + '.' + crypto.sign('RSA-SHA256', Buffer.from(input), account.private_key).toString('base64url');
}
function createSender(account, fetcher = fetch) {
  let accessToken = '', expires = 0;
  return async function send(job) {
    if (!accessToken || Date.now() >= expires) {
      const res = await fetcher('https://oauth2.googleapis.com/token', {method:'POST', redirect:'error', signal:AbortSignal.timeout(15000), headers:{'Content-Type':'application/x-www-form-urlencoded'},body:new URLSearchParams({grant_type:'urn:ietf:params:oauth:grant-type:jwt-bearer',assertion:jwt(account)})});
      if (!res.ok) throw Error('FCM authorization failed');
      const data = await res.json();
      if (typeof data.access_token !== 'string' || !data.access_token) throw Error('Invalid authorization response');
      accessToken = data.access_token; expires = Date.now() + Math.max(0, (Number(data.expires_in || 3600)-60)*1000);
    }
    // Data-only message: no private task text, receipt is reported by Android after app handles it.
    const res = await fetcher('https://fcm.googleapis.com/v1/projects/' + encodeURIComponent(account.project_id) + '/messages:send', {
      method:'POST',redirect:'error',signal:AbortSignal.timeout(15000),headers:{Authorization:'Bearer '+accessToken,'Content-Type':'application/json'},
      body:JSON.stringify({message:{token:job.fcm_token,data:{notification_id:String(job.notification_id),event:'notification'},android:{priority:'high',ttl:'86400s'}}})
    });
    if (res.ok) return;
    const body = await res.json().catch(()=>({}));
    const gone = Array.isArray(body?.error?.details) && body.error.details.some(d=>d && d.errorCode === 'UNREGISTERED');
    if (res.status === 401) { accessToken = ''; expires = 0; }
    const error = Error('FCM HTTP ' + res.status); error.gone = gone; throw error;
  };
}
async function processOne(job, send, ack) {
  let result = 'retry';
  try { await send(job); result = 'sent'; } catch (e) { if (e && e.gone) result = 'gone'; }
  await ack({id:job.id,lease:job.lease,fcm_hash:job.fcm_hash,result});
  return result;
}
async function main() {
  const cfg = configFromEnv(process.env), send = createSender(cfg.account);
  let stopped = false; process.on('SIGTERM',()=>{stopped=true}); process.on('SIGINT',()=>{stopped=true});
  async function call(action, data) {
    const r = await fetch(new URL('api.php?action='+action,cfg.base),{method:'POST',redirect:'error',signal:AbortSignal.timeout(15000),headers:{Authorization:'Bearer '+cfg.token,'Content-Type':'application/json'},body:JSON.stringify(data)});
    if (!r.ok) throw Error('Queue API HTTP '+r.status);
    const body = await r.json(); if (!body.ok) throw Error('Queue API rejected request'); return body;
  }
  while (!stopped) {
    try { const {job} = await call('android_push_claim',{}); if (job) { await processOne(job,send,data=>call('android_push_ack',data)); continue; } }
    catch(e) { console.error('Android push worker: '+(e.message || 'temporary failure')); }
    await new Promise(resolve=>setTimeout(resolve,5000));
  }
}
if (require.main === module) main().catch(e=>{console.error('Android push worker setup: '+e.message);process.exitCode=1});
module.exports={configFromEnv,jwt,createSender,processOne};
