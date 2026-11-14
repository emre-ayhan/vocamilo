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
test('one compact language switch sits directly before Sign out and moves opposite the mobile brand', () => {
    const layout = readFileSync(new URL('../Layouts/AppLayout.vue', import.meta.url), 'utf8');
    assert.equal((layout.match(/<LanguageSwitch\s*\/>/g) || []).length, 1);
    assert.match(layout, /<\/p><div class="sidebar-language"><LanguageSwitch\/><\/div><Link v-if="page.props.auth\?\.user" href="\/logout"/);
    assert.match(layout, /class="sidebar-bottom"><InstallApp\/><div class="small-label">/);
    assert.match(layout, /sidebar-language\{position:absolute;top:18px;right:20px/);
    assert.doesNotMatch(layout, /class="header-actions"><LanguageSwitch/);
});
test('compact switch uses the actual navy theme and logo lime', () => {
    const source = readFileSync(new URL('../Components/LanguageSwitch.vue', import.meta.url), 'utf8');
    assert.match(source, /border:1px solid #aec0c7[^}]*background:#122c3b/);
    assert.match(source, /aria-pressed=true\]\{background:#d5f1ad;color:#15372f/);
    assert.match(source, /min-height:28px/);
});

test('private mode starts live inside summary cards while guests retain all three modes',()=>{
    const show=readFileSync(new URL('../Pages/Collections/Show.vue',import.meta.url),'utf8');
    const summary=readFileSync(new URL('../Components/StudySummary.vue',import.meta.url),'utf8');
    assert.match(show, /<StudySummary v-if="!shared" :summary="summary" :base="base"/);
    assert.match(show, /<div v-if="shared" class="mode-grid guest-mode-grid">/);
    assert.match(summary, /class="button history-start"/);
    assert.match(summary, /history-start\{width:100%;margin-top:auto/);
    assert.match(summary, /study\?mode=\$\{mode\}/);
});
test('progress summary shows latest word totals in mode cards without a separate count card',()=>{
 const summary=readFileSync(new URL('../Components/StudySummary.vue',import.meta.url),'utf8');
 assert.doesNotMatch(summary,/Recent sessions|v-for="session in summary.recent"/);
 assert.doesNotMatch(summary,/completion-summary/);
 assert.match(summary,/latest_practiced/);
 assert.match(summary,/latest_correct/);
 assert.match(summary,/mode-percentage/);
 assert.match(summary,/latest_percent == null/);
 assert.match(summary,/summary-explanation/);
 assert.match(summary,/last\?\.completed_at/);
 assert.match(summary,/<section class="study-history">/);
 assert.doesNotMatch(summary,/class="panel study-history"/);
 assert.match(summary,/summary.total_sessions/);
 assert.match(summary,/summary.modes\[mode\]\?\.count/);
 assert.match(summary,/class="button history-start"/);
});
test('mobile install has breathing room and login language sits opposite logo',()=>{
 const install=readFileSync(new URL('../Components/InstallApp.vue',import.meta.url),'utf8');
 const login=readFileSync(new URL('../Pages/Auth/Login.vue',import.meta.url),'utf8');
 assert.match(install, /max-width:760px\)\{\.install-app\{margin:20px 0 18px/);
 assert.match(login,/login-shell .auth-language\{position:absolute;top:25px;right:20px/);
 assert.equal((login.match(/<LanguageSwitch\/>/g)||[]).length,1);
});
test('guest Start is larger and right aligned; Start icons replace long visual arrows',()=>{
 const show=readFileSync(new URL('../Pages/Collections/Show.vue',import.meta.url),'utf8');
 assert.match(show,/guest-start\{align-self:center/);
 assert.match(show,/min-width:112px;min-height:44px/);
 for(const path of ['../Pages/Collections/Show.vue','../Components/StudySummary.vue','../Pages/Study/Session.vue']){
 const s=readFileSync(new URL(path,import.meta.url),'utf8');
 assert.match(s,/<StartIcon\/>/);
 assert.doesNotMatch(s,/}}<\/b>.*Start →/);
 }
});

test('guest and summary share icons; count and gradient circle align in one heading',()=>{
 const show=readFileSync(new URL('../Pages/Collections/Show.vue',import.meta.url),'utf8');
 const summary=readFileSync(new URL('../Components/StudySummary.vue',import.meta.url),'utf8');
 assert.match(show,/<ModeIcon :mode="item.mode"/);
 assert.match(summary,/<ModeIcon :mode="mode"/);
 assert.match(summary,/mode-title-group/);
 assert.match(summary,/class="mode-completed"/);
 assert.match(summary,/class="mode-metrics"/);
 assert.match(summary,/class="percentage-splash"/);
 assert.match(summary,/collection_word_count/);
 assert.match(summary,/linearGradient/);
});
