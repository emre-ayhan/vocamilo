import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {csvPoolName} from './csv-pool-name.js';
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
 const vm=new Function('defineProps','useI18n','useForm','ref','watch','csvPoolName',`${script};return {form,save,upload,commitImport,previewDirty,newPoolName,readPoolName,chooseFile,togglePoolName,matchesPreview};`)(()=>props,()=>({t:s=>s}),useForm,value=>({value}),(getter,callback)=>{changed=callback;},csvPoolName);return {vm,props,requests,changed,source};
}
test('optional third note defaults correctly and ignore is explicit; preview shows only imported note values',()=>{const {vm,source}=mountImport();assert.equal(vm.form.note_column,2);assert.match(source,/<option :value="null">/);assert.match(source,/Keep existing note/);assert.match(source,/Set private note/);assert.match(source,/term,definition,note/);assert.match(source,/row.note/);assert.doesNotMatch(source,/existing_note|owner.note/);});
test('mapping changes invalidate commit until fresh preview; edits during upload stay invalid',()=>{const {vm,requests,changed}=mountImport();vm.form.note_column=null;changed();vm.commitImport();assert.equal(requests.length,0);assert.equal(vm.previewDirty.value,true);vm.upload();changed();requests[0].options.onSuccess();assert.equal(vm.previewDirty.value,true);vm.upload();requests[1].options.onSuccess();assert.equal(vm.previewDirty.value,false);vm.commitImport();assert.deepEqual(requests[2].data,{collection_id:'',pool_id:2,preview_id:'nonce-a'});});
test('upload and commit cannot overlap and source controls include every mapped input',()=>{const {vm,requests,source}=mountImport();vm.form.processing=true;vm.commitImport();vm.upload();assert.equal(requests.length,0);vm.form.processing=false;vm.save.processing=true;vm.upload();assert.equal(requests.length,0);assert.match(source,/form.file,form.pool_id,form.term_column,form.definition_column,form.note_column,form.has_header/);});

test('starting replacement preview invalidates old commit even when replacement fails',()=>{const {vm,requests}=mountImport();assert.equal(vm.previewDirty.value,false);vm.upload();assert.equal(vm.previewDirty.value,true);vm.commitImport();assert.equal(requests.length,1);vm.upload();requests[1].options.onSuccess();assert.equal(vm.previewDirty.value,false);vm.commitImport();assert.equal(requests[2].url,'/imports/store');});

test('new-pool selection displays a quoted first-row name and restores the existing pool when unchecked',async()=>{
 const {vm}=mountImport();vm.form.first_row_pool_name=true;vm.togglePoolName();
 assert.equal(vm.form.pool_id,'new');
 await vm.chooseFile({target:{files:[{size:80,text:async()=> '\uFEFF"Travel, words"\nterm,definition\nhello,greeting'}]}});
 await vm.readPoolName();assert.equal(vm.newPoolName.value,'Travel, words');
 vm.form.first_row_pool_name=false;vm.togglePoolName();assert.equal(vm.form.pool_id,2);
});
test('new pool commits only the preview nonce and rejects altered pool settings',()=>{
 const {vm,props,requests}=mountImport();Object.assign(vm.form,{first_row_pool_name:true,pool_id:'new',new_pool_language:'tr',new_pool_direction:'ltr'});vm.newPoolName.value='Travel';
 props.preview={preview_id:'new-nonce',pool_id:null,new_pool:{name:'Travel',language:'tr',text_direction:'ltr'},total:1};
 vm.commitImport();assert.deepEqual(requests[0].data,{collection_id:'',pool_id:null,preview_id:'new-nonce'});
 vm.form.new_pool_language='en';vm.commitImport();assert.equal(requests.length,1);
});
test('first-row CSV name handles BOM, quoted commas, escaped quotes and rejects word rows',()=>{
 assert.equal(csvPoolName('\uFEFF"Travel, \"\"words\"\""\r\nterm,definition'),'Travel, "words"');
 assert.equal(csvPoolName(' Travel , ,\nterm,definition'),'Travel');
 for(const text of ['',',,','word,definition','"unfinished','a'.repeat(151)])assert.throws(()=>csvPoolName(text));
});
test('stale file reads cannot replace the latest selected pool name',async()=>{
 const {vm}=mountImport();vm.form.first_row_pool_name=true;
 let resolve;vm.form.file={size:30,text:()=>new Promise(done=>{resolve=done;})};const old=vm.readPoolName();
 vm.form.file={size:30,text:async()=> 'Latest\nterm,definition'};await vm.readPoolName();resolve('Old\nterm,definition');await old;
 assert.equal(vm.newPoolName.value,'Latest');
});
