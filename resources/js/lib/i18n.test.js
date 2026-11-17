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

test('Turkish covers the existing UI catalog and preserves interpolation placeholders', async () => {
 const {default:pt}=await import('./pt.js');const {default:tr}=await import('./tr.js');
 for(const key of Object.keys(pt)){
  assert.ok(tr[key],key);
  assert.deepEqual([...tr[key].matchAll(/\{[^}]+\}/g)].map(m=>m[0]).sort(),[...key.matchAll(/\{[^}]+\}/g)].map(m=>m[0]).sort(),key);
 }
 assert.equal(translate('tr','Profile'),'Profil');
 assert.equal(translate('tr','Add {count} words',{count:15}),'15 kelime ekle');
 assert.equal(translate('tr','Unmapped text'),'Unmapped text');
});

test('profile uses a language select and the sidebar keeps its slogan on mobile too', async () => {
 const {readFileSync}=await import('node:fs');
 const profile=readFileSync(new URL('../Pages/Account/Profile.vue',import.meta.url),'utf8');
 const layout=readFileSync(new URL('../Layouts/AppLayout.vue',import.meta.url),'utf8');
 assert.doesNotMatch(profile,/LanguageSwitch/);
 assert.match(profile,/<select :value="locale" :disabled="language.processing" @change="changeLanguage">/);
 for(const code of ['en','pt','tr'])assert.ok(profile.includes(`value="${code}"`));
 for(const text of ['MAKE ROOM FOR NEW WORDS.','Learn a little.','Remember a lot.'])assert.ok(layout.includes(text));
 assert.doesNotMatch(layout,/\.sidebar-bottom>\.small-label,\.sidebar-bottom>p\{display:none\}/);
});
