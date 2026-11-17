// Integration smoke test against a running server with an admin test account.
// BASE_URL, TEST_USER and TEST_PASSWORD must be supplied by the operator.
const assert = require('node:assert/strict');
const base = process.env.BASE_URL, user = process.env.TEST_USER, password = process.env.TEST_PASSWORD;
if (!base || !user || !password) throw new Error('Set BASE_URL, TEST_USER and TEST_PASSWORD');
const cookies = {};
async function request(path, opts = {}) {
  const response = await fetch(base + path, {redirect:'manual', ...opts, headers:{...opts.headers, cookie:Object.entries(cookies).map(([k,v]) => k+'='+v).join('; ')}});
  for (const cookie of response.headers.getSetCookie()) { const [k,...v] = cookie.split(';')[0].split('='); cookies[k] = v.join('='); }
  const text = await response.text(); let json; try { json = JSON.parse(text); } catch {} return {response,text,json};
}
(async () => {
  const page = await request('/login.php');
  const token = page.text.match(/name="csrf_token" value="([^"]+)"/)[1];
  await request('/login.php', {method:'POST', headers:{'content-type':'application/x-www-form-urlencoded'}, body:new URLSearchParams({username:user,password,csrf_token:token}).toString()});
  const home = await request('/'); assert.equal(home.response.status,200);
  for (const id of ['section-attendance','section-leaves','dashAttendanceBox','btnClockIn','btnNewLeave']) assert(!home.text.includes(id),id);
  assert(home.text.includes('section-dashboard')); assert(home.text.includes('section-tasks'));
  const bootstrap = await request('/api.php?action=bootstrap'); assert(bootstrap.json.csrf_token);
  const stats = await request('/api.php?action=dashboard_stats'); assert(stats.json.ok); assert(!('attendance_today' in stats.json)); assert(!('pending_leaves' in stats.json));
  for (const action of ['attendance_today','attendance_list','attendance_user','attendance_team','leaves_list']) { const r=await request('/api.php?action='+action); assert(!r.json?.ok,action); }
  for (const action of ['attendance_clock_in','attendance_clock_out','attendance_manual','attendance_delete','leave_create','leave_review']) {
    const r=await request('/api.php?action='+action, {method:'POST',headers:{'content-type':'application/json','X-CSRF-Token':bootstrap.json.csrf_token},body:JSON.stringify({csrf_token:bootstrap.json.csrf_token})}); assert(!r.json?.ok,action);
  }
  for (const type of ['attendance','leaves']) { const r=await request('/export.php?type='+type); assert(r.text.includes('نوع گزارش نامعتبر')); }
  console.log('Removed sections, API routes, exports and dashboard: OK');
})().catch(e => { console.error(e); process.exitCode=1; });
