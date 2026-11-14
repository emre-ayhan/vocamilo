import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
test('CSV preview shows update actions and warnings, and blocks empty batches',()=>{
 const s=readFileSync(new URL('../Pages/Import/Index.vue',import.meta.url),'utf8');
 assert.match(s,/preview.warnings\?\.length/);
 assert.match(s,/warning.term/);
 assert.match(s,/row.action==='update'/);
 assert.match(s,/save.processing\|\|!preview.total/);
 assert.doesNotMatch(s,/Exact duplicates in your word pool are skipped, not overwritten/);
});
