<script setup>
import { Link } from "@inertiajs/vue3";
import { useI18n } from "../lib/i18n.js";
const {t,locale}=useI18n();
defineProps({ base: { type: String, required: true }, summary: { type: Object, default: () => ({ modes: {}, recent: [], total_sessions: 0 }) } });
const labels = { flashcards: 'Flashcards', study: 'Study', test: 'Test' };
function date(value) { return value ? new Date(value).toLocaleString(locale.value==='pt'?'pt-PT':'en-GB') : t('Not yet practiced'); }
function outcome(session) { return session ? `${session.correct} / ${session.total} ${session.mode === 'flashcards' ? t('marked known') : t('correct')}${session.mode === 'test' ? ` · ${session.percent}%` : ''}` : t('No completed sessions'); }
</script>
<template>
    <section class="panel study-history">
        <div class="section-heading"><div><span class="eyebrow">{{t("YOUR PRIVATE PROGRESS")}}</span><h2>{{t("Practice summary")}}</h2></div><span class="pill">{{ summary.total_sessions }} {{t('completed sessions')}}</span></div>
        <div class="mode-grid">
            <article v-for="(label, mode) in labels" :key="mode" class="history-mode" :class="`history-mode--${mode}`">
                <h3>{{ t(label) }}</h3><p>{{ summary.modes[mode]?.count || 0 }} {{t('completed')}}</p>
                <strong>{{ outcome(summary.modes[mode]?.last) }}</strong>
                <p class="muted small">{{ date(summary.modes[mode]?.last?.completed_at) }}</p>
                <p v-if="mode === 'test' && summary.modes[mode]?.best_percent !== null && summary.modes[mode]?.best_percent !== undefined">{{t('Best:')}} {{ Math.round(summary.modes[mode].best_percent) }}%</p>
                <p v-if="mode === 'flashcards'" class="muted small">{{t("Self-assessed, not a test score.")}}</p>
                <Link :href="`${base}/study?mode=${mode}`" class="button history-start" :aria-label="t('Start {mode} →', {mode:t(label)})">{{ t('Start →') }}</Link>
            </article>
        </div>
        <div class="completion-summary" :aria-label="t('completed sessions')">
            <div class="completion-total"><strong>{{ summary.total_sessions || 0 }}</strong><span>{{t('completed sessions')}}</span></div>
            <dl class="completion-counts">
                <div v-for="(label, mode) in labels" :key="mode" class="completion-count" :class="`completion-count--${mode}`">
                    <dt><span class="count-dot" aria-hidden="true"></span>{{t(label)}}</dt>
                    <dd>{{ summary.modes[mode]?.count || 0 }}</dd>
                </div>
            </dl>
        </div>
        <p class="muted small">{{t("Only completed sessions are shown. Results reflect the words and settings used at that time, not mastery of the entire current collection. Shared-link visitors cannot see this history.")}}</p>
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
.completion-summary{display:flex;align-items:center;gap:2rem;margin:1.75rem 0 1rem;padding:1.25rem 1.5rem;border:1px solid #dce5e4;border-radius:14px;background:linear-gradient(120deg,#f5f8f6,#f0f4f9)}
.completion-total{display:flex;align-items:center;gap:.8rem;flex-shrink:0;color:#183544}
.completion-total strong{font-size:2.1rem;line-height:1;font-variant-numeric:tabular-nums;letter-spacing:-.04em}
.completion-total span{max-width:9rem;font-size:.85rem;line-height:1.4;color:#52656d}
.completion-counts{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:.65rem;flex:1;margin:0;padding-left:1.5rem;border-left:1px solid #d5dfdf}
.completion-count{padding:.7rem .85rem;border-radius:10px;background:#fff;border:1px solid #e1e8e7}
.completion-count dt{display:flex;align-items:center;gap:.45rem;font-size:.75rem;font-weight:650;color:#52656d}
.completion-count dd{margin:.4rem 0 0;font-size:1.3rem;line-height:1.2;font-weight:750;font-variant-numeric:tabular-nums;color:#183544}
.count-dot{width:7px;height:7px;flex-shrink:0;border-radius:50%;background:#217c60}
.completion-count--study .count-dot{background:#3769ae}.completion-count--test .count-dot{background:#8056a0}
@media(max-width:800px){.completion-summary{align-items:stretch;flex-direction:column;gap:1rem}.completion-counts{padding-left:0;border-left:0}}
@media(max-width:600px){.completion-summary{padding:1.1rem}.completion-counts{grid-template-columns:1fr}.completion-count{display:flex;align-items:center;justify-content:space-between;gap:1rem}.completion-count dd{margin:0}}
.study-history>.section-heading .pill{background:#e9f7ef;border-color:#b6ddc8;color:#24604a}
</style>
