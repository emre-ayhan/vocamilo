import test from 'node:test';import assert from 'node:assert/strict';import {readFileSync} from 'node:fs';
const page=name=>readFileSync(new URL(`../Pages/${name}.vue`,import.meta.url),'utf8');
test('global disclosure requires unchecked explicit consent, permits decline logout and describes retained data and audit boundary',()=>{
 const s=page('Account/Disclosure');assert.match(s,/accepted:false/);assert.match(s,/v-model="form.accepted" required/);assert.match(s,/form.delete\('\/account\/disclosure'\)/);assert.match(s,/account and saved data will not be deleted/);assert.match(s,/collection access is logged/);assert.match(s,/direct server or database access/);assert.match(s,/cannot retract information already viewed/);
});
test('membership joins require explicit separate consent and withdrawal leaves without deleting history',()=>{
 const s=page('Collections/Consent');assert.match(s,/progress_consent:false/);assert.match(s,/progress_consent" type="checkbox" required/);assert.match(s,/existing and future learning results/);assert.match(s,/personal notes are never included/);assert.match(s,/form.delete\(`\/collections\/\$\{props.collection.id\}\/membership`\)/);assert.match(s,/history and notes are retained/);assert.match(s,/form.post\(`\/s\/\$\{props.joinUuid\}\/join\/cancel`\)/);assert.match(s,/:disabled="form.processing" @click="cancelJoin"/);assert.doesNotMatch(s,/<Link v-else :href=/);
 assert.match(page('Collections/Show'),/router.get\(`\/s\/\$\{props.collection.share_uuid\}\/join`\)/);
 assert.doesNotMatch(page('Study/Session'),/\/join`" method="post"/);
});
test('owner progress is paginated aggregate-only without study links or private notes',()=>{
 const s=page('Collections/Progress');assert.match(s,/members.data/);assert.match(s,/ManagementPager :page="members"/);assert.match(s,/latest_percent/);assert.doesNotMatch(s,/member\.note|word\.note|\/study\?mode/);
});
test('admin UI has no role assignment or destructive account/collection deletion, and does not render notes',()=>{
 const users=page('Admin/Users');assert.match(users,/!user.is_admin&&user.id!==page.props.auth\?\.user\?\.id/);assert.doesNotMatch(users,/v-model="[^"\n]*is_admin|form.delete/);
 const collection=page('Admin/Collection');assert.match(collection,/word.term/);assert.match(collection,/word.definition/);assert.doesNotMatch(collection,/word.note|word\.personal|form.delete/);assert.match(collection,/sharing.delete\(`\/admin\/collections\/\$\{props.collection.id\}\/sharing`/);
});
