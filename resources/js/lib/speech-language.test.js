import test from 'node:test';
import assert from 'node:assert/strict';
import {voiceLanguageScore,suggestedLocalVoices} from './speech-language.js';
const voice=(name,lang,localService=true)=>({voiceURI:name,name,lang,localService});
test('voice suggestions rank exact dialect then same language without discarding other choices',()=>{
 const voices=[voice('English','en-US'),voice('Brazil','pt-BR'),voice('Portugal','pt-PT')];
 assert.deepEqual(suggestedLocalVoices(voices,'pt_PT').map(v=>v.name),['Portugal','Brazil','English']);
 assert.deepEqual(voices.map(v=>v.name),['English','Brazil','Portugal']);
 assert.equal(voiceLanguageScore(voices[0],'pt'),0);
});
test('no matching voice is honest and remote or ambiguous voices are not suggested',()=>{
 const local=voice('Local','en-US'),remote=voice('Remote','pt-PT',false),duplicate=voice('Duplicate','pt-PT');
 assert.deepEqual(suggestedLocalVoices([local,remote,duplicate,duplicate],'pt'),[local]);
 assert.equal(voiceLanguageScore(local,''),0);
 assert.equal(voiceLanguageScore(local,'fr'),0);
 assert.deepEqual(suggestedLocalVoices([],'pt'),[]);
});
