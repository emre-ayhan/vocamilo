import test from 'node:test';import assert from 'node:assert/strict';
import {normalizeAnswer,isCorrect,shuffle,createQuestions,score,shouldAutoCheck} from './study.js';
const words=[{id:1,term:'curious',definition:'Eager to learn'},{id:2,term:'brave',definition:'Ready to face fear'},{id:3,term:'bold',definition:'Ready to face fear'}];
test('answers normalize case, unicode compatibility, and whitespace without discarding accents',()=>{assert.equal(normalizeAnswer('  Ａ  WORD\n'),'a word');assert.ok(isCorrect(' EAGER  to Learn ','Eager to learn'));assert.ok(!isCorrect('cafe','café'));});
test('shuffle does not mutate source',()=>{const source=[1,2,3];assert.deepEqual(shuffle(source,()=>0),[2,3,1]);assert.deepEqual(source,[1,2,3]);});
test('ordered questions respect count and direction',()=>{const q=createQuestions(words,{direction:'definition',order:'ordered',count:2,type:'choice'},()=>0);assert.equal(q.length,2);assert.equal(q[0].prompt,'curious');assert.equal(q[0].expected,'Eager to learn');assert.equal(new Set(q[1].options).size,q[1].options.length);assert.ok(q[1].options.includes(q[1].expected));});
test('a single distinct answer falls back to written',()=>{const q=createQuestions(words.slice(1),{direction:'definition',type:'choice'});assert.equal(q[0].type,'written');});
test('questions and score work for empty sets',()=>{assert.deepEqual(createQuestions([]),[]);assert.deepEqual(score([]),{correct:0,total:0,percent:0});assert.equal(score([{correct:true},{correct:false}]).percent,50);});

test('Study and Test choices check internally; written answers require submission',()=>{
    assert.equal(shouldAutoCheck('study','choice'),true);
    assert.equal(shouldAutoCheck('study','written'),false);
    assert.equal(shouldAutoCheck('test','choice'),true);
    assert.equal(shouldAutoCheck('test','written'),false);
    assert.equal(shouldAutoCheck('flashcards','choice'),false);
});

test('Study choices use accessible native radios with circle feedback instead of letters',async()=>{
    const {readFile}=await import('node:fs/promises');
    const source=await readFile(new URL('../Pages/Study/Session.vue',import.meta.url),'utf8');
    assert.match(source,/type="radio" name="answer-choice" :checked="answer===option"/);
    assert.match(source,/@change="selectOption\(option\)" :disabled="checked" required/);
    assert.match(source,/<svg v-if="mode!=='flashcards'" class="answer-choice-icon"/);
    assert.match(source,/<span v-else class="option-letter">/);
    assert.match(source,/v-if="mode==='study'&&checked&&answer===option&&correct"/);
    assert.match(source,/v-else-if="mode==='study'&&checked&&answer===option&&!correct"/);
    assert.match(source,/input:focus-visible\+\.answer-choice-icon/);
    assert.match(source,/class="answer-choice-icon"[^>]*aria-hidden="true"/);
    assert.match(source,/role="status"><strong>{{correct\?t/);
});
test('mixed Study covers all selected direction and answer combinations without duplicate words',()=>{
 const pool=Array.from({length:8},(_,i)=>({id:i,term:'term'+i,definition:'meaning'+i}));
 const questions=createQuestions(pool,{directions:['term','definition'],types:['choice','written'],order:'ordered',count:8},()=>.3);
 assert.equal(new Set(questions.map(q=>q.id)).size,8);
 for(const subset of [questions.slice(0,4),questions.slice(4)])assert.equal(new Set(subset.map(q=>q.answerKey+':'+q.type)).size,4);
 assert.deepEqual(questions.map(q=>q.id),pool.map(w=>w.id));
});
test('single selected settings and choice fallback still work',()=>{
 const questions=createQuestions(words,{directions:['definition'],types:['written'],count:3});
 assert.ok(questions.every(q=>q.answerKey==='definition'&&q.type==='written'));
 assert.equal(createQuestions([words[0]],{directions:['term'],types:['choice']})[0].type,'written');
});
test('Study switches retain at least one choice; question replay is a keyboard-native button',async()=>{
 const {readFile}=await import('node:fs/promises');
 const source=await readFile(new URL('../Pages/Study/Session.vue',import.meta.url),'utf8');
 assert.match(source,/if\(values.length>1\)values.splice/);
 assert.match(source,/directions.length===1&&directions.includes/);
 assert.match(source,/answerTypes.length===1&&answerTypes.includes/);
 assert.doesNotMatch(source,/Read question again/);
 assert.match(source,/v-if="mode!=='flashcards'&&speechEnabled&&voiceURI" type="button" class="spoken-question"/);
 assert.match(source,/@click="speakQuestion"><VocabularyText :text="current.prompt"/);
});
