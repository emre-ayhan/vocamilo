<?php

namespace App\Http\Controllers;

use App\Models\Collection;
use App\Models\StudySession;
use App\Models\User;
use App\Support\AdminAudit;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;

class CollectionController extends Controller
{
    public function index(Request $r)
    {
        return Inertia::render('Collections/Index', [...CollectionMembersController::homeProps($r), 'pools' => $r->user()->wordPools()->orderBy('name')->get(['id', 'name', 'language', 'text_direction']), 'collections' => Collection::where(fn ($q) => $q->where('user_id', $r->user()->id)->orWhere(fn ($q) => $q->whereNotNull('share_uuid')->whereDoesntHave('blockedUsers', fn ($q) => $q->where('users.id', $r->user()->id))->whereHas('members', fn ($q) => $q->where('users.id', $r->user()->id))))->when($r->user()->is_admin, fn ($q) => $q->where(fn ($q) => $q->where('user_id', $r->user()->id)->orWhereHas('user', fn ($q) => $q->where('admin_disclosure_version', 1)->whereNotNull('admin_disclosure_accepted_at'))))->withCount('words')->latest()->get()->each(function ($c) use ($r) {
            if ($r->user()->is_admin) {
                AdminAudit::record($r->user()->id, 'collection_index_item', $c->id);
            }
        })->map(fn ($c) => ['id' => $c->id, 'title' => $c->title, 'description' => $c->description, 'share_uuid' => $c->share_uuid, 'words_count' => $c->words_count, 'isOwner' => $c->user_id === $r->user()->id])]);
    }

    public function store(Request $r)
    {
        $data = $this->data($r);
        $c = DB::transaction(function () use ($r, $data) {
            User::whereKey($r->user()->id)->lockForUpdate()->firstOrFail();

            return $r->user()->collections()->create($data);
        });

        return redirect('/collections/'.$c->id)->with('success', __('Collection created.'));
    }

    public function show(Request $r, int $collection)
    {
        $c = Collection::accessibleTo($r->user()->id, $collection);
        $owner = $c->user_id === $r->user()->id;
        $poolIds = $owner ? $this->poolIds($r) : [];

        return Inertia::render('Collections/Show', ['collection' => $this->payload($c, false, $r->user()->id, true), 'shared' => false, 'isOwner' => $owner, 'member' => ! $owner, 'summary' => StudySession::summaryFor($c, $r->user()->id), ...($owner ? ['sharing' => $this->sharingData($c)] : []), 'pools' => $owner ? $r->user()->wordPools()->withCount('words')->orderBy('name')->get() : [], 'filters' => ['pool_ids' => $poolIds], 'availableWords' => $owner ? $r->user()->words()->whereHas('pools', fn ($q) => $q->whereIn('word_pools.id', $poolIds))->whereDoesntHave('collections', fn ($q) => $q->where('collections.id', $c->id))->select('id', 'term', 'definition', 'text_direction')->orderBy('term')->get() : []]);
    }

    public function words(Request $r, int $collection)
    {
        $c = Collection::accessibleTo($r->user()->id, $collection);

        return response()->json($this->wordPage($c, $r->user()->id));
    }

    public function publicWords(string $uuid)
    {
        return response()->json($this->wordPage($this->shared($uuid), null));
    }

    public function join(Request $r, string $uuid)
    {
        $c = $this->shared($uuid, true);
        $r->validate(['progress_consent' => 'required|accepted', 'consent_version' => 'required|integer|in:'.Collection::PROGRESS_CONSENT_VERSION]);
        if (! $r->user()) {
            $r->session()->put('pending_collection_uuid', $uuid);
            $r->session()->put('pending_collection_progress_consent', true);
            $r->session()->put('pending_collection_consent_version', Collection::PROGRESS_CONSENT_VERSION);
            $r->session()->put('pending_collection_consent_expires_at', now()->addMinutes(30)->timestamp);

            return redirect('/login');
        }
        if ((int) $r->user()->admin_disclosure_version !== 1 || ! $r->user()->admin_disclosure_accepted_at) {
            $r->session()->put('pending_collection_uuid', $uuid);
            $r->session()->put('pending_collection_progress_consent', true);
            $r->session()->put('pending_collection_consent_version', Collection::PROGRESS_CONSENT_VERSION);
            $r->session()->put('pending_collection_consent_expires_at', now()->addMinutes(30)->timestamp);

            return redirect('/account/disclosure');
        }
        DB::transaction(function () use ($c, $r, $uuid) {
            $locked = Collection::whereKey($c->id)->where('share_uuid', $uuid)->lockForUpdate()->firstOrFail();
            abort_if($locked->isBlocked($r->user()->id), 404);
            if ($locked->user_id !== $r->user()->id) {
                $locked->members()->syncWithoutDetaching([$r->user()->id => ['progress_consented_at' => now(), 'progress_consent_version' => Collection::PROGRESS_CONSENT_VERSION]]);
            }
        });

        return redirect('/collections/'.$c->id);
    }

