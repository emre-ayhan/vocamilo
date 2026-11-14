<script setup>
import StartIcon from "./StartIcon.vue";
import { Link } from "@inertiajs/vue3";
import { useI18n } from "../lib/i18n.js";
const {t,locale}=useI18n();
defineProps({ base: { type: String, required: true }, summary: { type: Object, default: () => ({ modes: {}, recent: [], total_sessions: 0 }) } });
const labels = { flashcards: 'Flashcards', study: 'Study', test: 'Test' };
function date(value) { return value ? new Date(value).toLocaleString(locale.value==='pt'?'pt-PT':'en-GB') : t('Not yet practiced'); }

</script>
<template>
    <section class="study-history">
        <div class="section-heading"><div><span class="eyebrow">{{t("YOUR PRIVATE PROGRESS")}}</span><h2>{{t("Practice summary")}}</h2></div><span class="pill">{{ summary.total_sessions }} {{t('completed sessions')}}</span></div>
        <div class="mode-grid">
            <article v-for="(label, mode) in labels" :key="mode" class="history-mode" :class="`history-mode--${mode}`">
                <div class="mode-heading"><h3><svg class="mode-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><g v-if="mode==='flashcards'"><rect x="5" y="6" width="15" height="15" rx="2"/><path d="M16 3H4a2 2 0 0 0-2 2v12"/></g><g v-else-if="mode==='study'"><path d="m12 3 2.7 5.5 6.1.9-4.4 4.3 1 6.1-5.4-2.9-5.4 2.9 1-6.1-4.4-4.3 6.1-.9Z"/></g><g v-else><circle cx="12" cy="12" r="9"/><path d="m7 12 3 3 7-7"/></g></svg>{{ t(label) }}</h3><div class="mode-percentage" :aria-label="summary.modes[mode]?.latest_percent == null ? t('Not yet practiced') : t('Latest word success: {percent}%', {percent:summary.modes[mode].latest_percent})"><span>{{ summary.modes[mode]?.latest_percent == null ? '—' : summary.modes[mode].latest_percent+'%' }}</span></div></div><p>{{ summary.modes[mode]?.count || 0 }} {{t('completed')}}</p>
                <dl class="mode-totals"><div><dt>{{ t('Words practiced') }}</dt><dd>{{ summary.modes[mode]?.latest_practiced || 0 }}</dd></div><div><dt>{{ t(mode === 'flashcards' ? 'Marked known' : 'Latest correct answers') }}</dt><dd>{{ summary.modes[mode]?.latest_correct || 0 }}</dd></div></dl>
                <p class="muted small">{{ date(summary.modes[mode]?.last?.completed_at) }}</p>
                <p v-if="mode === 'test' && summary.modes[mode]?.best_percent !== null && summary.modes[mode]?.best_percent !== undefined">{{t('Best:')}} {{ Math.round(summary.modes[mode].best_percent) }}%</p>
                <p v-if="mode === 'flashcards'" class="muted small">{{t("Self-assessed, not a test score.")}}</p>
                <Link :href="`${base}/study?mode=${mode}`" class="button history-start" :aria-label="t('Start {mode} →', {mode:t(label)})">{{ t('Start') }}<StartIcon/></Link>
            </article>
        </div>
        <p class="muted small summary-explanation">{{t("Each current collection word counts once, using your latest completed first attempt in that mode. Unpracticed words and automatic retries are excluded. Flashcards use your latest known mark; Study can include accepted equivalent definitions. Missing historical word records cannot be reconstructed.")}}</p>
    </section>
</template>
<style scoped>
.study-history{margin-bottom:2rem}.history-mode{display:flex;flex-direction:column;padding:1rem;border:1px solid #dce5e4;border-radius:12px}@media(max-width:600px){.section-heading{flex-wrap:wrap}}

.history-mode--flashcards{background:#e9f7ef;border-color:#b6ddc8;color:#164b3c}
.history-mode--study{background:#edf3ff;border-color:#c3d5f3;color:#204779}
.history-mode--test{background:#f3edff;border-color:#d7c7ef;color:#573778}
.history-mode .muted{color:inherit;opacity:.85}
.history-mode h3{margin-bottom:.8rem}
.history-start{width:100%;margin-top:auto;color:#fff;border:0}
.history-mode--flashcards .history-start{background:linear-gradient(135deg,#17644d,#0e8174)}
.history-mode--study .history-start{background:linear-gradient(135deg,#24549b,#3474b6)}
.history-mode--test .history-start{background:linear-gradient(135deg,#65408f,#8a4b97)}
.history-start:hover{filter:brightness(.92);color:#fff}
.mode-totals{margin:0 0 1rem;display:grid;gap:.7rem}.mode-totals>div{display:flex;align-items:center;justify-content:space-between;gap:1rem}.mode-totals dt{font-size:.8rem;line-height:1.4}.mode-totals dd{margin:0;font-size:1.1rem;font-weight:700;font-variant-numeric:tabular-nums}
.study-history>.section-heading .pill{background:#e9f7ef;border-color:#b6ddc8;color:#24604a}
.mode-heading{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:.8rem}.mode-heading h3{display:flex;align-items:center;gap:8px;margin:0;min-width:0}.mode-icon{width:22px;height:22px;flex-shrink:0}.mode-percentage{display:grid;place-items:center;flex:0 0 54px;width:54px;height:54px;border:3px solid currentColor;border-radius:50%;background:#ffffff70;font-size:.85rem;font-weight:750}.summary-explanation{margin-top:1.75rem;margin-bottom:0}
</style>
