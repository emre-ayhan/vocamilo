<script setup>
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
                <h3>{{ t(label) }}</h3><p>{{ summary.modes[mode]?.count || 0 }} {{t('completed')}}</p>
                <dl class="mode-totals"><div><dt>{{ t('Total word attempts') }}</dt><dd>{{ summary.modes[mode]?.total_practiced || 0 }}</dd></div><div><dt>{{ t(mode === 'flashcards' ? 'Marked known' : 'Correct first answers') }}</dt><dd>{{ summary.modes[mode]?.total_correct || 0 }}</dd></div></dl>
                <p class="muted small">{{ date(summary.modes[mode]?.last?.completed_at) }}</p>
                <p v-if="mode === 'test' && summary.modes[mode]?.best_percent !== null && summary.modes[mode]?.best_percent !== undefined">{{t('Best:')}} {{ Math.round(summary.modes[mode].best_percent) }}%</p>
                <p v-if="mode === 'flashcards'" class="muted small">{{t("Self-assessed, not a test score.")}}</p>
                <Link :href="`${base}/study?mode=${mode}`" class="button history-start" :aria-label="t('Start {mode} →', {mode:t(label)})">{{ t('Start →') }}</Link>
            </article>
        </div>
        <p class="muted small">{{t("Totals cover completed sessions, not unique words. The same word counts again in a new session; automatic retries do not add to these totals. Study correct answers may include self-assessed equivalents. Your progress stays private.")}}</p>
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
.mode-totals{margin:0 0 1rem;display:grid;gap:.7rem}.mode-totals>div{display:flex;align-items:center;justify-content:space-between;gap:1rem}.mode-totals dt{font-size:.8rem;line-height:1.4}.mode-totals dd{margin:0;font-size:1.6rem;font-weight:750;font-variant-numeric:tabular-nums}
.study-history>.section-heading .pill{background:#e9f7ef;border-color:#b6ddc8;color:#24604a}
</style>