    public function getNote(Request $r, int $collection, int $word)
    {
        $c = Collection::accessibleTo($r->user()->id, $collection);
        $w = $c->words()->findOrFail($word);
        $note = $w->user_id === $r->user()->id ? $w->note : DB::table('user_word_notes')->where('user_id', $r->user()->id)->where('word_id', $word)->value('note');

        return response()->json(['note' => $note]);
    }

    public function note(Request $r, int $collection, int $word)
    {
        $c = Collection::accessibleTo($r->user()->id, $collection);
        $w = $c->words()->findOrFail($word);
        $d = $r->validate(['note' => 'present|nullable|string|max:5000']);
        DB::transaction(function () use ($r, $c, $w, $word, $d) {
            User::whereKey($r->user()->id)->lockForUpdate()->firstOrFail();
            if ($c->user_id === $r->user()->id) {
                $w->update(['note' => $d['note'] ?? null]);
            } else {
                DB::table('user_word_notes')->upsert([['user_id' => $r->user()->id, 'word_id' => $word, 'note' => $d['note'] ?? null, 'created_at' => now(), 'updated_at' => now()]], ['user_id', 'word_id'], ['note', 'updated_at']);
            }

        });

        if ($r->expectsJson()) {
            return response()->json(['note' => $d['note'] ?? null]);
        }

        return back()->with('success', __('Note saved.'));
    }

    public function update(Request $r, int $collection)
    {
        $this->owned($r, $collection)->update($this->data($r));

        return back()->with('success', __('Collection updated.'));
    }

    public function destroy(Request $r, int $collection)
    {
        DB::transaction(function () use ($r, $collection) {
            User::whereKey($r->user()->id)->lockForUpdate()->firstOrFail();
            $this->owned($r, $collection)->delete();
        });

        return redirect('/collections')->with('success', __('Collection deleted. Your words remain in the pool.'));
    }

    public function addWords(Request $r, int $collection)
    {
        $d = $r->validate(['word_ids' => 'required|array|max:500', 'word_ids.*' => 'required|integer|distinct']);
        DB::transaction(function () use ($r, $collection, $d) {
            User::whereKey($r->user()->id)->lockForUpdate()->firstOrFail();
            $c = $this->owned($r, $collection);
            $poolIds = $this->poolIds($r);
            $ids = $r->user()->words()->whereHas('pools', fn ($q) => $q->whereIn('word_pools.id', $poolIds))->whereIn('id', $d['word_ids'])->pluck('id');
            abort_if($ids->count() !== count($d['word_ids']), 422, __('Choose words from your own pool.'));
            $existing = $c->words()->pluck('words.id');
            $c->words()->syncWithoutDetaching($ids->diff($existing)->mapWithKeys(fn ($id) => [$id => ['added_at' => now()]])->all());
        });

        return back()->with('success', __('Words added.'));
    }

    public function removeWord(Request $r, int $collection, int $word)
    {
        $this->owned($r, $collection);
        $d = $r->validate(['words_page' => 'sometimes|integer|min:1']);
        $poolIds = $r->has('pool_ids') ? $this->poolIds($r) : null;
        $page = DB::transaction(function () use ($r, $collection, $word, $d) {
            User::whereKey($r->user()->id)->lockForUpdate()->firstOrFail();
            $c = $this->owned($r, $collection);
            $c->words()->detach($word);

            return min((int) ($d['words_page'] ?? 1), max(1, (int) ceil($c->words()->count() / 15)));
        });
        $query = ['words_page' => $page];
        if ($poolIds !== null) {
            $query['pool_ids'] = $poolIds;
        }

        return redirect('/collections/'.$collection.'?'.http_build_query($query));
    }

