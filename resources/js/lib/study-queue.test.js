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

test('v4 correct first answer returns later behind other words and needs two correct, maximum three attempts',()=>{
 let queue=createStudyQueue([{id:1,type:'choice',retry_type:'written'},{id:2,type:'choice',retry_type:'written'}]);
 queue=appendStudyRepeat(queue,queue[0],true,false,4);assert.deepEqual(queue.map(q=>q.id),[1,2,1]);assert.equal(queue[2].type,'written');assert.equal(queue[2].correct_count,1);
 assert.equal(appendStudyRepeat(queue,queue[2],true,false,4),queue);
 for(const answers of [[false,false,false],[true,false,false],[false,true,false],[false,false,true],[false,true,true],[true,false,true]]){
 let single=createStudyQueue([{id:1,type:'choice',retry_type:'choice',prior_stage:'reinforced',prior_qualified:true}]);for(let i=0;i<answers.length;i++)single=appendStudyRepeat(single,single[i],answers[i],!answers[0],4);assert.equal(single.length,3);assert.ok(single.every(q=>q.type==='choice'));
 }
});
test('v4 preserves direction, note, prompt and selected retry type while legacy/Test remain unchanged',()=>{
 const initial={id:1,type:'choice',retry_type:'written',prompt:'مرحبا',expected:'hello',text_direction:'rtl',options:['hello','world'],option_directions:['ltr','ltr'],note:'private'};const queue=createStudyQueue([initial]);const next=appendStudyRepeat(queue,queue[0],true,false,4)[1];for(const key of ['prompt','expected','text_direction','options','option_directions','note'])assert.deepEqual(next[key],initial[key]);assert.equal(appendStudyRepeat(queue,queue[0],true,false,3),queue);assert.equal(appendStudyRepeat(queue,queue[0],true,false,2),queue);
});
test('guest completed stages require two correct then a later qualified mistake-free round',async()=>{
 const {completedGuestMastery}=await import('./study-queue.js');
 const rows=answers=>answers.map((correct,i)=>({id:1,attempt_no:i+1,correct,prior_stage:'new',prior_qualified:false}));
 assert.deepEqual(completedGuestMastery(rows([true,true])),[{word_id:1,stage:'learning',qualified:true,applied:true}]);
 assert.equal(completedGuestMastery(rows([true,true]).map(a=>({...a,prior_qualified:true})))[0].stage,'reinforced');
 const reset=completedGuestMastery(rows([false,true,false]).map(a=>({...a,prior_qualified:true})))[0];assert.equal(reset.qualified,false);
 assert.equal(completedGuestMastery(rows([true,true]).map(a=>({...a,prior_stage:reset.stage,prior_qualified:reset.qualified})))[0].stage,'learning');
 assert.equal(completedGuestMastery(rows([false,true,true]).map(a=>({...a,prior_qualified:true})))[0].stage,'learning');
});
test('guest review quota uses oldest reinforced words and completion count without mutation',async()=>{
 const {selectGuestStudyQuestions}=await import('./study-queue.js');const questions=Array.from({length:10},(_,i)=>({id:i+1}));const stages=new Map([[8,{stage:'reinforced',last_review:7}],[9,{stage:'reinforced',last_review:1}],[10,{stage:'reinforced',last_review:3}]]);
 assert.deepEqual(selectGuestStudyQuestions(questions,stages,5).map(q=>q.id),[1,2,3,4,9]);
 assert.deepEqual(selectGuestStudyQuestions(questions,stages,1,3).map(q=>q.id),[1]);assert.deepEqual(selectGuestStudyQuestions(questions,stages,1,4).map(q=>q.id),[9]);
 assert.deepEqual(selectGuestStudyQuestions(questions,stages,8).map(q=>q.id),[1,2,3,4,5,6,9,10]);assert.equal(selectGuestStudyQuestions(questions,stages,10).length,10);assert.deepEqual(selectGuestStudyQuestions([],stages,2),[]);assert.equal(stages.get(9).last_review,1);
});

test('guest v4 choice-only honors selection without changing legacy single-option fallback',async()=>{const {readFile}=await import('node:fs/promises');const source=await readFile(new URL('../Pages/Study/Session.vue',import.meta.url),'utf8');assert.match(source,/if\(props.shared&&props.mode==='study'\)questions.value=questions.value.map/);assert.match(source,/type:answerTypes.value.length===1&&answerTypes.value\[0\]==='choice'\?'choice':question.type/);assert.match(source,/Only one answer is available/);});

test('guest selection prioritizes unseen or unqualified before qualified Learning stably',async()=>{const {selectGuestStudyQuestions}=await import('./study-queue.js');const stages=new Map([[1,{stage:'learning',qualified:true}],[3,{stage:'learning',qualified:false}]]);assert.deepEqual(selectGuestStudyQuestions([{id:1},{id:2},{id:3},{id:4}],stages,3).map(q=>q.id),[2,3,4]);});
