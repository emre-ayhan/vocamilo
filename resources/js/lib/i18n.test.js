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