    public function share(Request $r, int $collection)
    {
        $c = $this->owned($r, $collection);
        if (! $c->share_uuid) {
            $c->share_uuid = (string) Str::uuid();
            $c->save();
        }

        return back()->with('success', __('Sharing enabled. Personal notes stay private.'));
    }

    public function unshare(Request $r, int $collection)
    {
        $c = $this->owned($r, $collection);
        DB::transaction(function () use ($c) {
            $c = Collection::whereKey($c->id)->lockForUpdate()->firstOrFail();
            $c->share_uuid = null;
            $c->save();
            $c->members()->detach();
        });

        return back()->with('success', __('Sharing disabled. The previous link no longer works.'));
    }

    public function sharingAccess(Request $r, int $collection)
    {
        $c = $this->owned($r, $collection);
        $d = $r->validate(['access' => 'required|in:guests,signed_in']);
        $c->share_access = $d['access'];
        $c->save();

        return back()->with('success', __('Sharing access updated.'));
    }

    public function rotateSharing(Request $r, int $collection)
    {
        $c = $this->owned($r, $collection);
        DB::transaction(function () use ($c) {
            $locked = Collection::whereKey($c->id)->lockForUpdate()->firstOrFail();
            abort_unless($locked->share_uuid, 422, __('Enable sharing before replacing the link.'));
            $locked->share_uuid = (string) Str::uuid();
            $locked->save();
        });

        return back()->with('success', __('Sharing link replaced. Existing members keep access.'));
    }

    public function removeMember(Request $r, int $collection, int $user)
    {
        $c = $this->owned($r, $collection);
        abort_if($user === $c->user_id, 422);
        DB::transaction(function () use ($c, $user) {
            $locked = Collection::whereKey($c->id)->lockForUpdate()->firstOrFail();
            $locked->members()->detach($user);
        });

        return back()->with('success', __('Member removed. They can join again using the link.'));
    }

    public function blockMember(Request $r, int $collection, int $user)
    {
        $c = $this->owned($r, $collection);
        abort_if($user === $c->user_id, 422);
        DB::transaction(function () use ($c, $user) {
            $locked = Collection::whereKey($c->id)->lockForUpdate()->firstOrFail();
            abort_unless($locked->members()->where('users.id', $user)->exists() || $locked->isBlocked($user), 404);
            $locked->blockedUsers()->syncWithoutDetaching([$user]);
            $locked->members()->detach($user);
        });

        return back()->with('success', __('Account blocked from this collection.'));
    }

    public function unblockMember(Request $r, int $collection, int $user)
    {
        $c = $this->owned($r, $collection);
        DB::transaction(function () use ($c, $user) {
            Collection::whereKey($c->id)->lockForUpdate()->firstOrFail()->blockedUsers()->detach($user);
        });

        return back()->with('success', __('Account unblocked. They can join again using the link.'));
    }

    public function sharingMembers(Request $r, int $collection)
    {
        $this->owned($r, $collection);
        $d = $r->validate(['q' => 'nullable|string|max:100', 'participants_page' => 'nullable|integer|min:1', 'blocked_page' => 'nullable|integer|min:1', 'member' => 'nullable|integer|min:1']);

        return redirect('/collections?'.http_build_query(['tab' => 'members', 'member_collection' => $collection, 'member_q' => trim($d['q'] ?? ''), 'member' => $d['member'] ?? null, 'members_page' => $d['participants_page'] ?? 1]));
    }

    private function sharingData(Collection $c): array
    {
        return ['access' => $c->share_access, 'participants_count' => $c->members()->count(), 'blocked_count' => $c->blockedUsers()->count()];
    }

    public function publicShow(string $uuid)
    {
        $c = $this->shared($uuid);

        return Inertia::render('Collections/Show', ['collection' => $this->payload($c, true, null, true), 'shared' => true, 'isOwner' => false, 'member' => false, 'availableWords' => []]);
    }

