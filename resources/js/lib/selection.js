export const MAX_SELECTION = 500;

export function inclusiveRange(ids, first, last) {
    const start = ids.indexOf(first), end = ids.indexOf(last);
    if (start < 0 || end < 0) return [];
    return ids.slice(Math.min(start, end), Math.max(start, end) + 1);
}

// Bulk operations are atomic: never silently truncate a user's selection.
export function selectIds(selected, additions, limit = MAX_SELECTION) {
    const next = [...new Set([...selected, ...additions])];
    return next.length > limit ? { ids: [...selected], overflow: true } : { ids: next, overflow: false };
}

export function deselectIds(selected, removals) {
    const removed = new Set(removals);
    return selected.filter(id => !removed.has(id));
}
