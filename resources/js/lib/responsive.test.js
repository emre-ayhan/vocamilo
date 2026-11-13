import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import postcss from 'postcss';

const css = postcss.parse(readFileSync(new URL('../../css/app.css', import.meta.url), 'utf8'));
function columns(selector, width) {
    let value;
    css.walkRules(rule => {
        if (!rule.selectors.includes(selector)) return;
        for (let parent = rule.parent; parent; parent = parent.parent) {
            if (parent.type !== 'atrule' || parent.name !== 'media') continue;
            if (/prefers-/.test(parent.params)) return;
            for (const [, kind, number] of parent.params.matchAll(/(min|max)-width:\s*(\d+)px/g)) {
                if (kind === 'min' ? width < Number(number) : width > Number(number)) return;
            }
        }
        rule.walkDecls('grid-template-columns', declaration => { value = declaration.value; });
    });
    return value;
}
test('cards stack throughout mobile including the former 601–760 gap', () => {
    for (const width of [320, 390, 600, 601, 760]) {
        for (const selector of ['.mode-grid', '.stats-grid', '.collection-grid']) assert.equal(columns(selector, width), '1fr', `${selector} at ${width}`);
    }
});
test('tablet practice cards use two columns and desktop uses three', () => {
    for (const width of [761, 820, 1024]) for (const selector of ['.mode-grid', '.stats-grid']) assert.equal(columns(selector, width), 'repeat(2,minmax(0,1fr))');
    assert.equal(columns('.mode-grid', 1025), 'repeat(3,1fr)');
});
test('one language switch belongs to the sidebar brand, not the page action header', () => {
    const layout = readFileSync(new URL('../Layouts/AppLayout.vue', import.meta.url), 'utf8');
    assert.equal((layout.match(/<LanguageSwitch\s*\/>/g) || []).length, 1);
    assert.match(layout, /class="sidebar-brand"[\s\S]*?<LanguageSwitch\/>\s*<\/div>/);
    assert.doesNotMatch(layout, /class="header-actions"><LanguageSwitch/);
});
