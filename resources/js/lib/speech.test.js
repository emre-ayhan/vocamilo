import test from 'node:test';
import assert from 'node:assert/strict';
import { createLocalSpeech, localVoices } from './speech.js';
const voice={voiceURI:'local-en',lang:'en-US',localService:true};
function setup(){const events=[];let voices=[voice,{voiceURI:'remote',localService:false},{voiceURI:'unknown'}];return {events,setVoices(value){voices=value;},synthesis:{getVoices:()=>voices,cancel(){events.push('cancel');},speak(value){events.push(value);}}};}
class Utterance {constructor(text){this.text=text;}}
test('voice discovery only permits explicitly local voices',()=>{const {synthesis}=setup();assert.deepEqual(localVoices(synthesis),[voice]);assert.deepEqual(localVoices(null),[]);});
test('speech explicitly selects local voice and language, cancelling any previous utterance',()=>{const {synthesis,events}=setup();const speech=createLocalSpeech(synthesis,Utterance);assert.equal(speech.speak('hello','local-en'),true);assert.equal(events[0],'cancel');assert.equal(events[1].voice,voice);assert.equal(events[1].lang,'en-US');assert.equal(events[1].text,'hello');speech.speak('answer','local-en');assert.equal(events[2],'cancel');assert.equal(events[3].text,'answer');});
test('remote, unknown, missing, blank and removed voices never fall back',()=>{const {synthesis,events,setVoices}=setup();const speech=createLocalSpeech(synthesis,Utterance);for(const uri of ['remote','unknown','missing',''])assert.equal(speech.speak('text',uri),false);assert.equal(speech.speak('   ','local-en'),false);setVoices([]);assert.equal(speech.speak('text','local-en'),false);assert.ok(events.every(e=>e==='cancel'));});
test('unavailable API is unsupported and safe; disposal prevents future speech',()=>{const missing=createLocalSpeech(null,null);assert.equal(missing.supported,false);assert.equal(missing.speak('text','local-en'),false);missing.cancel();missing.dispose();const {synthesis,events}=setup();const speech=createLocalSpeech(synthesis,Utterance);speech.dispose();assert.equal(speech.speak('text','local-en'),false);assert.deepEqual(events,['cancel','cancel']);});

test('module controls have no selector, default off, and read the visible flashcard side',async()=>{
 const {readFile}=await import('node:fs/promises');
 const source=await readFile(new URL('../Pages/Study/Session.vue',import.meta.url),'utf8');
 assert.match(source,/speechEnabled=ref\(false\)/);
 assert.doesNotMatch(source,/select v-model="voiceURI"/);
 assert.match(source,/props.mode==='flashcards'&&flipped.value\?current.value.expected:current.value.prompt/);
 assert.match(source,/watch\(\[index,started,finished,questions,flipped\]/);
 assert.match(source,/class="flashcard-tools"/);
 assert.match(source,/role="switch" :aria-checked="speechEnabled" :disabled="!speechAvailable\|\|!voiceURI"/);
 assert.doesNotMatch(source,/voiceMemory.write/);
});

test('composite identity selects duplicate URI voices by name and language regardless of ordering',async()=>{
 const {voiceIdentity,resolveLocalVoice,selectableLocalVoices}=await import('./speech.js');
 const a={voiceURI:'same',name:'English 1',lang:'en-US',localService:true},b={...a,name:'English 2'};
 assert.equal(resolveLocalVoice([a,b],'same'),null);
 assert.equal(resolveLocalVoice([b,a],voiceIdentity(b)),b);
 assert.equal(resolveLocalVoice([a,b],voiceIdentity(b)),b);
 assert.equal(resolveLocalVoice([a,{...a}],voiceIdentity(a)),null);
 assert.equal(selectableLocalVoices([a,{...a},b]).length,1);
 assert.equal(resolveLocalVoice([{...b,localService:false}],voiceIdentity(b)),null);
 const {synthesis,events,setVoices}=setup();setVoices([b,a]);const speech=createLocalSpeech(synthesis,Utterance);
 assert.equal(speech.speak('test',voiceIdentity(b)),true);assert.equal(events.at(-1).voice,b);
 setVoices([a]);assert.equal(speech.speak('test',voiceIdentity(b)),false);
});
test('delayed voices, resume and storage events refresh inventory and disposer removes listeners',async()=>{
 const {observeVoiceChanges}=await import('./speech.js');
 const synth=new EventTarget(),win=new EventTarget(),doc=new EventTarget();doc.visibilityState='visible';let count=0;
 const stop=observeVoiceChanges(synth,win,doc,()=>count++);assert.equal(count,1);
 synth.dispatchEvent(new Event('voiceschanged'));for(const key of ['focus','pageshow','storage'])win.dispatchEvent(new Event(key));doc.dispatchEvent(new Event('visibilitychange'));assert.equal(count,6);
 doc.visibilityState='hidden';doc.dispatchEvent(new Event('visibilitychange'));assert.equal(count,6);
 stop();synth.dispatchEvent(new Event('voiceschanged'));win.dispatchEvent(new Event('focus'));assert.equal(count,6);
});
