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

test('Study UI defaults off and keeps speech controls above active questions',async()=>{
 const {readFile}=await import('node:fs/promises');
 const source=await readFile(new URL('../Pages/Study/Session.vue',import.meta.url),'utf8');
 assert.match(source,/speechEnabled=ref\(false\)/);
 assert.match(source,/v-if="mode==='study'&&words.length"/);
 assert.ok(source.indexOf('id="speech-title"')<source.indexOf('class="panel question-panel"'));
 assert.match(source,/role="switch" :aria-checked="speechEnabled" :disabled="!speechAvailable"/);
 assert.match(source,/v-if="speechEnabled&&speechAvailable"/);
 assert.match(source,/select v-model="voiceURI" required aria-required="true"/);
 assert.match(source,/speechEnabled.value&&voiceURI.value/);
 assert.match(source,/speech-toggle input:focus-visible/);
 assert.match(source,/speech-toggle\{display:flex;flex-direction:row/);
 assert.match(source,/watch\(\[index,started,finished,questions\],\(\)=>\{speech.cancel\(\);speakQuestion\(\);\}\)/);
 assert.match(source,/current.value.type\)\)check\(\);speak\(value\)/);
 assert.match(source,/removeEventListener\('voiceschanged',refreshVoices\);speech.dispose\(\)/);
});
