import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {hasArabicScript} from './vocabulary-typography.js';
test('Arabic typography follows actual script including vocalized Arabic, not direction or Latin/Hebrew text',()=>{
 for(const text of ['عربي','بِسْمِ','Hello ع','ﻻ'])assert.equal(hasArabicScript(text),true);
 for(const text of ['English','עברית','İstanbul','123',null])assert.equal(hasArabicScript(text),false);
});
test('vocabulary and both letter inputs increase Arabic desktop size without altering graphemes or cell width',()=>{
 for(const file of ['VocabularyText','LetterBoxes','ArrangeLetters']){
 const source=readFileSync(new URL(`../Components/${file}.vue`,import.meta.url),'utf8');
 assert.match(source,/hasArabicScript\((text|expected)\)/);assert.match(source,/@media\(min-width:900px\)/);
 assert.match(source,/line-height:1\.[79]/);
 if(file!=='VocabularyText'){assert.match(source,/font-size:1\.85rem/);assert.match(source,/flex:0 0 2\.75rem/);}
 }
});
