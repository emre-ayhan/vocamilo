import test from 'node:test';import assert from 'node:assert/strict';
import {normalizeAnswer,isCorrect,shuffle,createQuestions,score,shouldAutoCheck} from './study.js';
const words=[{id:1,term:'curious',definition:'Eager to learn'},{id:2,term:'brave',definition:'Ready to face fear'},{id:3,term:'bold',definition:'Ready to face fear'}];
test('answers normalize case, unicode compatibility, and whitespace without discarding accents',()=>{assert.equal(normalizeAnswer('  Ａ  WORD\n'),'a word');assert.ok(isCorrect(' EAGER  to Learn ','Eager to learn'));assert.ok(!isCorrect('cafe','café'));});
test('shuffle does not mutate source',()=>{const source=[1,2,3];assert.deepEqual(shuffle(source,()=>0),[2,3,1]);assert.deepEqual(source,[1,2,3]);});
test('ordered questions respect count and direction',()=>{const q=createQuestions(words,{direction:'definition',order:'ordered',count:2,type:'choice'},()=>0);assert.equal(q.length,2);assert.equal(q[0].prompt,'curious');assert.equal(q[0].expected,'Eager to learn');assert.equal(new Set(q[1].options).size,q[1].options.length);assert.ok(q[1].options.includes(q[1].expected));});
test('a single distinct answer falls back to written',()=>{const q=createQuestions(words.slice(1),{direction:'definition',type:'choice'});assert.equal(q[0].type,'written');});
test('questions and score work for empty sets',()=>{assert.deepEqual(createQuestions([]),[]);assert.deepEqual(score([]),{correct:0,total:0,percent:0});assert.equal(score([{correct:true},{correct:false}]).percent,50);});

test('only Study multiple-choice selection auto-checks',()=>{
    assert.equal(shouldAutoCheck('study','choice'),true);
    assert.equal(shouldAutoCheck('study','written'),false);
    assert.equal(shouldAutoCheck('test','choice'),false);
    assert.equal(shouldAutoCheck('test','written'),false);
    assert.equal(shouldAutoCheck('flashcards','choice'),false);
});
