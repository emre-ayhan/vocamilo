import test from 'node:test';
import assert from 'node:assert/strict';
import {swipeMark} from './flashcard-swipe.js';
import {readFileSync} from 'node:fs';
const start={id:1,x:100,y:100};
test('horizontal swipes mark revealed cards; scroll, short and mismatched gestures do not',()=>{
 assert.equal(swipeMark(start,{id:1,x:180,y:110},true),true);
 assert.equal(swipeMark(start,{id:1,x:20,y:90},true),false);
 for(const end of [{id:1,x:120,y:100},{id:1,x:180,y:200},{id:2,x:200,y:100}])assert.equal(swipeMark(start,end,true),null);
 assert.equal(swipeMark(start,{id:1,x:200,y:100},false),null);
 assert.equal(swipeMark(null,{id:1,x:200,y:100},true),null);
});
test('gesture supports cancellation and scroll while retaining flip and action buttons',()=>{
 const s=readFileSync(new URL('../Pages/Study/Session.vue',import.meta.url),'utf8');
 assert.match(s,/@pointercancel="cancelSwipe"/);
 assert.match(s,/pointerType!=='touch'/);
 assert.match(s,/touch-action:pan-y/);
 assert.match(s,/suppressFlipUntil=Date.now\(\)\+700/);
 assert.match(s,/@click="next\(false\)"/);
 assert.match(s,/@click="next\(true\)"/);
});
test('Test shows neither live correctness icons nor feedback and uses repeat payload',()=>{
 const s=readFileSync(new URL('../Pages/Study/Session.vue',import.meta.url),'utf8');
 assert.match(s,/v-if="checked&&mode==='study'" :class="\['answer-feedback'/);
 assert.match(s,/v-if="mode==='study'&&checked&&answer===option&&correct"/);
 assert.match(s,/answers:props.mode!=='flashcards'\?studyAttemptPayload/);
 assert.doesNotMatch(s,/if\(props.mode==='test'\)next\(\)/);
});

test('swipe motion follows touch intent with reduced-motion escape and timed exit',()=>{
 const s=readFileSync(new URL('../Pages/Study/Session.vue',import.meta.url),'utf8');
 assert.match(s,/@pointermove="moveSwipe"/);
 assert.match(s,/dragX.value=dx/);
 assert.match(s,/prefers-reduced-motion:reduce\)\{\.flashcard\{transform:none;transition:none/);
 assert.match(s,/clearTimeout\(swipeTimer\)/);
 assert.match(s,/Math.abs\(dy\)>20/);
 assert.match(s,/class="swipe-badge"/);
});
