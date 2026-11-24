const assert = require('node:assert/strict');
const fs = require('node:fs');
const css = fs.readFileSync('assets/css/app.css','utf8');
const login = fs.readFileSync('login.php','utf8');
const index = fs.readFileSync('index.php','utf8');
function contrast(a,b){function lum(hex){let c=hex.match(/\w\w/g).map(v=>parseInt(v,16)/255).map(v=>v<=.04045?v/12.92:((v+.055)/1.055)**2.4);return .2126*c[0]+.7152*c[1]+.0722*c[2]}let x=lum(a),y=lum(b);return (Math.max(x,y)+.05)/(Math.min(x,y)+.05)}
assert(contrast('ffffff','0f766e')>=4.5);assert(contrast('ffffff','115e59')>=4.5);assert(contrast('fecdd3','4c1022')>=4.5);assert(contrast('5eead4','134e4a')>=4.5);
assert.match(css,/\.icon-btn\{[^}]*color:var\(--text\)/);assert.match(css,/\.icon-btn\.menu-toggle\{width:48px;height:48px;font-size:23px/);assert.match(css,/\.btn-danger\{background:#4c1022;color:#fecdd3/);
assert(!login.includes('demo-box'));assert(!login.includes('admin123'));assert.match(login,/name="username"/);assert.match(login,/name="password"/);assert.match(index,/id="menuToggle"[^>]*aria-label=/);
console.log('Theme button contrast, mobile menu touch target and login sample removal: OK');
