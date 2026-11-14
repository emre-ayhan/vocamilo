import test from 'node:test';
import assert from 'node:assert/strict';
import {voicePreference} from './speech-preference.js';
import {readFileSync} from 'node:fs';
test('voice preference is scoped per collection and user without storing enabled state',()=>{
 const data=new Map(),storage={getItem:key=>data.get(key),setItem:(key,value)=>data.set(key,value)};
 const pref=voicePreference(storage,1,5);pref.write('local-en');
 assert.equal(pref.read(),'local-en');
 assert.equal(voicePreference(storage,2,5).read(),'');
 assert.equal(voicePreference(storage,1,6).read(),'');
 assert.equal(voicePreference(storage,1).read(),'');
 pref.write('');assert.equal(pref.read(),'local-en');
 assert.deepEqual([...data.values()],['local-en']);
});
test('unavailable or denied storage is harmless',()=>{
 for(const storage of [undefined,{getItem(){throw Error();},setItem(){throw Error();}}]){
 const pref=voicePreference(storage,1);assert.equal(pref.read(),'');assert.doesNotThrow(()=>pref.write('voice'));
 }
});
test('saved voice restores only from available local voices and never turns speech on',()=>{
 const source=readFileSync(new URL('../Pages/Study/Session.vue',import.meta.url),'utf8');
 assert.match(source,/speechEnabled=ref\(false\)/);
 assert.match(source,/voices.value=localVoices\(synthesis\)/);
 assert.match(source,/voices.value.some\(voice=>voice.voiceURI===rememberedVoice\)/);
 assert.doesNotMatch(source,/speechEnabled.value=true/);
});
