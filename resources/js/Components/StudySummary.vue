<script setup>
defineProps({ summary: { type: Object, default: () => ({ modes: {}, recent: [], total_sessions: 0 }) } });
const labels = { flashcards: 'Flashcards', study: 'Study', test: 'Test' };
function date(value) { return value ? new Date(value).toLocaleString() : 'Not yet practiced'; }
function outcome(session) { return session ? `${session.correct} / ${session.total} ${session.mode === 'flashcards' ? 'marked known' : 'correct'}${session.mode === 'test' ? ` · ${session.percent}%` : ''}` : 'No completed sessions'; }
</script>
<template>
    <section class="panel study-history">
        <div class="section-heading"><div><span class="eyebrow">YOUR PRIVATE PROGRESS</span><h2>Practice summary</h2></div><span class="pill">{{ summary.total_sessions }} completed sessions</span></div>
        <div class="mode-grid">
            <article v-for="(label, mode) in labels" :key="mode" class="history-mode" :class="`history-mode--${mode}`">
                <h3>{{ label }}</h3><p>{{ summary.modes[mode]?.count || 0 }} completed</p>
                <strong>{{ outcome(summary.modes[mode]?.last) }}</strong>
                <p class="muted small">{{ date(summary.modes[mode]?.last?.completed_at) }}</p>
                <p v-if="mode === 'test' && summary.modes[mode]?.best_percent !== null && summary.modes[mode]?.best_percent !== undefined">Best: {{ Math.round(summary.modes[mode].best_percent) }}%</p>
                <p v-if="mode === 'flashcards'" class="muted small">Self-assessed, not a test score.</p>
            </article>
        </div>
        <h3 class="recent-heading">Recent sessions</h3>
        <p v-if="!summary.recent.length" class="muted">Complete a session to see your progress here.</p>
        <ol v-else class="history-list"><li v-for="session in summary.recent" :key="session.id"><div><strong>{{ labels[session.mode] }}</strong><span v-if="session.practice_round" class="muted"> · Retry practice</span><p>{{ outcome(session) }} · {{ session.total - session.correct }} {{ session.mode === 'flashcards' ? 'still learning' : 'incorrect' }}</p><small v-if="session.mode !== 'flashcards' && session.self_assessed">{{ session.self_assessed }} accepted by self-assessment</small></div><time :datetime="session.completed_at">{{ date(session.completed_at) }}</time></li></ol>
        <p class="muted small">Only completed sessions are shown. Results reflect the words and settings used at that time, not mastery of the entire current collection. Shared-link visitors cannot see this history.</p>
    </section>
</template>
<style scoped>
.study-history{margin-bottom:2rem}.history-mode{padding:1rem;border:1px solid #dce5e4;border-radius:12px}.history-list{padding:0;list-style:none}.history-list li{display:flex;justify-content:space-between;gap:1rem;padding:1rem 0;border-bottom:1px solid #dce5e4}.history-list time{font-size:.85rem;color:#52656d}@media(max-width:600px){.history-list li{flex-direction:column}.section-heading{flex-wrap:wrap}}

.history-mode--flashcards{background:#e9f7ef;border-color:#b6ddc8;color:#164b3c}
.history-mode--study{background:#edf3ff;border-color:#c3d5f3;color:#204779}
.history-mode--test{background:#f3edff;border-color:#d7c7ef;color:#573778}
.history-mode .muted{color:inherit;opacity:.85}
.history-mode h3{margin-bottom:.8rem}
.recent-heading{margin-top:2.25rem;margin-bottom:1.1rem}
.study-history>.section-heading .pill{background:#e9f7ef;border-color:#b6ddc8;color:#24604a}
</style>