    public function study(Request $r, int $collection)
    {
        return $this->session($r, Collection::accessibleTo($r->user()->id, $collection), false);
    }

    public function publicStudy(Request $r, string $uuid)
    {
        return $this->session($r, $this->shared($uuid), true);
    }

    private function session(Request $r, Collection $c, bool $shared)
    {
        $mode = $r->query('mode', 'flashcards');
        abort_unless(in_array($mode, ['flashcards', 'study', 'test'], true), 422);
        $p = $this->payload($c, $shared, $shared || $mode === 'test' ? null : $r->user()->id);

        return Inertia::render('Study/Session', ['collection' => $p, 'words' => $p['words'], 'mode' => $mode, 'shared' => $shared, 'settings' => []]);
    }

    private function owned(Request $r, int $id): Collection
    {
        return $r->user()->collections()->findOrFail($id);
    }

    private function shared(string $uuid, bool $joining = false): Collection
    {
        abort_unless(Str::isUuid($uuid), 404);

        $c = Collection::where('share_uuid', $uuid)->firstOrFail();
        $viewer = request()->user();
        abort_if($viewer && $c->isBlocked($viewer->id), 404);
        if (! $viewer && $c->share_access === 'signed_in' && ! $joining) {
            throw new AuthenticationException;
        }

        return $c;
    }

    private function poolIds(Request $r): array
    {
        $d = $r->validate(['pool_ids' => 'sometimes|array|max:100', 'pool_ids.*' => 'required|integer|distinct|min:1']);
        $owned = $r->user()->wordPools()->pluck('id');
        if (! array_key_exists('pool_ids', $d)) {
            return $owned->all();
        }
        abort_unless(collect($d['pool_ids'])->diff($owned)->isEmpty(), 404);

        return $d['pool_ids'];
    }

    private function data(Request $r): array
    {
        return $r->validate(['title' => 'required|string|max:150', 'description' => 'nullable|string|max:2000', 'voice_language' => ['nullable', 'string', 'max:35', 'regex:/^[a-zA-Z]{2,8}(?:-[a-zA-Z0-9]{1,8})*$/']]);
    }

    private function wordData(Collection $c, $words, ?int $learner): array
    {
        $notes = $learner && $learner !== $c->user_id ? DB::table('user_word_notes')->where('user_id', $learner)->pluck('note', 'word_id') : collect();

        return $words->map(function ($w) use ($c, $learner, $notes) {
            $row = $w->only('id', 'term', 'definition', 'text_direction');
            if ($learner) {
                $row['note'] = $learner === $c->user_id ? $w->note : ($notes[$w->id] ?? null);
            }

            return $row;
        })->values()->all();
    }

    private function wordPage(Collection $c, ?int $learner): array
    {
        $d = request()->validate(['words_page' => 'sometimes|integer|min:1']);
        $total = $c->words()->count();
        $last = max(1, (int) ceil($total / 15));
        $current = min((int) ($d['words_page'] ?? 1), $last);
        // Keep newest-added ordering and a unique ID tie-breaker for legacy rows.
        $words = $c->words()->reorder()->select('words.*')->selectRaw("COALESCE(collection_word.added_at, '1970-01-01 00:00:00') as membership_added_at")->orderByDesc('membership_added_at')->orderByDesc('words.id')->offset(($current - 1) * 15)->limit(15)->get();

        return ['data' => $this->wordData($c, $words, $learner), 'current_page' => $current, 'last_page' => $last, 'per_page' => 15, 'total' => $total, 'from' => $total ? ($current - 1) * 15 + 1 : null, 'to' => $total ? min($current * 15, $total) : null];
    }

    private function payload(Collection $c, bool $public, ?int $learner = null, bool $paginate = false): array
    {
        $page = $paginate ? $this->wordPage($c, $learner) : null;

        return ['id' => $public ? null : $c->id, 'title' => $c->title, 'description' => $c->description, 'voice_language' => $c->voice_language, 'share_uuid' => $c->share_uuid, 'words' => $paginate ? $page['data'] : $this->wordData($c, $c->words()->get(), $learner), 'words_count' => $c->words()->count(), 'word_pagination' => $page ? array_diff_key($page, ['data' => true]) : null];
    }
}
