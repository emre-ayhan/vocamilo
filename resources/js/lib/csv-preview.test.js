import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
test('CSV preview shows update actions and warnings, and blocks empty batches',()=>{
 const s=readFileSync(new URL('../Pages/Import/Index.vue',import.meta.url),'utf8');
 assert.match(s,/preview.warnings\?\.length/);
 assert.match(s,/warning.term/);
 assert.match(s,/row.action==='update'/);
 assert.match(s,/save.processing\|\|form.processing\|\|previewDirty\|\|!preview.total/);
 assert.doesNotMatch(s,/Exact duplicates in your word pool are skipped, not overwritten/);
});
function mountImport(){
 const source=readFileSync(new URL('../Pages/Import/Index.vue',import.meta.url),'utf8'),props={selectedPoolId:2,preview:{preview_id:'nonce-a',pool_id:2,total:1}};let changed;const requests=[];
 function useForm(data){return {...data,processing:false,transform(fn){this.mapper=fn;return this;},post(url,options){requests.push({url,data:this.mapper?this.mapper(this):this,options});}};}
 const script=source.split('<script setup>')[1].split('</script>')[0].replace(/^import .*;\n/gm,'');
 const vm=new Function('defineProps','useI18n','useForm','ref','watch',`${script};return {form,save,upload,commitImport,previewDirty};`)(()=>props,()=>({t:s=>s}),useForm,value=>({value}),(getter,callback)=>{changed=callback;});return {vm,props,requests,changed,source};
}
test('optional third note defaults correctly and ignore is explicit; preview shows only imported note values',()=>{const {vm,source}=mountImport();assert.equal(vm.form.note_column,2);assert.match(source,/<option :value="null">/);assert.match(source,/Keep existing note/);assert.match(source,/Set private note/);assert.match(source,/term,definition,note/);assert.match(source,/row.note/);assert.doesNotMatch(source,/existing_note|owner.note/);});
test('mapping changes invalidate commit until fresh preview; edits during upload stay invalid',()=>{const {vm,requests,changed}=mountImport();vm.form.note_column=null;changed();vm.commitImport();assert.equal(requests.length,0);assert.equal(vm.previewDirty.value,true);vm.upload();changed();requests[0].options.onSuccess();assert.equal(vm.previewDirty.value,true);vm.upload();requests[1].options.onSuccess();assert.equal(vm.previewDirty.value,false);vm.commitImport();assert.deepEqual(requests[2].data,{collection_id:'',pool_id:2,preview_id:'nonce-a'});});
test('upload and commit cannot overlap and source controls include every mapped input',()=>{const {vm,requests,source}=mountImport();vm.form.processing=true;vm.commitImport();vm.upload();assert.equal(requests.length,0);vm.form.processing=false;vm.save.processing=true;vm.upload();assert.equal(requests.length,0);assert.match(source,/form.file,form.pool_id,form.term_column,form.definition_column,form.note_column,form.has_header/);});

test('starting replacement preview invalidates old commit even when replacement fails',()=>{const {vm,requests}=mountImport();assert.equal(vm.previewDirty.value,false);vm.upload();assert.equal(vm.previewDirty.value,true);vm.commitImport();assert.equal(requests.length,1);vm.upload();requests[1].options.onSuccess();assert.equal(vm.previewDirty.value,false);vm.commitImport();assert.equal(requests[2].url,'/imports/store');});
