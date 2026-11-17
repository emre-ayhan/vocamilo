<script setup>
import AppIcon from "../Components/AppIcon.vue";
import { Head, Link } from '@inertiajs/vue3';
import { useI18n } from '../lib/i18n.js';
import AppLayout from '../Layouts/AppLayout.vue';
const { t } = useI18n();
defineProps({ stats: { type: Object, default: () => ({}) }, collections: { type: Array, default: () => [] }, recentWords: { type: Array, default: () => [] } });
</script>
<template>
    <Head :title="t('Overview')" />
    <AppLayout :title="t('Make every word count.')" :subtitle="t('A little practice today. A little more confidence tomorrow.')">
        <template #actions><Link href="/imports" class="button"><AppIcon name="import"/>{{ t('Import words') }}</Link></template>
        <section class="hero"><div><span class="pill">{{ t('YOUR LEARNING SPACE') }}</span><h2>{{ t('Turn new words') }}<br />{{ t('into second nature.') }}</h2><p>{{ t('Build a collection, flip a card, and discover what sticks.') }}</p><Link href="/collections" class="button light">{{ t('Explore collections →') }}</Link></div><div class="hero-card"><span>{{ t('WORD OF YOUR JOURNEY') }}</span><strong>{{ t('possibility') }}</strong><p>{{ t('Something that can happen.') }}<br />{{ t('Like learning a little more today.') }}</p><div>{{ t('✦ Keep your curiosity.') }}</div></div></section>
        <div class="stats-grid"><article class="stat"><span>{{ t('Words collected') }}</span><strong>{{ stats.words ?? 0 }}</strong><small>{{ t('Ideas worth remembering') }}</small></article><article class="stat"><span>{{ t('Your collections') }}</span><strong>{{ stats.collections ?? 0 }}</strong><small>{{ t('A place for every topic') }}</small></article><article class="stat"><span>{{ t('Shared with me') }}</span><strong>{{ stats.joined ?? stats.memberships ?? stats.shared_with_me ?? 0 }}</strong><small>{{ t('Knowledge travels further') }}</small></article></div>
        <div class="section-heading"><h2>{{ t('Your collections') }}</h2><Link href="/collections">{{ t('View all →') }}</Link></div>
        <div class="collection-grid" v-if="collections.length"><Link class="collection-card" v-for="(c, i) in collections" :key="c.id" :href="`/collections/${c.id}`"><div class="collection-icon" :class="`tone-${i % 3}`"><AppIcon/></div><span class="tiny-label">{{ t(c.isOwner === false ? 'SHARED WITH ME' : c.share_uuid ? 'LINK SHARING ON' : 'PRIVATE COLLECTION') }}</span><h3>{{ c.title }}</h3><p>{{ c.description || t('A new world of words.') }}</p><div class="card-bottom"><span>{{ t('{count} words', { count: c.words_count ?? c.words?.length ?? 0 }) }}</span><span>{{ t('Open →') }}</span></div></Link></div>
        <div v-else class="empty"><span><AppIcon/></span><h3>{{ t('Your first collection starts here.') }}</h3><p>{{ t('Group words by topic, book, lesson, or whatever inspires you.') }}</p><Link href="/collections" class="button">{{ t('Create a collection') }}</Link></div>
        <div class="section-heading"><h2>{{ t('Recently added') }}</h2><Link href="/words">{{ t('Word pool →') }}</Link></div><div class="word-list"><div v-for="word in recentWords" :key="word.id" class="word-row"><strong>{{ word.term }}</strong><p>{{ word.definition }}</p></div><p v-if="!recentWords.length" class="muted padded">{{ t('No words yet. Add one or import a CSV to begin.') }}</p></div>
    </AppLayout>
</template>
