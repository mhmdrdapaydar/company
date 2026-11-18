// Live API integration; supply BASE_URL, TEST_USER, TEST_PASSWORD.
const assert=require('node:assert/strict');
const base=process.env.BASE_URL, user=process.env.TEST_USER, password=process.env.TEST_PASSWORD;
if (!base||!user||!password) throw Error('Set BASE_URL, TEST_USER and TEST_PASSWORD');
const cookies={};
async function req(path,options={}) {const r=await fetch(base+path,{redirect:'manual',...options,headers:{...options.headers,cookie:Object.entries(cookies).map(([k,v])=>k+'='+v).join('; ')}});for(const c of r.headers.getSetCookie()){const [k,...v]=c.split(';')[0].split('=');cookies[k]=v.join('=')}const text=await r.text();let json;try{json=JSON.parse(text)}catch{}return {status:r.status,text,json};}
(async()=>{const login=await req('/login.php');const token=login.text.match(/name="csrf_token" value="([^"]+)"/)[1];await req('/login.php',{method:'POST',headers:{'content-type':'application/x-www-form-urlencoded'},body:new URLSearchParams({username:user,password,csrf_token:token}).toString()});const csrf=(await req('/api.php?action=bootstrap')).json.csrf_token;const post=async transcript=>(await req('/api.php?action=voice_preview',{method:'POST',headers:{'content-type':'application/json','X-CSRF-Token':csrf},body:JSON.stringify({csrf_token:csrf,transcript})})).json;
const before=(await req('/api.php?action=tasks_list&scope=team')).json.tasks;
const example='سلام، برای فرداغ یک تسک تعین کن با عنوان پالشگاه ملی و همچنین زیر مجموعه اون را سارا بذار. و راجع به مراهل شنبه زن، تماس با پالشگاه، مرحله دومشو، بزار، تماس با منشی. همین دوتا رو قرار بده برام برای ساعت هفته صبح فرداغ.';
const result=await post(example);assert(result.ok,JSON.stringify(result));assert.equal(result.tasks.length,1);const t=result.tasks[0];assert.equal(t.title,'پالشگاه ملی');assert.equal(t.assigned_name,'سارا محمدی');assert.equal(t.date_reason,'فردا');assert.equal(t.due_time,'07:00');assert.deepEqual(t.subtasks,['تماس با پالشگاه','تماس با منشی']);assert(result.notes.some(n=>n.includes('مبهم')));
const project=await post('سلام، برای فرداغ یک پروژه تایین بکن برای ساعت نه. سرگروهش رو هم بذار سارا.');assert.equal(project.ok,false);assert.match(project.message,/پروژه/);assert.match(project.message,/بخش پروژه/);
const after=(await req('/api.php?action=tasks_list&scope=team')).json.tasks;assert.equal(after.length,before.length);console.log('Exact voice examples and no task creation: OK');
})().catch(e=>{console.error(e);process.exitCode=1});
