import test from 'node:test';import assert from 'node:assert/strict';import {readFileSync} from 'node:fs';
for(const name of ['Collections/Progress','Admin/Users','Admin/Collections'])test(`${name} searches explicit trimmed string from first page and can clear stale filters`,()=>{
 const s=readFileSync(new URL(`../Pages/${name}.vue`,import.meta.url),'utf8');
 assert.match(s,/function search\(\)/);assert.match(s,/String\(query.value\?\?''\).trim\(\)/);
 assert.match(s,/@submit.prevent="search"/);assert.doesNotMatch(s,/@submit.prevent="router.get/);
 assert.match(s,/router.get\([^,]+,\{q\},/);assert.match(s,/watch\(\(\)=>props.filters\?\.q/);
 assert.match(s,/function clearSearch\(\)\{query.value='';search\(\);\}/);
 assert.match(s,/onFinish:\(\)=>\{searching.value=false;/);assert.match(s,/:disabled="searching"/);
 assert.match(s,/@media\(max-width:600px\)\{\.(progress|admin)-search \.button\{width:100%\}/);
});
