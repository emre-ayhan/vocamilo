import test from 'node:test';
import assert from 'node:assert/strict';
import { postSession, sessionAnswers } from './session-api.js';

test('answer payload separates subjective flashcard marks from server-graded answers', () => {
    const results = [{ id: 4, answer: 'example', correct: true, self_assessed: true, note: 'private', expected: 'example' }];
    assert.deepEqual(sessionAnswers(results, 'study'), [{word_id:4, answer:'example', self_assessed:true}]);
    assert.deepEqual(sessionAnswers(results, 'flashcards'), [{word_id:4, answer:'example', known:true, self_assessed:false}]);
    assert.equal('correct' in sessionAnswers(results, 'test')[0], false);
    assert.equal('note' in sessionAnswers(results, 'test')[0], false);
});
test('session requests use same-origin credentials and decoded CSRF token', async () => {
    const originalFetch = globalThis.fetch, originalDocument = globalThis.document;
    globalThis.document = { cookie: 'other=value; XSRF-TOKEN=abc%3D' };
    globalThis.fetch = async (url, options) => {
        assert.equal(url, '/collections/1/sessions');
        assert.equal(options.credentials, 'same-origin');
        assert.equal(options.headers['X-XSRF-TOKEN'], 'abc=');
        assert.equal(options.headers.Accept, 'application/json');
        return { ok:true, json:async()=>({id:'saved'}) };
    };
    try { assert.deepEqual(await postSession('/collections/1/sessions', {}), {id:'saved'}); }
    finally { globalThis.fetch=originalFetch;globalThis.document=originalDocument; }
});
test('failed save and expired session throw instead of reporting saved', async () => {
    const originalFetch = globalThis.fetch, originalDocument = globalThis.document;
    globalThis.document = { cookie: '' };
    try {
        globalThis.fetch=async()=>({ok:false,status:500});
        await assert.rejects(postSession('/save',{}), /could not be saved/);
        globalThis.fetch=async()=>({ok:false,status:419});
        await assert.rejects(postSession('/save',{}), /expired/);
        globalThis.fetch=async()=>{throw new Error('offline');};
        await assert.rejects(postSession('/save',{}), /offline/);
    } finally { globalThis.fetch=originalFetch;globalThis.document=originalDocument; }
});
