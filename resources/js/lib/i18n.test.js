import test from 'node:test';
import assert from 'node:assert/strict';
import { translate } from './i18n.js';
test('Portuguese UI keys and interpolation leave data values untouched',()=>{
 assert.equal(translate('pt','Word pool'),'Banco de palavras');
 assert.equal(translate('pt','Add {count} words',{count:15}),'Adicionar 15 palavras');
 assert.equal(translate('pt','Remove “{term}” from this collection? It stays in your word pool.',{term:'hello'}),'Remover “hello” desta coleção? A palavra fica no seu banco.');
 assert.equal(translate('en','Word pool'),'Word pool');
 assert.equal(translate('pt','Unmapped text'),'Unmapped text');
});
test('every literal UI translation key has a Portuguese translation', async () => {
 const fs=await import('node:fs');const path=await import('node:path');const {default:pt}=await import('./pt.js');
 function walk(directory){for(const entry of fs.readdirSync(directory,{withFileTypes:true})){
  const file=path.join(directory,entry.name);if(entry.isDirectory())walk(file);
  else if(file.endsWith('.vue'))for(const match of fs.readFileSync(file,'utf8').matchAll(/\bt\(\s*(['"])(.*?)\1/g))if(/[A-Za-z]/.test(match[2]))assert.ok(match[2] in pt,`${file}: ${match[2]}`);
 }}
 const {fileURLToPath}=await import('node:url');walk(fileURLToPath(new URL('..',import.meta.url)));
});
