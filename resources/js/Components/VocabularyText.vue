<script setup>
import {safeDirection} from '../lib/text-direction.js';
defineProps({text:{default:''},direction:{type:String,default:'ltr'},as:{type:String,default:'span'}});
</script><template><component :is="as" class="vocabulary-text" :dir="safeDirection(direction)"><bdi dir="auto">{{text}}</bdi></component></template><style scoped>.vocabulary-text{display:block;min-width:0;max-width:100%;unicode-bidi:isolate;overflow-wrap:anywhere;white-space:pre-wrap}.vocabulary-text>bdi{display:block;unicode-bidi:isolate;text-align:start}</style>
