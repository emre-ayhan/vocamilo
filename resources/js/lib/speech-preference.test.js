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
 assert.match(source,/resolveLocalVoice\(voices.value,rememberedVoice\)/);
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
 assert.match(picker,/observeVoiceChanges\(synthesis,window,document,refresh\)/);
});

test('guest voice never transfers into signed-in owner or member scopes',()=>{
 const data=new Map(),storage={getItem:k=>data.get(k),setItem:(k,v)=>data.set(k,v)};
 const collection={id:31,share_uuid:'public-uuid'};
 voicePreference(storage,31,'guest').write('legacy-guest');
 collectionVoicePreference(storage,collection,'guest',true).write('guest-voice');
 for(const user of [1,2]){
  assert.equal(collectionVoicePreference(storage,collection,user,true).read(),'');
  assert.equal(collectionVoicePreference(storage,collection,user,false).read(),'');
 }
 collectionVoicePreference(storage,collection,1,true).write('member-voice');
 assert.equal(collectionVoicePreference(storage,collection,2,true).read(),'');
 assert.equal(collectionVoicePreference(storage,collection,'guest',true).read(),'guest-voice');
 collectionVoicePreference(storage,collection,1,true).write('');
 assert.equal(collectionVoicePreference(storage,collection,'guest',true).read(),'guest-voice');
});
test('voice is browser local; public link rotation does not inherit the old shared selection',()=>{
 const data=new Map(),storage={getItem:k=>data.get(k),setItem:(k,v)=>data.set(k,v)};
 collectionVoicePreference(storage,{id:31,share_uuid:'old'},'guest',true).write('voice');
 assert.equal(collectionVoicePreference(storage,{id:31,share_uuid:'new'},'guest',true).read(),'');
 const otherBrowser={getItem:()=>null,setItem(){}};
 assert.equal(collectionVoicePreference(otherBrowser,{id:31,share_uuid:'old'},'guest',true).read(),'');
});
test('public UI allows only personal voice changes and never persists guest notes/results',()=>{
 const show=readFileSync(new URL('../Pages/Collections/Show.vue',import.meta.url),'utf8');
 const session=readFileSync(new URL('../Pages/Study/Session.vue',import.meta.url),'utf8');
 assert.match(show,/function saveVoice\(\)\{if\(persistVoice\(voiceDraft.value\)\)showVoice.value=false;/);
 assert.match(show,/v-if="owner" class="button secondary"/);
 assert.match(show,/v-if="showEdit && owner"/);
 assert.match(session,/<FlashcardNote v-if="!shared"/);
 assert.match(session,/if\(props.shared\|\|busy.value\|\|saveState.value==='saved'\)return/);
});

test('session refresh rereads saved identity and invalid voices disable rather than fallback',()=>{const source=readFileSync(new URL('../Pages/Study/Session.vue',import.meta.url),'utf8');assert.match(source,/function refreshVoices\(\)\{[\s\S]*const rememberedVoice=voiceMemory.read\(\)/);assert.match(source,/if\(!selected\)\{voiceURI.value='';speechEnabled.value=false;speech.cancel\(\)/);});
