import test from 'node:test';import assert from 'node:assert/strict';import {readFileSync} from 'node:fs';
const page=name=>readFileSync(new URL(`../Pages/${name}.vue`,import.meta.url),'utf8');
test('word pool UI scopes creation/export/import and removes membership instead of deleting shared word',()=>{
 const s=page('Words/Index');assert.match(s,/pool_id:props.selectedPool\?\.id/);assert.match(s,/\/words\/export\?pool_id=/);assert.match(s,/\/imports\?pool_id=/);assert.match(s,/router.delete\(`\/word-pools\/\$\{props.selectedPool.id\}\/words\/\$\{word.id\}`/);assert.doesNotMatch(s,/router.delete\(`\/words\/\$\{word.id\}`/);assert.match(s,/Editing this word updates every pool and collection/);assert.match(s,/available-words\?search=/);assert.match(s,/reuse.word_ids.length>=500/);
});
test('CSV uses a chosen pool without a third column and prevents save of preview for another pool',()=>{
 const s=page('Import/Index');assert.match(s,/pool_id:props.preview\?\.new_pool\?'new':props.selectedPoolId/);assert.match(s,/term_column:0,definition_column:1/);assert.match(s,/preview&&matchesPreview\(\)/);assert.match(s,/Target word pool/);
});
test('collection pool selection guards stale results and only changes applied scope after response',()=>{
 const s=page('Collections/Show');assert.match(s,/const poolsDirty=computed/);assert.match(s,/onSuccess:\(\)=>\{add.pool_ids=selected;add.defaults\('pool_ids',selected\)/);assert.match(s,/add.processing\|\|applyingPools\|\|poolsDirty/);assert.match(s,/voice_language:props.collection.voice_language/);assert.match(s,/preferred-language="edit.voice_language"/);assert.match(s,/leave.delete\(`\/collections\/\$\{props.collection.id\}\/membership`\)/);assert.match(s,/If you join again and consent, the owner may see your past results/);
});

test('CSV commit binds the visible preview nonce and pool, not another tab or a changed target',()=>{const s=page('Import/Index');assert.match(s,/@submit.prevent="commitImport"/);assert.match(s,/pool_id:props.preview.pool_id,preview_id:props.preview.preview_id/);assert.match(s,/!props.preview\?\.preview_id/);assert.match(s,/!matchesPreview\(\)/);assert.doesNotMatch(s,/@submit.prevent="save.post/);});
test('overview is pool-only cards and create form; detail has canonical scoped search and back link',()=>{
 const overview=page('Words/Pools'),detail=page('Words/Index');
 assert.match(overview,/defineProps\(\{pools:/);assert.match(overview,/v-for="\(pool,index\) in pools"/);assert.match(overview,/href="`\/word-pools\/\$\{pool.id\}`"/);assert.match(overview,/pool.words_count/);assert.match(overview,/poolForm.post\('\/word-pools'/);assert.doesNotMatch(overview,/word\.term|word\.definition|words\.data/);
 assert.match(detail,/router.get\(`\/word-pools\/\$\{props.selectedPool.id\}`,\{search:search.value.trim\(\)\}/);assert.match(detail,/href="\/words"/);assert.doesNotMatch(detail,/choosePool|poolForm.post\('\/word-pools'/);
 const layout=readFileSync(new URL('../Layouts/AppLayout.vue',import.meta.url),'utf8');assert.match(layout,/active\('\/words'\)\|\|active\('\/word-pools'\)/);
});

test('pool export is retained once, collection export is owner-only and language toolbar has space',()=>{
 const detail=readFileSync(new URL('../Pages/Words/Index.vue',import.meta.url),'utf8');
 const collection=readFileSync(new URL('../Pages/Collections/Show.vue',import.meta.url),'utf8');
 assert.equal((detail.match(/\/words\/export\?pool_id=/g)||[]).length,1);
 assert.match(collection,/<a v-if="owner" :href="`\/collections\/\$\{collection.id\}\/export`"/);
 assert.match(detail,/\.pool-toolbar\{[^}]*gap:20px/);
 assert.match(detail,/min-height:44px/);
 assert.match(detail,/@media\(max-width:600px\)\{\.pool-toolbar \.actions,\.form-actions\{flex-direction:column;align-items:stretch/);
});
