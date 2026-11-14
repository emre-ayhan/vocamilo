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
