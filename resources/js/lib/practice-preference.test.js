import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {normalizePracticeSettings,practicePreference} from './practice-preference.js';
const storage=()=>{const map=new Map();return {getItem:key=>map.get(key),setItem:(key,value)=>map.set(key,value)}};
const choices={type:'written',direction:'definition',types:['written','choice'],directions:['term','definition'],order:'ordered',count:30};
test('remembers each mode, collection, account and guest scope independently',()=>{
 const local=storage(),c={id:3,share_uuid:'secret'};
 const pref=practicePreference(local,c,1,'study');pref.write(choices,100);
 assert.deepEqual(pref.read({},100),choices);
 for(const other of [practicePreference(local,c,1,'test'),practicePreference(local,{id:4},1,'study'),practicePreference(local,c,2,'study'),practicePreference(local,c,'guest','study'),practicePreference(local,c,1,'study',true),practicePreference(storage(),c,1,'study')])assert.equal(other.read({},100).count,10);
 practicePreference(local,c,'guest','study',true).write(choices,100);
 assert.equal(practicePreference(local,c,1,'study',true).read({},100).count,10);
 assert.equal(practicePreference(local,{share_uuid:'rotated'},'guest','study',true).read({},100).count,10);
});
test('invalid values safely fall back, count clamps to available words, no unsafe extra settings',()=>{
 assert.deepEqual(normalizePracticeSettings({type:'bad',direction:null,types:[],directions:['bad'],order:'bad',count:-1,speechEnabled:true,voiceURI:'secret'},8),{type:'choice',direction:'term',types:['choice'],directions:['term'],order:'random',count:8});
 assert.equal(normalizePracticeSettings(choices,5).count,5);
 assert.equal(normalizePracticeSettings(choices,0).count,0);
 assert.equal(normalizePracticeSettings({count:9999},9999).count,2000);
 assert.equal(normalizePracticeSettings({count:1.5},100).count,10);
 assert.deepEqual(normalizePracticeSettings({types:['written','written','unknown']},10).types,['written']);
});
test('corrupt, unsupported and inaccessible storage do not break practice',()=>{
 for(const getItem of [()=>'{',()=>'{"version":9}',()=>{throw Error('denied')}])assert.equal(practicePreference({getItem},{id:1},1,'study').read({},20).count,10);
 assert.equal(practicePreference(undefined,{id:1},1,'study').write(choices,20),false);
 assert.equal(practicePreference({setItem(){throw Error()}},{id:1},1,'test').write(choices,20),false);
 const local=storage();assert.equal(practicePreference(local,{id:1},1,'flashcards').write(choices,100),false);
});
test('session remembers setup changes but never enables speech from saved settings',()=>{
 const source=readFileSync(new URL('../Pages/Study/Session.vue',import.meta.url),'utf8');
 assert.match(source,/watch\(practiceSettings,settings=>setupMemory.write/);
 assert.match(source,/speechEnabled=ref\(false\)/);
 assert.match(source,/page.props.auth\?\.user\?\.id\?\?'guest',props.mode,props.shared/);
});

import {studyNotesPreference} from './practice-preference.js';
test('notes toggle defaults off and persists only authenticated private Study scope without note content',()=>{
 const local=storage(),pool={id:3,share_uuid:'share'};const pref=studyNotesPreference(local,pool,1,'study');
 assert.equal(pref.read(),false);assert.equal(pref.write(true),true);assert.equal(pref.read(),true);
 for(const other of [studyNotesPreference(local,pool,2,'study'),studyNotesPreference(local,{id:4},1,'study'),studyNotesPreference(local,pool,1,'test'),studyNotesPreference(local,pool,1,'flashcards'),studyNotesPreference(local,pool,'guest','study'),studyNotesPreference(local,pool,1,'study',true)])assert.equal(other.read(),false);
 assert.equal(studyNotesPreference(local,pool,1,'test').write(true),false);
 assert.equal(studyNotesPreference(undefined,pool,1,'study').write(true),false);
 assert.equal(studyNotesPreference({getItem(){throw Error();}},pool,1,'study').read(),false);
 pref.write(false);assert.equal(pref.read(),false);
});
test('private Study notes are opt-in and separate from session settings payload',()=>{
 const source=readFileSync(new URL('../Pages/Study/Session.vue',import.meta.url),'utf8');
 assert.match(source,/props.mode==='study'&&!props.shared&&!!page.props.auth\?\.user\?\.id/);
 assert.match(source,/v-if="canShowNotes&&showNotes&&current.note"/);
 assert.match(source,/v-if="canShowNotes" class="speech-toggle study-notes-toggle"/);
 assert.doesNotMatch(source,/practiceSettings=computed[^\n]*showNotes/);
});
