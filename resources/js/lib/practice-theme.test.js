import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
const session=readFileSync(new URL('../Pages/Study/Session.vue',import.meta.url),'utf8');
const boxes=readFileSync(new URL('../Components/LetterBoxes.vue',import.meta.url),'utf8');
test('Study collection blue and Test collection lilac palettes are mode scoped',()=>{assert.match(session,/'practice-themed':mode==='study'\|\|mode==='test'/);assert.match(session,/\.practice-themed\{--practice-soft:#edf3ff/);assert.match(session,/\.practice-test\{--practice-soft:#f3edff/);assert.match(session,/--practice-accent:#65408f;--practice-end:#8a4b97/);});
test('theme keeps explicit success/error feedback colors and accessible focus',()=>{assert.match(session,/\.answer-option-correct\{border-color:#32845f;background:#e8f4e8/);assert.match(session,/\.answer-option-incorrect\{border-color:#b28948;background:#fff4df/);assert.match(session,/\.answer-option.selected:not\(\.answer-option-correct\):not\(\.answer-option-incorrect\)/);assert.match(boxes,/outline:2px solid var\(--practice-focus,#247d70\)/);assert.match(boxes,/min-height:44px/);});

test('large setup and question panels remain neutral for both modes',()=>{assert.match(session,/\.practice-themed \.study-settings,\.practice-themed \.question-panel\{background:#fff;border-color:var\(--line\)\}/);assert.doesNotMatch(session,/linear-gradient\(155deg,var\(--practice-soft\)/);});
test('Study accents use its collection card blue gradient',()=>{const css=readFileSync(new URL('../../css/app.css',import.meta.url),'utf8');assert.match(css,/--mode-start:#24549b;--mode-end:#3474b6/);assert.match(session,/--practice-accent:#24549b;--practice-end:#3474b6;--practice-focus:#24549b/);});
