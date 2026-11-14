import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
const members=readFileSync(new URL('../Pages/Collections/Members.vue',import.meta.url),'utf8');
const source=readFileSync(new URL('../Pages/Collections/Show.vue',import.meta.url),'utf8');
test('sharing management is owner-only and uses controlled accessible switch with pending guard',()=>{
 assert.match(source,/v-if="owner" class="sharing panel sharing-management"/);
 assert.match(source,/role="switch" :checked="Boolean\(collection.share_uuid\)" :aria-checked="Boolean\(collection.share_uuid\)" :disabled="sharingBusy"/);
 assert.match(source,/if\(sharingBusy.value\)return/);
 assert.match(source,/onFinish:\(\)=>\{sharingBusy.value=false/);
 assert.match(source,/event.target.checked=Boolean\(props.collection.share_uuid\)/);
 assert.match(source,/Errors :errors="sharingErrors"/);
});
test('refresh invalidates old links explicitly and keeps refresh before copy beneath link',()=>{
 assert.match(source,/old link will stop working immediately. Existing members keep access and blocked accounts stay blocked/);
 assert.match(source,/sharingAction\('post',`\$\{base.value\}\/sharing\/rotate`\)/);
 const actions=source.slice(source.indexOf('class="share-link-actions"'));
 assert.ok(actions.indexOf('@click="rotateLink"')<actions.indexOf('@click="copy"'));
 assert.match(source,/all joined accounts will lose access/);
});
test('access choice, remove, block, unblock have distinct routes and truthful consequences',()=>{
 assert.match(source,/option value="guests"/);assert.match(source,/option value="signed_in"/);
 assert.match(source,/sharing\/access`,\{access\}/);
 assert.match(members,/sharingAction\('delete',`\$\{base.value\}\/members\/\$\{person.id\}`\)/);
 assert.match(members,/sharingAction\('post',`\$\{base.value\}\/blocks\/\$\{person.id\}`\)/);
 assert.match(members,/sharingAction\('delete',`\$\{base.value\}\/blocks\/\$\{person.id\}`\)/);
 assert.match(members,/can join again using an active link/);
 assert.match(members,/Membership is not restored automatically/);
 assert.match(source,/A blocked account can still view the collection while signed out/);
});

test('member identities are shown only on the separate management page with server search and independent pagination',()=>{
 assert.match(source,/href="`\$\{base\}\/sharing\/members`"/);
 assert.doesNotMatch(source,/v-for="person in sharing\.(participants|blocked)/);
 assert.match(members,/router.get\(`\$\{base.value\}\/sharing\/members`,\{q:query.value\}/);
 assert.match(members,/maxlength="100"/);
 assert.match(members,/page:participants/);assert.match(members,/page:blocked/);
 assert.match(members,/group.page.prev_page_url/);assert.match(members,/group.page.next_page_url/);
 assert.doesNotMatch(members,/v-html/);
});
test('Read Aloud is compact and includes a decorative speaker icon',()=>{
 const session=readFileSync(new URL('../Pages/Study/Session.vue',import.meta.url),'utf8');
 assert.match(session,/class="read-aloud-label"><svg/);
 assert.match(session,/padding:12px 16px!important;margin-bottom:12px!important/);
});
