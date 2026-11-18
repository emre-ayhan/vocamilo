<script setup>
import { useId } from "vue";
const gradientId = useId();
import ModeIcon from "./ModeIcon.vue";
import StartIcon from "./StartIcon.vue";
import { Link } from "@inertiajs/vue3";
import { useI18n } from "../lib/i18n.js";
const {t,locale}=useI18n();
defineProps({ readOnly: {type:Boolean,default:false}, base: { type: String, required: true }, summary: { type: Object, default: () => ({ modes: {}, recent: [], total_sessions: 0 }) } });
const labels = { flashcards: 'Flashcards', study: 'Study', test: 'Test' };
function date(value) { return value ? new Date(value).toLocaleString(({en:'en-GB',pt:'pt-PT',tr:'tr-TR'})[locale.value]) : t('Not yet practiced'); }

</script>
<template>
    <section class="study-history">
        <div class="section-heading"><div><span class="eyebrow">{{t(readOnly?'SHARED MEMBER PROGRESS':'YOUR PRIVATE PROGRESS')}}</span><h2>{{t("Practice summary")}}</h2></div><span class="pill">{{ summary.total_sessions }} {{t('completed sessions')}}</span></div>
        <div class="mode-grid">
            <article v-for="(label, mode) in labels" :key="mode" class="history-mode" :class="`history-mode--${mode}`">
                <div class="mode-heading"><div class="mode-title-group"><h3><ModeIcon :mode="mode"/>{{ t(label) }}</h3><p class="mode-completed">{{ summary.modes[mode]?.count || 0 }} {{t('completed')}}</p></div><div class="mode-percentage" :aria-label="summary.modes[mode]?.latest_percent == null ? t('No words in this collection') : t('Latest word success: {percent}%', {percent:summary.modes[mode].latest_percent})"><svg class="percentage-splash" viewBox="0 0 100 100" aria-hidden="true"><defs><linearGradient :id="gradientId+'-'+mode" x1="0" y1="0" x2="1" y2="1"><stop offset="0%" :stop-color="mode==='flashcards'?'#17644d':mode==='study'?'#24549b':'#65408f'"/><stop offset="100%" :stop-color="mode==='flashcards'?'#0e8174':mode==='study'?'#3474b6':'#8a4b97'"/></linearGradient></defs><path :fill="'url(#'+gradientId+'-'+mode+')'" d="M51 5C62 3 65 15 74 12C86 8 87 22 86 30C100 32 97 45 91 51C101 61 90 69 84 72C87 85 75 91 65 86C59 99 47 95 42 89C29 98 21 89 22 80C8 81 5 69 12 59C0 50 7 39 15 36C8 24 19 16 29 19C31 5 43 8 51 5Z"/><g :fill="'url(#'+gradientId+'-'+mode+')'"><circle cx="8" cy="13" r="2.8"/><circle cx="92" cy="19" r="2.2"/><circle cx="5" cy="77" r="2"/><circle cx="87" cy="91" r="3"/><circle cx="34" cy="3" r="1.6"/><path d="m16 8 5 5-4 2ZM95 66l4 6-6-1ZM12 93l2-6 4 4Z"/></g><path d="M31 25c9-8 23-10 34-4" fill="none" stroke="#fff" stroke-opacity=".28" stroke-width="2" stroke-linecap="round"/><path d="M27 72c10 8 22 9 34 6" fill="none" stroke="#fff" stroke-opacity=".16" stroke-width="1.5" stroke-linecap="round"/></svg><span>{{ summary.modes[mode]?.latest_percent == null ? '—' : summary.modes[mode].latest_percent+'%' }}</span></div></div>
                <div class="mode-metrics"><dl class="mode-totals"><div><dt>{{ t('Words practiced') }}</dt><dd>{{ summary.modes[mode]?.latest_practiced || 0 }}</dd></div><div><dt>{{ t(mode === 'flashcards' ? 'Marked known' : 'Latest correct answers') }}</dt><dd>{{ summary.modes[mode]?.latest_correct || 0 }}</dd></div><div><dt>{{ t('Collection words') }}</dt><dd>{{ summary.modes[mode]?.collection_word_count || 0 }}</dd></div></dl></div>
                <p class="muted small">{{ date(summary.modes[mode]?.last?.completed_at) }}</p>
                <p v-if="mode === 'test' && summary.modes[mode]?.best_percent !== null && summary.modes[mode]?.best_percent !== undefined">{{t('Best:')}} {{ Math.round(summary.modes[mode].best_percent) }}%</p>
                <p v-if="mode === 'flashcards'" class="muted small">{{t("Self-assessed, not a test score.")}}</p>
                <Link v-if="!readOnly" :href="`${base}/study?mode=${mode}`" class="button history-start" :aria-label="t('Start {mode} →', {mode:t(label)})">{{ t('Start') }}<StartIcon/></Link>
            </article>
        </div>
        <p class="muted small summary-explanation">{{t(readOnly?"Success is the member’s latest correct or known first attempts divided by all current collection words, including unpracticed words. Automatic retries do not change the first result. Empty collections show no percentage. Missing historical word records cannot be reconstructed.":"Success is your latest correct or known first attempts divided by all current collection words, including unpracticed words. Automatic retries do not change the first result. Empty collections show no percentage. Missing historical word records cannot be reconstructed.")}}</p>
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
.mode-heading{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:.8rem}.mode-heading h3{display:flex;align-items:center;gap:8px;margin:0;min-width:0}.mode-icon{width:22px;height:22px;flex-shrink:0}.mode-percentage{position:relative;display:grid;place-items:center;flex:0 0 82px;width:82px;height:82px;color:#fff;font-size:1rem;font-weight:750}.percentage-splash{position:absolute;inset:0;width:100%;height:100%}.mode-percentage span{position:relative;z-index:1}.summary-explanation{margin-top:1.75rem;margin-bottom:0}
.mode-title-group{min-width:0}.mode-completed{font-size:.8rem;margin:.4rem 0 0}.mode-heading{min-height:54px}
.mode-metrics{display:flex;align-items:center;gap:14px;margin-bottom:1rem}.mode-metrics .mode-totals{flex:1;min-width:0;margin:0}.mode-totals>div{gap:.5rem}
</style>

<style scoped>
.mode-percentage{font-variant-numeric:tabular-nums;font-size:1.15rem}
.mode-totals dt,.mode-completed{font-size:14px}.history-start{min-height:44px}
</style>
