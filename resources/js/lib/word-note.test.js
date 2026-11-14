import test from 'node:test';
import assert from 'node:assert/strict';
import {wordNote} from './word-note.js';
import {readFileSync} from 'node:fs';
test('private notes read fresh and PUT with CSRF and same-origin credentials',async()=>{
 const oldFetch=globalThis.fetch,oldDocument=globalThis.document,calls=[];
 globalThis.document={cookie:'XSRF-TOKEN=a%20b'};
 globalThis.fetch=async(url,options)=>{calls.push({url,options});return{ok:true,json:async()=>({note:'private'})};};
 try{
 assert.deepEqual(await wordNote('/collections/1/words/2/note'),{note:'private'});
 await wordNote('/collections/1/words/2/note',{note:'new'});
 assert.equal(calls[0].options.method,'GET');assert.equal(calls[1].options.method,'PUT');
 assert.equal(calls[1].options.credentials,'same-origin');
 assert.equal(calls[1].options.headers['X-XSRF-TOKEN'],'a b');
 assert.deepEqual(JSON.parse(calls[1].options.body),{note:'new'});
 }finally{globalThis.fetch=oldFetch;globalThis.document=oldDocument;}
});
test('failed save never reports success',async()=>{
 const oldFetch=globalThis.fetch,oldDocument=globalThis.document;
 globalThis.document={cookie:''};globalThis.fetch=async()=>({ok:false,status:403});
 try{await assert.rejects(()=>wordNote('/note',{note:'draft'}),/could not be loaded or saved/);}
 finally{globalThis.fetch=oldFetch;globalThis.document=oldDocument;}
});
test('editor is private, blocks duplicate saves, preserves failed draft and supports cancellation',()=>{
 const editor=readFileSync(new URL('../Components/FlashcardNote.vue',import.meta.url),'utf8');
 const session=readFileSync(new URL('../Pages/Study/Session.vue',import.meta.url),'utf8');
 assert.match(session,/<FlashcardNote v-if="!shared"/);
 assert.match(editor,/if\(loading.value\|\|saving.value\)return/);
 assert.match(editor,/v-model="draft"/);
 assert.match(editor,/:disabled="loading\|\|saving\|\|!loaded"/);
 assert.match(editor,/controller\?\.abort\(\)/);
 assert.match(editor,/await wordNote\(url\(\),\{note:draft.value/);
});

test('flashcard note editor spans below its Add Note and Read Aloud toolbar',async()=>{
 const {readFileSync}=await import('node:fs');
 const component=readFileSync(new URL('../Components/FlashcardNote.vue',import.meta.url),'utf8');
 const session=readFileSync(new URL('../Pages/Study/Session.vue',import.meta.url),'utf8');
 assert.match(component,/<slot name="tools"\/><\/div>/);
 assert.match(component,/:aria-expanded="open"/);
 assert.match(component,/:disabled="open\|\|loading\|\|saving"/);
 assert.ok(component.indexOf('<form v-if="open"')>component.indexOf('<slot name="tools"'));
 assert.match(component,/\.flash-note form\{margin-top:12px;width:100%;box-sizing:border-box\}/);
 assert.match(session,/<template #tools><label class="speech-toggle">/);
 assert.match(session,/<\/FlashcardNote><label v-else class="speech-toggle">/);
});
