import test from 'node:test';
import assert from 'node:assert/strict';
import {createStudyQueue,appendStudyRepeat,studyAttemptPayload,prioritizeGuestWords} from './study-queue.js';
test('first correct requires no repeat; first wrong stops after a correct first repeat',()=>{
 let queue=createStudyQueue([{id:1},{id:2}]);
 assert.equal(appendStudyRepeat(queue,queue[0],true,false),queue);
 queue=appendStudyRepeat(queue,queue[0],false,true);
 assert.deepEqual(queue.map(x=>x.id),[1,2,1]);
 queue=appendStudyRepeat(queue,queue[2],true,true);
 assert.equal(queue.length,3);
 assert.equal(new Set(queue.map(x=>x.occurrence_id)).size,3);
});
test('wrong answers stop after two extra attempts even if still wrong',()=>{
 let queue=createStudyQueue([{id:3}]);
 for(let i=0;i<3;i++)queue=appendStudyRepeat(queue,queue[i],false,true);
 assert.equal(queue.length,3);
});
test('legacy version two still requires a second repeat after a correct first repeat',()=>{
 let queue=createStudyQueue([{id:3}]);
 queue=appendStudyRepeat(queue,queue[0],false,true,2);
 queue=appendStudyRepeat(queue,queue[1],true,true,2);
 assert.equal(queue.length,3);
});
test('repeated questions are spaced behind pending other words',()=>{
 let queue=createStudyQueue([{id:1},{id:2}]);
 queue=appendStudyRepeat(queue,queue[0],false,true);
 queue=appendStudyRepeat(queue,queue[1],false,true);
 queue=appendStudyRepeat(queue,queue[2],true,true);
 assert.deepEqual(queue.map(x=>x.id),[1,2,1,2]);
});
test('attempt API sends per-word attempt numbers, never client grading or occurrence identifiers',()=>{
 assert.deepEqual(studyAttemptPayload([{id:2,attempt_no:3,occurrence_id:'2:3',answer:'yes',correct:true,self_assessed:true}]),[{word_id:2,attempt_no:3,answer:'yes',self_assessed:true}]);
});
test('guest priority is stable and does not mutate supplied words',()=>{
 const words=[{id:1},{id:2},{id:3}];assert.deepEqual(prioritizeGuestWords(words,new Set([1])).map(w=>w.id),[2,3,1]);assert.deepEqual(words.map(w=>w.id),[1,2,3]);
});
