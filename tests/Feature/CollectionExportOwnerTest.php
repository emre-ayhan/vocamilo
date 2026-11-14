<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class CollectionExportOwnerTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_owner_can_export_regardless_of_membership_or_admin_status(): void
    {
        $owner = User::factory()->create();
        $collection = $owner->collections()->create(['title' => 'Shared']);
        $collection->forceFill(['share_uuid' => (string) Str::uuid()])->save();
        $word = $owner->words()->create(['term' => '=danger', 'definition' => '+formula', 'note' => '@owner']);
        $collection->words()->attach($word);
        $url = '/collections/'.$collection->id.'/export';
        $this->get($url)->assertRedirect('/login');
        $this->getJson($url)->assertUnauthorized();
        foreach (['consented', 'unconsented', 'blocked', 'former', 'admin', 'stranger'] as $role) {
            $user = User::factory()->create(['is_admin' => $role === 'admin']);
            if ($role !== 'stranger') {
                $collection->members()->attach($user, ['progress_consent_version' => 2, 'progress_consented_at' => $role === 'unconsented' ? null : now()]);
            }
            if ($role === 'blocked') {
                $collection->blockedUsers()->attach($user);
            }
            if ($role === 'former') {
                $collection->members()->detach($user);
            }
            $this->actingAs($user)->getJson($url)->assertNotFound();
        }
        $response = $this->actingAs($owner)->get($url)->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('private', $response->headers->get('Cache-Control'));
        $csv = $response->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        foreach (["'=danger", "'+formula", "'@owner"] as $cell) {
            $this->assertStringContainsString($cell, $csv);
        }
        $collection->forceFill(['share_uuid' => null])->save();
        $this->get($url)->assertOk();
        $owner->forceFill(['is_admin' => true])->save();
        $this->get($url)->assertOk();
    }
}
