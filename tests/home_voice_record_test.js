// Simulated MediaRecorder + speech recognition; no microphone, external AI, or live network.
const {JSDOM}=require('jsdom'),fs=require('fs'),assert=require('node:assert/strict');
const wait=ms=>new Promise(r=>setTimeout(r,ms));
(async()=>{
 const dom=new JSDOM('<!doctype html><body><div id="toastWrap"></div></body>',{url:'https://example.test/',runScripts:'outside-only',pretendToBeVisual:true}),w=dom.window;
 w.HTMLCanvasElement.prototype.getContext=()=>({clearRect(){},beginPath(){},moveTo(){},lineTo(){},stroke(){}});w.URL.createObjectURL=()=> 'blob:recording';w.URL.revokeObjectURL=()=>{};
 let tracksStopped=0,received=[];Object.defineProperty(w.navigator,'mediaDevices',{configurable:true,value:{getUserMedia:async()=>({getTracks:()=>[{stop:()=>tracksStopped++}]})}});
 class Recorder{constructor(){this.state='inactive';this.mimeType='audio/webm'}static isTypeSupported(){return true}start(){this.state='recording'}stop(){this.state='inactive';setTimeout(()=>{if(this.ondataavailable)this.ondataavailable({data:new w.Blob(['audio'],{type:'audio/webm'})});if(this.onstop)this.onstop()},10)}}
 class Speech{start(){}stop(){setTimeout(()=>{if(this.onresult)this.onresult({resultIndex:0,results:Object.assign([[{transcript:'جلسه فردا'}]],{length:1})});},10)}abort(){}}
 // Simulate a final recognition result just after stop.
 Speech.prototype.stop=function(){setTimeout(()=>{if(this.onresult){let result=[{transcript:'جلسه فردا'}];result.isFinal=true;this.onresult({resultIndex:0,results:[result]})}},10)};
 w.MediaRecorder=Recorder;w.SpeechRecognition=Speech;w.eval(fs.readFileSync('assets/js/jalali.js','utf8'));w.eval(fs.readFileSync('assets/js/voice-ai.js','utf8'));
 const voice=w.VoiceAI;voice.init({users:[{id:1,full_name:'علی'}],isManager:false,api:async(action,body)=>{received.push({action,transcript:body.get('transcript'),audio:body.get('audio')});return {ok:true,transcript:body.get('transcript'),provider:'test',tasks:[{title:'جلسه',assigned_to:1}],requires_review:true}}});
 voice.reset();voice.homeHoldActive=true;let held=true;await voice.startRecord(()=>held,true);
 assert(voice.recording);assert(!voice.el.classList.contains('open'),'hold stays on home page');
 held=false;voice.homeHoldActive=false;voice.finishHomeRecording();assert(voice.el.classList.contains('open'));assert(!w.document.getElementById('voiceProcessing').classList.contains('hidden'),'immediate preparing indicator');assert.equal(received.length,0,'wait for final speech and audio');
 await wait(1500);assert.equal(received.length,1);assert.equal(received[0].action,'voice_preview');assert.equal(received[0].transcript,'جلسه فردا');assert(received[0].audio.size>0,'recorded audio included');assert(!w.document.getElementById('voicePreviewWrap').classList.contains('hidden'));assert(tracksStopped>0);
 // Closing during the final-recognition grace period must cancel automatic processing.
 voice.close();received=[];voice.reset();voice.homeHoldActive=true;await voice.startRecord(()=>true,true);voice.homeHoldActive=false;voice.finishHomeRecording();voice.close();await wait(1500);assert.equal(received.length,0,'close cancels pending processing');
 // Release while the browser's microphone permission prompt is pending.
 let allow;Object.defineProperty(w.navigator,'mediaDevices',{configurable:true,value:{getUserMedia:()=>new Promise(r=>allow=r)}});
 voice.reset();voice.homeHoldActive=true;let pendingHeld=true;let pending=voice.startRecord(()=>pendingHeld,true);pendingHeld=false;voice.homeHoldActive=false;voice.finishHomeRecording();
 assert.match(w.document.getElementById('recStatus').textContent,/ضبطی آماده نشد/);let lateStopped=0;allow({getTracks:()=>[{stop:()=>lateStopped++}]});await pending;assert.equal(lateStopped,1);assert(!voice.recording);assert(w.document.getElementById('voiceProcessing').classList.contains('hidden'));voice.close();
 console.log('Hold recording, release processing, final transcript and cancellation: OK');dom.window.close();
})().catch(e=>{console.error(e);process.exitCode=1});
