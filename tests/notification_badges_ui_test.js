// Requires jsdom and a running app with a disposable account.
// NODE_PATH=/path/to/node_modules BASE_URL=... TEST_USER=... TEST_PASSWORD=... node tests/notification_badges_ui_test.js
const {JSDOM}=require('jsdom'), fs=require('node:fs'), assert=require('node:assert/strict');
const base=process.env.BASE_URL, username=process.env.TEST_USER, password=process.env.TEST_PASSWORD;
if(!base||!username||!password) throw Error('Set BASE_URL, TEST_USER, TEST_PASSWORD');
const cookies={};
async function request(path,opts={}) {const r=await fetch(base+path,{redirect:'manual',...opts,headers:{...opts.headers,cookie:Object.entries(cookies).map(([k,v])=>k+'='+v).join('; ')}}); for(const c of r.headers.getSetCookie()){const [k,...v]=c.split(';')[0].split('=');cookies[k]=v.join('=')} return r.text()}
const sleep=ms=>new Promise(r=>setTimeout(r,ms));
(async()=>{
  const login=await request('/login.php'), csrf=login.match(/name="csrf_token" value="([^"]+)"/)[1];
  await request('/login.php',{method:'POST',headers:{'content-type':'application/x-www-form-urlencoded'},body:new URLSearchParams({username,password,csrf_token:csrf}).toString()});
  const page=await request('/'), appScript=[...page.matchAll(/<script\b[^>]*>([\s\S]*?)<\/script>/gi)].map(x=>x[1]).find(x=>/window\.APP\s*=/.test(x));
  const appdom=new JSDOM('',{runScripts:'outside-only'});appdom.window.eval(appScript);
  const dom=new JSDOM(page.replace(/<script\b[^>]*>[\s\S]*?<\/script>/gi,''),{runScripts:'outside-only',url:base+'/',pretendToBeVisual:true});const w=dom.window;w.APP=appdom.window.APP;
  w.requestAnimationFrame=fn=>setTimeout(fn,0);w.matchMedia=()=>({matches:false,addListener(){},addEventListener(){}});
  let list=[{id:11,title:'one',message:'a',is_read:0,created_ago:'now',created_human:'now'},{id:12,title:'two',message:'b',is_read:0,created_ago:'now',created_human:'now'}], fail=false, resolveRead=null, reads=0;
  function response(body){return {ok:true,status:200,json:async()=>body,text:async()=>JSON.stringify(body),headers:{get:()=>null}}}
  w.fetch=(url,opts={})=>{const action=new URL(String(url),base+'/').searchParams.get('action');let body={ok:true};
    if(action==='notifications_unread') body={ok:true,unread:list.filter(n=>!n.is_read).length,last_id:12};
    if(action==='notifications_list') body={ok:true,notifications:list.map(n=>({...n}))};
    if(action==='notification_read'){reads++;let id=JSON.parse(opts.body).id;return new Promise(resolve=>{resolveRead=()=>{if(fail)resolve(response({ok:false,message:'error'}));else {list=list.map(n=>n.id===id?{...n,is_read:1}:n);resolve(response({ok:true}));}}})}
    if(action==='notifications_read_all') {list=list.map(n=>({...n,is_read:1}));body={ok:true}}
    return Promise.resolve(response(body));
  };
  for(const path of ['assets/js/jalali.js','assets/js/offline-audio.js','assets/js/voice-ai.js','assets/js/app.js'])w.eval(fs.readFileSync(path,'utf8'));
  await sleep(100); const dot=w.document.getElementById('notifDot'), nav=w.document.getElementById('notifNavBadge');
  assert.equal(dot.textContent,'۲');assert.equal(nav.textContent,'۲');
  w.document.querySelector('[data-section="notifications"]').click(); await sleep(50);
  let item=w.document.querySelector('[data-notif="11"]');assert(item);item.click();
  assert.equal(dot.textContent,'۱');assert.equal(nav.textContent,'۱');assert(!item.classList.contains('unread'));item.click();assert.equal(reads,1);
  w.document.dispatchEvent(new w.Event('visibilitychange'));await sleep(10);assert.equal(dot.textContent,'۱');
  resolveRead();await sleep(80);assert.equal(dot.textContent,'۱');assert.equal(nav.textContent,'۱');
  fail=true;item=w.document.querySelector('[data-notif="12"]');item.click();assert.equal(dot.textContent,'۰');assert.equal(nav.style.display,'none');resolveRead();await sleep(80);assert.equal(dot.textContent,'۱');assert.equal(nav.textContent,'۱');assert(w.document.querySelector('[data-notif="12"]').classList.contains('unread'));
  w.document.getElementById('btnReadAll').click();assert.equal(dot.textContent,'۰');assert.equal(nav.style.display,'none');await sleep(80);assert.equal(list.filter(n=>!n.is_read).length,0);
  console.log('Notification badge optimistic update, deduplication, failure rollback, polling and read-all: OK');dom.window.close();
})().catch(e=>{console.error(e);process.exitCode=1});
