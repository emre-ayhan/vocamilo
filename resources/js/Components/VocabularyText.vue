<script setup>
import {hasArabicScript} from '../lib/vocabulary-typography.js';
import {safeDirection} from '../lib/text-direction.js';
defineProps({text:{default:''},direction:{type:String,default:'ltr'},as:{type:String,default:'span'}});
</script><template><component :is="as" class="vocabulary-text" :class="{'arabic-content':hasArabicScript(text)}" :dir="safeDirection(direction)"><bdi dir="auto">{{text}}</bdi></component></template><style scoped>.vocabulary-text{display:block;min-width:0;max-width:100%;unicode-bidi:isolate;overflow-wrap:anywhere;white-space:pre-wrap}.vocabulary-text>bdi{display:block;unicode-bidi:isolate;text-align:start}.arabic-content{font-size:1.25em;line-height:1.9}@media(min-width:900px){.arabic-content{font-size:1.4em;line-height:1.9}}</style>
