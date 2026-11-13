import test from 'node:test';
import assert from 'node:assert/strict';
import { inclusiveRange, selectIds, deselectIds, MAX_SELECTION } from './selection.js';

test('ranges include both ends in either direction and use displayed order', () => {
    const ids = [9, 3, 7, 1];
    assert.deepEqual(inclusiveRange(ids, 3, 1), [3, 7, 1]);
    assert.deepEqual(inclusiveRange(ids, 1, 3), [3, 7, 1]);
    assert.deepEqual(inclusiveRange(ids, 7, 7), [7]);
    assert.deepEqual(ids, [9, 3, 7, 1]);
});
test('filtered ranges never include hidden words or stale anchors', () => {
    assert.deepEqual(inclusiveRange([1, 3, 5], 1, 5), [1, 3, 5]);
    assert.deepEqual(inclusiveRange([1, 3], 2, 3), []);
    assert.deepEqual(inclusiveRange([], 1, 3), []);
});
test('select all filtered results preserves hidden selections and deduplicates', () => {
    const initial = [8, 1];
    assert.deepEqual(selectIds(initial, [1, 3, 5]), { ids: [8, 1, 3, 5], overflow: false });
    assert.deepEqual(initial, [8, 1]);
});
test('clear results keeps hidden selections', () => {
    assert.deepEqual(deselectIds([8, 1, 3, 5], [1, 3]), [8, 5]);
    assert.deepEqual(deselectIds([8], []), [8]);
});
test('exactly 500 allowed; overflow is atomic and does not silently truncate', () => {
    const all = Array.from({ length: MAX_SELECTION }, (_, i) => i);
    assert.equal(selectIds([], all).overflow, false);
    assert.deepEqual(selectIds(all, [500]), { ids: all, overflow: true });
    assert.deepEqual(selectIds([999], all), { ids: [999], overflow: true });
    assert.deepEqual(selectIds(all, [0, 1]), { ids: all, overflow: false });
});
