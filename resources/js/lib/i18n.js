import { computed, watchEffect } from 'vue';
import { usePage } from '@inertiajs/vue3';
import pt from './pt.js';
export function translate(locale, key, values = {}) {
    let text = locale === 'pt' ? (pt[key] ?? key) : key;
    for (const [name, value] of Object.entries(values)) text = text.replaceAll(`{${name}}`, String(value));
    return text;
}
export function useI18n() {
    const page = usePage();
    const locale = computed(() => page.props.locale === 'pt' ? 'pt' : 'en');
    watchEffect(() => { if (typeof document !== 'undefined') document.documentElement.lang = locale.value === 'pt' ? 'pt-PT' : 'en'; });
    const t = (key, values) => translate(locale.value, key, values);
    return { t, locale };
}
