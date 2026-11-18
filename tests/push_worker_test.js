const assert=require('node:assert/strict');const {allowedEndpoint,processOne,configFromEnv}=require('../scripts/push-worker');
(async()=>{assert(allowedEndpoint('https://fcm.googleapis.com/fcm/send/example'));for(const e of ['https://fcm.googleapis.com.evil.test/x','http://fcm.googleapis.com/x','https://localhost/x','https://fcm.googleapis.com:8443/x'])assert(!allowedEndpoint(e));
const job={id:1,lease:'abc',notification_id:42,subscription:{endpoint:'https://fcm.googleapis.com/fcm/send/example',keys:{}}};let ack;
assert.equal(await processOne(job,async(s,p)=>assert.equal(p,JSON.stringify({id:42})),async a=>{ack=a}),'sent');assert.equal(ack.result,'sent');
assert.equal(await processOne(job,async()=>{throw {statusCode:503}},async a=>{ack=a}),'retry');assert.equal(ack.result,'retry');
assert.equal(await processOne(job,async()=>{throw {statusCode:410}},async a=>{ack=a}),'gone');
assert.equal(await processOne({...job,subscription:{endpoint:'https://localhost'}},async()=>{throw Error('must not send')},async a=>{ack=a}),'gone');
assert.throws(()=>configFromEnv({PUSH_APP_URL:'http://external.example/',WEB_PUSH_WORKER_TOKEN:'a'.repeat(32),WEB_PUSH_PUBLIC_KEY:'x',WEB_PUSH_PRIVATE_KEY:'x',WEB_PUSH_SUBJECT:'mailto:a@b.example'}));
console.log('Push worker validation, payload, transient retry and expired subscription: OK');})().catch(e=>{console.error(e);process.exitCode=1});
