import test from 'node:test';import assert from 'node:assert/strict';import {readFileSync} from 'node:fs';
const page=name=>readFileSync(new URL(`../Pages/${name}.vue`,import.meta.url),'utf8');
test('word pool UI scopes creation/export/import and removes membership instead of deleting shared word',()=>{
 const s=page('Words/Index');assert.match(s,/pool_id:props.selectedPool\?\.id/);assert.match(s,/\/words\/export\?pool_id=/);assert.match(s,/\/imports\?pool_id=/);assert.match(s,/router.delete\(`\/word-pools\/\$\{props.selectedPool.id\}\/words\/\$\{word.id\}`/);assert.doesNotMatch(s,/router.delete\(`\/words\/\$\{word.id\}`/);assert.match(s,/Editing this word updates every pool and collection/);assert.match(s,/available-words\?search=/);assert.match(s,/reuse.word_ids.length>=500/);
});
test('CSV uses a chosen pool without a third column and prevents save of preview for another pool',()=>{
 const s=page('Import/Index');assert.match(s,/pool_id:props.selectedPoolId/);assert.match(s,/term_column:0,definition_column:1/);assert.match(s,/preview&&Number\(form.pool_id\)===Number\(preview.pool_id\)/);assert.match(s,/Target word pool/);
});
test('collection pool selection guards stale results and only changes applied scope after response',()=>{
 const s=page('Collections/Show');assert.match(s,/const poolsDirty=computed/);assert.match(s,/onSuccess:\(\)=>\{add.pool_ids=selected;add.defaults\('pool_ids',selected\)/);assert.match(s,/add.processing\|\|applyingPools\|\|poolsDirty/);assert.match(s,/voice_language:props.collection.voice_language/);assert.match(s,/preferred-language="edit.voice_language"/);assert.match(s,/leave.delete\(`\/collections\/\$\{props.collection.id\}\/membership`\)/);assert.match(s,/If you join again and consent, the owner may see your past results/);
});

test('CSV commit binds the visible preview nonce and pool, not another tab or a changed target',()=>{const s=page('Import/Index');assert.match(s,/@submit.prevent="commitImport"/);assert.match(s,/pool_id:props.preview.pool_id,preview_id:props.preview.preview_id/);assert.match(s,/!props.preview\?\.preview_id/);assert.match(s,/Number\(form.pool_id\)!==Number\(props.preview.pool_id\)/);assert.doesNotMatch(s,/@submit.prevent="save.post/);});
