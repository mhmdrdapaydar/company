// Verify an asynchronously granted microphone is never left recording after release/close.
const {JSDOM}=require('jsdom'),fs=require('fs'),assert=require('node:assert/strict');
(async()=>{
 const dom=new JSDOM('',{runScripts:'outside-only'}),w=dom.window;
 w.eval(fs.readFileSync('assets/js/voice-ai.js','utf8'));
 let resolve,stopped=0;
 Object.defineProperty(w.navigator,'mediaDevices',{value:{getUserMedia:()=>new Promise(r=>resolve=r)}});
 let voice=w.VoiceAI;
 for(const name of ['stopBrowserSpeech','stopVad','stopTimer','stopWaveform','setRecordingUI','stopStream'])voice[name]=()=>{};
 let held=true,p=voice.startRecord(()=>held);held=false;voice.stopRecord();resolve({getTracks:()=>[{stop:()=>stopped++}]});await p;
 assert.equal(stopped,1);assert(!voice.recording);
 let p2=voice.startRecord();voice.stopRecord(true);resolve({getTracks:()=>[{stop:()=>stopped++}]});await p2;
 assert.equal(stopped,2,'closing also invalidates pending permission');
 console.log('Pending microphone permission is canceled on release and close: OK');dom.window.close();
})().catch(e=>{console.error(e);process.exitCode=1});
