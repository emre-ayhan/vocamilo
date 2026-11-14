import test from 'node:test';
import assert from 'node:assert/strict';
import {voicePreference,collectionVoicePreference} from './speech-preference.js';
import {readFileSync} from 'node:fs';
test('voice preference is scoped per collection and user without storing enabled state',()=>{
 const data=new Map(),storage={getItem:key=>data.get(key),setItem:(key,value)=>data.set(key,value)};
 const pref=voicePreference(storage,1,5);pref.write('local-en');
 assert.equal(pref.read(),'local-en');
 assert.equal(voicePreference(storage,2,5).read(),'');
 assert.equal(voicePreference(storage,1,6).read(),'');
 assert.equal(voicePreference(storage,1).read(),'');
 pref.write('');assert.equal(pref.read(),'');
 assert.deepEqual([...data.values()],['']);
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

test('shared Show and sessions use UUID identity and legacy id fallback does not overwrite explicit clearing',()=>{
 const data=new Map(),storage={getItem:k=>data.get(k),setItem:(k,v)=>data.set(k,v)};
 voicePreference(storage,7,'guest').write('legacy');
 assert.equal(collectionVoicePreference(storage,{id:7,share_uuid:'abc'},'guest',true).read(),'legacy');
 collectionVoicePreference(storage,{id:null,share_uuid:'abc'},'guest',true).write('local-pt');
 assert.equal(collectionVoicePreference(storage,{id:7,share_uuid:'abc'},'guest',true).read(),'local-pt');
 collectionVoicePreference(storage,{share_uuid:'abc'},'guest',true).write('');
 assert.equal(collectionVoicePreference(storage,{id:7,share_uuid:'abc'},'guest',true).read(),'');
 assert.equal(voicePreference(storage,7,'guest').read(),'legacy');
});
test('storage failure is observable',()=>{assert.equal(voicePreference(undefined,1).write('voice'),false);});
test('collection forms keep voice outside server form data and save only through explicit completion',()=>{
 const index=readFileSync(new URL('../Pages/Collections/Index.vue',import.meta.url),'utf8');
 const show=readFileSync(new URL('../Pages/Collections/Show.vue',import.meta.url),'utf8');
 const picker=readFileSync(new URL('../Components/LocalVoicePicker.vue',import.meta.url),'utf8');
 assert.match(index,/useForm\(\{ title: '', description: '' \}\)/);
 assert.match(index,/onSuccess: response =>/);
 assert.match(index,/response.props.collection.id/);
 assert.match(index,/draftVoice='';open = true/);
 assert.match(show,/function openVoice\(\)\{voiceDraft.value=voiceMemory.read\(\)/);
 assert.match(show,/onSuccess: \(\) => \{persistVoice\(voiceDraft\)/);
 assert.match(show,/@submit.prevent="saveVoice"/);
 assert.doesNotMatch(picker,/localStorage|voiceMemory|fetch\(/);
 assert.match(picker,/localVoices\(synthesis\)/);
 assert.match(picker,/addEventListener\('voiceschanged',refresh\)/);
});
