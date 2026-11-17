import { computed, watchEffect } from 'vue';
import { usePage } from '@inertiajs/vue3';
import pt from './pt.js';
import tr from './tr.js';
const translations = { pt, tr };
export function translate(locale, key, values = {}) {
    let text = translations[locale]?.[key] ?? key;
    for (const [name, value] of Object.entries(values)) text = text.replaceAll(`{${name}}`, String(value));
    return text;
}
export function useI18n() {
    const page = usePage();
    const locale = computed(() => ['en', 'pt', 'tr'].includes(page.props.locale) ? page.props.locale : 'en');
    watchEffect(() => { if (typeof document !== 'undefined') document.documentElement.lang = ({en:'en',pt:'pt-PT',tr:'tr-TR'})[locale.value]; });
    const t = (key, values) => translate(locale.value, key, values);
    return { t, locale };
}
