<?php

namespace Tests\Feature;

use App\Console\Commands\FirstAdministrator;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\Console\Tester\CommandTester;
use Tests\TestCase;

class AdminDisclosureTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function unconsented(): User
    {
        return User::factory()->create(['admin_disclosure_version' => null, 'admin_disclosure_accepted_at' => null]);
    }

    private function admin(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }

    private function collection(User $owner)
    {
        $c = $owner->collections()->create(['title' => 'Collection']);
        $c->forceFill(['share_uuid' => (string) Str::uuid()])->save();
        $w = $owner->words()->create(['term' => 'Word', 'definition' => 'Meaning', 'note' => 'OWNER_NOTE_SECRET']);
        $c->words()->attach($w);

        return [$c, $w];
    }

    public function test_unconsented_existing_user_cannot_read_any_authenticated_or_shared_payload_until_explicit_acceptance(): void
    {
        $u = $this->unconsented();
        [$c] = $this->collection($u);
        $this->actingAs($u);
        foreach (['/dashboard', '/words', '/collections/'.$c->id, '/s/'.$c->share_uuid] as $url) {
            $this->get($url)->assertRedirect('/account/disclosure')->assertDontSee('OWNER_NOTE_SECRET');
        }
        $this->getJson('/collections/'.$c->id.'/words')->assertStatus(409)->assertJsonPath('disclosure_required', true);
        $this->postJson('/words', ['term' => 'forged', 'definition' => 'write'])->assertStatus(409);
        $this->patchJson('/admin/users/'.$u->id, ['suspended' => true])->assertStatus(409);
        $this->assertDatabaseMissing('words', ['term' => 'forged']);
        $this->get('/account/disclosure')->assertOk()->assertInertia(fn (Assert $p) => $p->component('Account/Disclosure', false)->where('version', 1));
        $this->post('/account/disclosure', ['accepted' => false])->assertSessionHasErrors('accepted');
        $this->assertNull($u->fresh()->admin_disclosure_accepted_at);
        $this->post('/account/disclosure', ['accepted' => true])->assertRedirect();
        $this->assertSame(1, $u->fresh()->admin_disclosure_version);
        $this->assertNotNull($u->fresh()->admin_disclosure_accepted_at);
        $this->get('/dashboard')->assertOk();
    }

    public function test_version_alone_does_not_grant_access_and_decline_withdraws_without_deleting_data(): void
    {
        $u = User::factory()->create(['admin_disclosure_accepted_at' => null]);
        [$c] = $this->collection($u);
        $this->actingAs($u)->get('/dashboard')->assertRedirect('/account/disclosure');
        $this->post('/account/disclosure', ['accepted' => true])->assertRedirect();
        $this->delete('/account/disclosure')->assertRedirect('/login');
        $this->assertGuest();
        $this->assertNull($u->fresh()->admin_disclosure_version);
        $this->assertNull($u->fresh()->admin_disclosure_accepted_at);
        $this->assertDatabaseHas('collections', ['id' => $c->id]);
    }

    public function test_nonadmin_denied_and_admin_lists_only_consented_owners_without_private_notes(): void
    {
        $admin = $this->admin();
        $owner = User::factory()->create();
        $unconsented = $this->unconsented();
        [$visible,$word] = $this->collection($owner);
        [$hidden] = $this->collection($unconsented);
        $this->actingAs($owner)->get('/admin/collections')->assertForbidden();
        $this->assertDatabaseHas('admin_audit_logs', ['actor_id' => $owner->id, 'action' => 'denied_admin']);
        $this->actingAs($admin)->get('/admin/collections')->assertOk()->assertInertia(fn (Assert $p) => $p->component('Admin/Collections', false)->has('collections.data', 1)->where('collections.data.0.id', $visible->id));
        $this->get('/admin/collections/'.$visible->id)->assertOk()->assertInertia(fn (Assert $p) => $p->component('Admin/Collection', false)->missing('words.data.0.note')->missing('words.data.0.pivot')->where('words.data.0.term', 'Word'))->assertDontSee('OWNER_NOTE_SECRET');
        $this->assertDatabaseHas('admin_audit_logs', ['actor_id' => $admin->id, 'collection_id' => $visible->id, 'action' => 'collection_view_requested']);
        $this->get('/admin/collections/'.$hidden->id)->assertNotFound();
        $this->get('/admin/collections/'.$visible->id.'/export')->assertNotFound();
        $this->getJson('/collections/'.$visible->id.'/words/'.$word->id.'/note')->assertNotFound();
        $this->getJson('/collections/'.$visible->id.'/export')->assertNotFound();
        $this->getJson('/collections/'.$visible->id)->assertNotFound();
        $this->postJson('/collections/'.$visible->id.'/sessions', ['mode' => 'study'])->assertNotFound();
        $this->putJson('/collections/'.$visible->id.'/words/'.$word->id.'/note', ['note' => 'forged'])->assertNotFound();
        $this->get('/words')->assertDontSee('OWNER_NOTE_SECRET');
        $this->get('/admin/users')->assertInertia(fn (Assert $p) => $p->has('users.data', 2));
        $this->patch('/admin/users/'.$unconsented->id, ['suspended' => true])->assertNotFound();
    }

    public function test_admin_can_suspend_and_reactivate_but_cannot_promote_or_suspend_admins(): void
    {
        $admin = $this->admin();
        $user = User::factory()->create();
        $this->actingAs($admin);
        $this->patch('/admin/users/'.$user->id, ['suspended' => true, 'is_admin' => true])->assertRedirect();
        $this->assertNotNull($user->fresh()->suspended_at);
        $this->assertFalse($user->fresh()->is_admin);
        $this->actingAs($user->fresh())->get('/dashboard')->assertForbidden();
        $this->assertGuest();
        $this->actingAs($admin)->patch('/admin/users/'.$user->id, ['suspended' => false])->assertRedirect();
        $this->assertNull($user->fresh()->suspended_at);
        $this->patchJson('/admin/users/'.$admin->id, ['suspended' => true])->assertUnprocessable();
        $this->assertNull($admin->fresh()->suspended_at);
        $this->assertDatabaseHas('admin_audit_logs', ['action' => 'user_suspended', 'target_user_id' => $user->id]);
    }

    public function test_admin_collection_mutations_are_audited_and_do_not_touch_words_or_notes(): void
    {
        $admin = $this->admin();
        $owner = User::factory()->create();
        [$c,$w] = $this->collection($owner);
        $member = User::factory()->create();
        $c->members()->attach($member);
        $this->actingAs($admin);
        $this->patch('/admin/collections/'.$c->id, ['title' => 'Updated', 'description' => 'Metadata', 'note' => 'forged'])->assertRedirect();
        $this->assertSame('OWNER_NOTE_SECRET', $w->fresh()->note);
        $this->assertSame('Updated', $c->fresh()->title);
        $this->delete('/admin/collections/'.$c->id.'/sharing')->assertRedirect();
        $this->assertNull($c->fresh()->share_uuid);
        $this->assertSame(0, $c->members()->count());
        $this->assertDatabaseHas('admin_audit_logs', ['action' => 'collection_updated', 'collection_id' => $c->id]);
        $this->assertDatabaseHas('admin_audit_logs', ['action' => 'collection_sharing_disabled', 'collection_id' => $c->id]);
        $this->delete('/admin/collections/'.$c->id)->assertStatus(405);
        $this->assertStringNotContainsString('OWNER_NOTE_SECRET', DB::table('admin_audit_logs')->get()->toJson());
    }

    public function test_withdrawal_immediately_removes_admin_visibility_and_external_return_is_rejected(): void
    {
        $admin = $this->admin();
        $owner = User::factory()->create();
        [$c] = $this->collection($owner);
        $this->actingAs($owner)->delete('/account/disclosure')->assertRedirect();
        $this->actingAs($admin)->get('/admin/collections/'.$c->id)->assertNotFound();
        $this->actingAs($owner)->withSession(['disclosure_return_to' => 'https://evil.example'])->post('/account/disclosure', ['accepted' => true])->assertRedirect('/dashboard');
    }

    public function test_registration_never_assigns_admin_or_auto_accepts_disclosure(): void
    {
        $this->post('/register', ['name' => 'New', 'email' => 'new-admin-check@example.com', 'password' => 'strong-password-123', 'password_confirmation' => 'strong-password-123', 'is_admin' => true, 'admin_disclosure_version' => 1])->assertRedirect();
        $u = User::where('email', 'new-admin-check@example.com')->firstOrFail();
        $this->assertFalse($u->is_admin);
        $this->assertNull($u->admin_disclosure_accepted_at);
        $this->get('/dashboard')->assertRedirect('/account/disclosure');
    }

    public function test_first_admin_command_requires_confirmation_and_refuses_second_grant(): void
    {
        $user = User::factory()->create();
        $command = app(FirstAdministrator::class);
        $command->setLaravel(app());
        $tester = new CommandTester($command);
        $this->assertSame(1, $tester->execute(['email' => $user->email], ['interactive' => false]));
        $tester->setInputs(['no']);
        $this->assertSame(1, $tester->execute(['email' => $user->email], ['interactive' => true]));
        $this->assertFalse($user->fresh()->is_admin);
        $tester->setInputs(['yes']);
        $this->assertSame(0, $tester->execute(['email' => $user->email], ['interactive' => true]));
        $this->assertTrue($user->fresh()->is_admin);
        $other = User::factory()->create();
        $this->assertSame(1, $tester->execute(['email' => $other->email], ['interactive' => true]));
        $this->assertFalse($other->fresh()->is_admin);
    }

    public function test_admin_ordinary_shared_access_is_audited_and_cannot_bypass_owner_consent(): void
    {
        $admin = $this->admin();
        $owner = User::factory()->create();
        [$c] = $this->collection($owner);
        $this->actingAs($admin)->get('/s/'.$c->share_uuid)->assertOk()->assertDontSee('OWNER_NOTE_SECRET');
        $this->assertDatabaseHas('admin_audit_logs', ['actor_id' => $admin->id, 'collection_id' => $c->id, 'action' => 'collection_learner_request']);
        $this->assertStringNotContainsString($c->share_uuid, DB::table('admin_audit_logs')->get()->toJson());
        $c->members()->attach($admin, ['progress_consented_at' => now()]);
        $this->get('/collections')->assertInertia(fn (Assert $p) => $p->has('collections', 1));
        $owner->forceFill(['admin_disclosure_version' => null, 'admin_disclosure_accepted_at' => null])->save();
        $this->get('/collections')->assertInertia(fn (Assert $p) => $p->has('collections', 0));
        $this->get('/dashboard')->assertInertia(fn (Assert $p) => $p->where('stats.collections', 0)->where('stats.joined', 0));
        $this->get('/s/'.$c->share_uuid)->assertNotFound();
        $this->getJson('/s/'.$c->share_uuid.'/words')->assertNotFound();
        $this->get('/s/'.$c->share_uuid.'/study')->assertNotFound();
        $this->get('/collections/999999')->assertNotFound();
        $this->assertDatabaseHas('admin_audit_logs', ['actor_id' => $admin->id, 'collection_id' => 999999, 'action' => 'collection_learner_request']);
        $this->post('/logout');
        $this->get('/s/'.$c->share_uuid)->assertOk();
    }

    public function test_pending_join_can_be_cancelled_before_disclosure_and_cannot_resurrect_on_accept(): void
    {
        $owner = $this->unconsented();
        [$c] = $this->collection($owner);
        foreach ([false, true] as $isAdmin) {
            $viewer = $this->unconsented();
            $viewer->forceFill(['is_admin' => $isAdmin])->save();
            $this->actingAs($viewer)->withSession(['pending_collection_uuid' => $c->share_uuid, 'pending_collection_progress_consent' => true, 'pending_collection_consent_expires_at' => now()->addMinutes(30)->timestamp])
                ->post('/s/'.$c->share_uuid.'/join/cancel')->assertRedirect('/s/'.$c->share_uuid)->assertSessionMissing('pending_collection_uuid')->assertSessionMissing('pending_collection_progress_consent');
            $this->post('/account/disclosure', ['accepted' => true])->assertRedirect('/dashboard');
            $this->assertFalse($c->members()->where('users.id', $viewer->id)->exists());
        }
    }
}
