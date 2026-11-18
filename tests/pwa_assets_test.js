const assert=require('node:assert/strict'),fs=require('node:fs'),vm=require('node:vm');
const manifest=JSON.parse(fs.readFileSync('manifest.json','utf8'));
assert.equal(manifest.display,'standalone');assert.equal(manifest.scope,'./');assert.equal(manifest.start_url,'./');
for(const size of ['192x192','512x512']){const icon=manifest.icons.find(i=>i.sizes===size);assert(icon);const b=fs.readFileSync(icon.src);assert.equal(b.toString('hex',0,8),'89504e470d0a1a0a');assert.equal(b.readUInt32BE(16),Number(size.split('x')[0]));assert.equal(b.readUInt32BE(20),Number(size.split('x')[1]));}
let handlers={};const self={location:{origin:'https://test.example'},registration:{scope:'https://test.example/app/'},clients:{claim:async()=>{},matchAll:async()=>[],openWindow:async()=>{}},skipWaiting(){},addEventListener:(n,fn)=>handlers[n]=fn};const caches={open:async()=>({addAll:async()=>{}}),keys:async()=>[],delete:async()=>{},match:async()=>({offline:true})};vm.runInNewContext(fs.readFileSync('service-worker.js','utf8'),{self,caches,URL,fetch:async()=>({ok:true}),Promise});
let intercepted=false;handlers.fetch({request:{url:'https://test.example/app/api.php?action=tasks_list',method:'GET',mode:'cors'},respondWith(){intercepted=true}});assert.equal(intercepted,false,'private API must bypass service worker cache');
handlers.fetch({request:{url:'https://test.example/app/',method:'POST',mode:'navigate'},respondWith(){intercepted=true}});assert.equal(intercepted,false,'mutating requests must bypass service worker');
handlers.fetch({request:{url:'https://test.example/app/',method:'GET',mode:'navigate'},respondWith(){intercepted=true}});assert.equal(intercepted,true,'offline navigation fallback');
console.log('Manifest, icon dimensions and private-data cache policy: OK');
