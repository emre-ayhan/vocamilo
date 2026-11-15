import test from 'node:test';import assert from 'node:assert/strict';import {readFileSync} from 'node:fs';
import {safeDirection,suggestedDirection,languageDirection} from './text-direction.js';import {createQuestions} from './study.js';
test('RTL language suggestions respect explicit manual choices and Latin script',()=>{for(const code of ['ar','he-IL','fa','ur-PK'])assert.equal(suggestedDirection(code),'rtl');assert.equal(suggestedDirection('ar-Latn'),'ltr');assert.equal(suggestedDirection('pt-PT'),'ltr');assert.equal(languageDirection('ar','ltr',true),'ltr');assert.equal(languageDirection('en','rtl',true),'rtl');assert.equal(safeDirection('invalid'),'ltr');});
test('guest question and option directions remain aligned across mixed pools and retain text',()=>{
 const words=[{id:1,term:'مرحبا',definition:'hello',text_direction:'rtl'},{id:2,term:'world',definition:'العالم',text_direction:'ltr'}];
 const questions=createQuestions(words,{order:'ordered',direction:'term',type:'choice',count:2},()=>.2);
 assert.equal(questions[0].text_direction,'rtl');for(const question of questions){assert.equal(question.options.length,question.option_directions.length);question.options.forEach((text,i)=>assert.equal(question.option_directions[i],text==='مرحبا'?'rtl':'ltr'));}
 assert.equal(words[0].term,'مرحبا');
});
test('vocabulary is isolated per content item; page controls are not globally flipped',()=>{
 const component=readFileSync(new URL('../Components/VocabularyText.vue',import.meta.url),'utf8');assert.match(component,/:dir="safeDirection\(direction\)"/);assert.match(component,/<bdi dir="auto">/);assert.match(component,/unicode-bidi:isolate/);assert.match(component,/overflow-wrap:anywhere/);
 for(const path of ['Words/Index','Collections/Show','Study/Session']){const source=readFileSync(new URL(`../Pages/${path}.vue`,import.meta.url),'utf8');assert.match(source,/VocabularyText/);assert.doesNotMatch(source,/<AppLayout[^>]*dir=/);}
 const session=readFileSync(new URL('../Pages/Study/Session.vue',import.meta.url),'utf8');assert.match(session,/option_directions\?\.\[i\]/);assert.match(session,/<LetterBoxes.*?:direction="current.text_direction"/);const boxes=readFileSync(new URL('../Components/LetterBoxes.vue',import.meta.url),'utf8');assert.match(boxes,/dir="auto"/);
});
test('pool create suggests language direction but edit preserves existing direction and exposes manual override',()=>{
 for(const path of ['Pools','Index']){const source=readFileSync(new URL(`../Pages/Words/${path}.vue`,import.meta.url),'utf8');assert.match(source,/text_direction:'ltr'/);assert.match(source,/@change="directionChosen=true"/);assert.match(source,/languageDirection\(language,poolForm.text_direction,directionChosen.value\)/);}
 const edit=readFileSync(new URL('../Pages/Words/Index.vue',import.meta.url),'utf8');assert.match(edit,/directionChosen.value=true;poolForm.text_direction=props.selectedPool.text_direction/);
});
