// NODE_PATH must provide jsdom. No microphone or network is needed.
const {JSDOM}=require('jsdom'),fs=require('fs'),assert=require('assert/strict');
const dom=new JSDOM('<!doctype html><body></body>',{url:'https://example.test/',runScripts:'outside-only'}),w=dom.window;
w.eval(fs.readFileSync('assets/js/voice-ai.js','utf8'));w.VoiceAI.init({api:()=>Promise.resolve({ok:true}),isManager:true,users:[{id:1,full_name:'علی رضایی'},{id:2,full_name:'مینا کریمی'},{id:3,full_name:'سارا محمدی'}]});
w.VoiceAI.previewTasks=[{title:'جلسه',assigned_to:1}];
function select(text){w.document.getElementById('voiceTranscript').value=text;w.VoiceAI.renderPreview();return Array.from(w.document.querySelector('#voicePreviewList select').selectedOptions).map(x=>Number(x.value));}
assert.deepEqual(select('فردا جلسه با علی و مینا'),[1,2]);assert.deepEqual(Array.from(w.VoiceAI.collect()[0].assigned_ids),[1,2]);assert.deepEqual(select('فردا جلسه همه کارمندان بیان'),[1,2,3]);assert.deepEqual(select('فردا جلسه علیه مشکلات'),[1]);assert(!w.document.querySelector('#voiceTaskTpl').content.querySelector('[data-f=priority]'));assert(!w.document.querySelector('#voiceTaskTpl').content.querySelector('[data-f=subtasks]'));console.log('Voice preview: multi-name and everyone selection with confirmation: OK');dom.window.close();
