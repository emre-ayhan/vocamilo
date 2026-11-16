<?php

namespace App\Http\Controllers;

use App\Models\Collection;
use App\Models\StudySession;
use App\Models\User;
use App\Support\LiteralSearch;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CollectionMembersController extends Controller
{
    private static function eligible($q, int $owner): void
    {
        $q->where('collections.user_id', $owner)->whereNotNull('collections.share_uuid')
            ->where('collection_members.progress_consent_version', Collection::PROGRESS_CONSENT_VERSION)->whereNotNull('collection_members.progress_consented_at')
            ->where('users.admin_disclosure_version', 1)->whereNotNull('users.admin_disclosure_accepted_at')->whereNull('users.suspended_at')
            ->whereNotExists(fn ($b) => $b->selectRaw('1')->from('collection_blocks')->whereColumn('collection_blocks.collection_id', 'collections.id')->whereColumn('collection_blocks.user_id', 'users.id'));
    }

    private static function related(int $owner)
    {
        return User::where('users.id', '!=', $owner)->where(fn ($q) => $q
            ->whereExists(fn ($m) => $m->selectRaw('1')->from('collection_members')->join('collections', 'collections.id', '=', 'collection_members.collection_id')->whereColumn('collection_members.user_id', 'users.id')->where('collections.user_id', $owner))
            ->orWhereExists(fn ($m) => $m->selectRaw('1')->from('collection_blocks')->join('collections', 'collections.id', '=', 'collection_blocks.collection_id')->whereColumn('collection_blocks.user_id', 'users.id')->where('collections.user_id', $owner)));
    }

    public static function homeProps(Request $r): array
    {
        $d = $r->validate(['member_q' => 'nullable|string|max:100', 'members_page' => 'nullable|integer|min:1', 'member' => 'nullable|integer|min:1', 'member_collection' => 'nullable|integer|min:1']);
        $owner = $r->user()->id;
        $search = trim($d['member_q'] ?? '');
        $members = self::related($owner)->when($search !== '', fn ($q) => $q->where(fn ($q) => $q
            ->whereRaw("LOWER(users.name) LIKE LOWER(?) ESCAPE '!'", [LiteralSearch::pattern($search)])
            ->orWhere(fn ($q) => $q->whereRaw("LOWER(users.email) LIKE LOWER(?) ESCAPE '!'", [LiteralSearch::pattern($search)])
                ->whereExists(function ($m) use ($owner) {
                    $m->selectRaw('1')->from('collection_members')->join('collections', 'collections.id', '=', 'collection_members.collection_id')->whereColumn('collection_members.user_id', 'users.id');
                    self::eligible($m, $owner);
                }))))->orderBy('users.name')->orderBy('users.id')->paginate(15, ['users.id', 'users.name'], 'members_page')->withQueryString();
        $selected = isset($d['member']) ? self::related($owner)->find($d['member'], ['users.id', 'users.name']) : null;
        $detail = null;
        if ($selected) {
            $collections = Collection::where('user_id', $owner)->where(fn ($q) => $q->whereHas('members', fn ($m) => $m->where('users.id', $selected->id))->orWhereHas('blockedUsers', fn ($m) => $m->where('users.id', $selected->id)))->orderBy('id')->get();
            $items = $collections->map(function ($c) use ($selected, $owner) {
                $eligible = $c->members()->where('users.id', $selected->id)->join('collections', 'collections.id', '=', 'collection_members.collection_id');
                self::eligible($eligible, $owner);

                return ['id' => $c->id, 'title' => $c->title, 'blocked' => $c->isBlocked($selected->id), 'is_member' => $c->members()->where('users.id', $selected->id)->exists(), 'can_view_progress' => $eligible->exists()];
            });
            $chosen = isset($d['member_collection']) ? $items->firstWhere('id', (int) $d['member_collection']) : $items->first();
            $detail = ['id' => $selected->id, 'name' => $selected->name, 'collections' => $items, 'selected_collection_id' => $chosen['id'] ?? null, 'summary' => $chosen && $chosen['can_view_progress'] ? StudySession::summaryFor($collections->firstWhere('id', $chosen['id']), $selected->id) : null];
        }

        return ['members' => $members, 'memberFilters' => ['q' => $search], 'selectedMember' => $detail];
    }

    private function data(Request $r): array
    {
        return $r->validate(['action' => 'required|in:remove,block,unblock', 'scope' => 'required|in:selected,all', 'collection_id' => 'required_if:scope,selected|nullable|integer|min:1']);
    }

    private function snapshot(Request $r, int $user, array $d, bool $lock = false): array
    {
        abort_if($user === $r->user()->id, 422);
        self::related($r->user()->id)->findOrFail($user);
        $query = Collection::where('user_id', $r->user()->id)->orderBy('id');
        if ($lock) {
            $query->lockForUpdate();
        }
        $collections = $query->get();
        if ($d['scope'] === 'selected') {
            abort_unless($collections->contains('id', $d['collection_id']), 404);
        }
        $state = $collections->map(fn ($c) => ['id' => $c->id, 'title' => $c->title, 'member' => $c->members()->where('users.id', $user)->exists(), 'blocked' => $c->isBlocked($user), 'membership' => DB::table('collection_members')->where('collection_id', $c->id)->where('user_id', $user)->first(), 'block_record' => DB::table('collection_blocks')->where('collection_id', $c->id)->where('user_id', $user)->first()])->all();
        if ($d['scope'] === 'selected') {
            $selected = collect($state)->firstWhere('id', (int) $d['collection_id']);
            abort_unless($selected && ($selected['member'] || $selected['blocked']), 404);
        }
        $ids = [];
        foreach ($state as $item) {
            if ($d['scope'] === 'selected' && $item['id'] != $d['collection_id']) {
                continue;
            }
            if (($d['action'] === 'remove' && $item['member']) || ($d['action'] === 'block' && ! $item['blocked']) || ($d['action'] === 'unblock' && $item['blocked'])) {
                $ids[] = $item['id'];
            }
        }

        return ['ids' => $ids, 'hash' => hash('sha256', json_encode($state)), 'collections' => $collections];
    }

    public function preview(Request $r, int $user)
    {
        $d = $this->data($r);
        $s = $this->snapshot($r, $user, $d);
        $nonce = (string) Str::uuid();
        Cache::put('member-action-pending:'.$nonce, $r->user()->id, now()->addMinutes(10));
        $token = Crypt::encryptString(json_encode(['nonce' => $nonce, 'owner' => $r->user()->id, 'user' => $user, 'data' => $d, 'ids' => $s['ids'], 'hash' => $s['hash'], 'expires' => now()->addMinutes(10)->timestamp]));

        return response()->json(['token' => $token, 'count' => count($s['ids']), 'collection_ids' => $s['ids'], 'action' => $d['action'], 'scope' => $d['scope']]);
    }

    public function apply(Request $r, int $user)
    {
        $d = $this->data($r);
        $return = $r->validate(['token' => 'required|string|max:50000', 'member_q' => 'nullable|string|max:100', 'members_page' => 'nullable|integer|min:1', 'member_collection' => 'nullable|integer|min:1']);
        try {
            $token = json_decode(Crypt::decryptString($r->input('token')), true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            throw ValidationException::withMessages(['token' => __('Review the current scope and confirm again.')]);
        }
        DB::transaction(function () use ($r, $user, $d, $token) {
            User::whereKey($r->user()->id)->lockForUpdate()->firstOrFail();
            $s = $this->snapshot($r, $user, $d, true);
            if (($token['owner'] ?? null) !== $r->user()->id || ($token['user'] ?? null) !== $user || ($token['data'] ?? null) != $d || ($token['expires'] ?? 0) < now()->timestamp || ($token['ids'] ?? null) !== $s['ids'] || ($token['hash'] ?? null) !== $s['hash']) {
                throw ValidationException::withMessages(['token' => __('Review the current scope and confirm again.')]);
            }
            if (! isset($token['nonce']) || Cache::pull('member-action-pending:'.$token['nonce']) !== $r->user()->id) {
                throw ValidationException::withMessages(['token' => __('Review the current scope and confirm again.')]);
            }
            foreach ($s['collections']->whereIn('id', $s['ids']) as $c) {
                if ($d['action'] === 'block') {
                    $c->blockedUsers()->syncWithoutDetaching([$user]);
                }
                if ($d['action'] === 'unblock') {
                    $c->blockedUsers()->detach($user);
                } else {
                    $c->members()->detach($user);
                }
            }
        });

        return redirect('/collections?'.http_build_query(['tab' => 'members', 'member' => $user, 'member_collection' => $d['collection_id'] ?? $return['member_collection'] ?? null, 'member_q' => trim($return['member_q'] ?? ''), 'members_page' => $return['members_page'] ?? 1]))->with('success', __('Member access updated. Private notes and study history have been preserved.'));
    }
}
