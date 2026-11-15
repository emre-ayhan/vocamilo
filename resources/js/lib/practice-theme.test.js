import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
const session=readFileSync(new URL('../Pages/Study/Session.vue',import.meta.url),'utf8');
const boxes=readFileSync(new URL('../Components/LetterBoxes.vue',import.meta.url),'utf8');
test('Study green and Test collection lilac palettes are mode scoped',()=>{assert.match(session,/'practice-themed':mode==='study'\|\|mode==='test'/);assert.match(session,/\.practice-themed\{--practice-soft:#e9f7ef/);assert.match(session,/\.practice-test\{--practice-soft:#f3edff/);assert.match(session,/--practice-accent:#65408f;--practice-end:#8a4b97/);});
test('theme keeps explicit success/error feedback colors and accessible focus',()=>{assert.match(session,/\.answer-option-correct\{border-color:#32845f;background:#e8f4e8/);assert.match(session,/\.answer-option-incorrect\{border-color:#b28948;background:#fff4df/);assert.match(session,/\.answer-option.selected:not\(\.answer-option-correct\):not\(\.answer-option-incorrect\)/);assert.match(boxes,/outline:2px solid var\(--practice-focus,#247d70\)/);assert.match(boxes,/min-height:44px/);});
