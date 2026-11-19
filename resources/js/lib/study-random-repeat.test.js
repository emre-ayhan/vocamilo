import test from 'node:test';
import assert from 'node:assert/strict';
import {createStudyQueue, appendStudyRepeat, prepareStudyTurn} from './study-queue.js';
const question = id => ({id,type:'choice',retry_type:'choice',expected:'hello',options:['hello','مرحبا','other'],option_directions:['ltr','rtl','ltr']});
test('first-round order stays intact and choices reshuffle without losing RTL associations',()=>{
 const original=createStudyQueue([question(1),question(2),question(3)]);
 const queue=prepareStudyTurn(original,0,()=>0.99);
 assert.deepEqual(queue.map(q=>q.id),[1,2,3]);
 assert.notDeepEqual(queue[0].options,original[0].options);
 assert.deepEqual(queue[0].options.map((text,i)=>[text,queue[0].option_directions[i]]).sort(),original[0].options.map((text,i)=>[text,original[0].option_directions[i]]).sort());
 assert.deepEqual(original[0].options,['hello','مرحبا','other']);
});
test('both correct and incorrect repeats reshuffle without moving completed turns or immediately repeating a word',()=>{
 let queue=createStudyQueue([question(1),question(2),question(3)]);
 for(let i=0;i<3;i++)queue=appendStudyRepeat(queue,queue[i],i!==1,i===1,8,'study');
 const mixed=prepareStudyTurn(queue,3,()=>0.99);
 assert.deepEqual(mixed.slice(0,3),queue.slice(0,3));
 assert.notDeepEqual(mixed.slice(3).map(q=>q.id),[1,2,3]);
 assert.notEqual(mixed[3].id,3);
 assert.deepEqual(mixed.slice(3).map(q=>q.occurrence_id).sort(),['1:2','2:2','3:2']);
 assert.notDeepEqual(mixed[3].options,queue.find(q=>q.id===mixed[3].id).options);
});
test('single-word sessions still repeat, stop after two correct answers and retain first-attempt data',()=>{
 let queue=createStudyQueue([question(1)]);queue=prepareStudyTurn(queue,0,()=>0);
 const first=queue[0];queue=appendStudyRepeat(queue,first,true,false,8,'study');queue=prepareStudyTurn(queue,1,()=>0);
 assert.equal(queue.length,2);assert.equal(queue[0],first);assert.notDeepEqual(queue[1].options,first.options);
 assert.equal(appendStudyRepeat(queue,queue[1],true,false,8,'study'),queue);
 assert.equal(prepareStudyTurn(queue,2),queue);
});
