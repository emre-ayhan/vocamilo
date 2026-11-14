<?php

namespace App\Http\Controllers;

use App\Http\Middleware\RequireAccountDisclosure;
use App\Models\Collection;
use App\Models\User;
use App\Support\AdminAudit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class AdminController extends Controller
{
    private function consentedUsers()
    {
        return User::where('admin_disclosure_version', RequireAccountDisclosure::VERSION)->whereNotNull('admin_disclosure_accepted_at');
    }

    private function consentedCollections()
    {
        return Collection::whereHas('user', fn ($q) => $q->where('admin_disclosure_version', RequireAccountDisclosure::VERSION)->whereNotNull('admin_disclosure_accepted_at'));
    }

    private function search(Request $r): string
    {
        return trim($r->validate(['q' => 'nullable|string|max:100', 'page' => 'nullable|integer|min:1'])['q'] ?? '');
    }

    public function users(Request $r)
    {
        $q = $this->search($r);
        $users = $this->consentedUsers()->when($q !== '', fn ($s) => $s->where(fn ($s) => $s->where('name', 'like', '%'.$q.'%')->orWhere('email', 'like', '%'.$q.'%')))->orderBy('id')->paginate(15, ['id', 'name', 'email', 'is_admin', 'suspended_at', 'created_at'])->withQueryString();
        AdminAudit::record($r->user()->id, 'users_list');

        return Inertia::render('Admin/Users', ['users' => $users, 'filters' => ['q' => $q]]);
    }

    public function updateUser(Request $r, int $user)
    {
        AdminAudit::record($r->user()->id, 'user_change_requested', null, $user);
        $d = $r->validate(['suspended' => 'required|boolean']);
        DB::transaction(function () use ($r, $user, $d) {
            $target = $this->consentedUsers()->whereKey($user)->lockForUpdate()->firstOrFail();
            if ($target->is_admin || $target->id === $r->user()->id) {
                abort(422, __('Administrator accounts cannot be suspended here.'));
            }
            $target->forceFill(['suspended_at' => $d['suspended'] ? now() : null])->save();
            AdminAudit::record($r->user()->id, $d['suspended'] ? 'user_suspended' : 'user_reactivated', null, $user);
        });

        return back()->with('success', __('Account updated.'));
    }

    public function collections(Request $r)
    {
        $q = $this->search($r);
        $collections = $this->consentedCollections()->when($q !== '', fn ($s) => $s->where('title', 'like', '%'.$q.'%'))->select('id', 'title', 'description', 'user_id', 'share_uuid')->withCount('words')->orderBy('id')->paginate(15)->withQueryString();
        AdminAudit::record($r->user()->id, 'collections_list');
        foreach ($collections as $c) {
            AdminAudit::record($r->user()->id, 'collection_list_item', $c->id);
        }

        return Inertia::render('Admin/Collections', ['collections' => $collections, 'filters' => ['q' => $q]]);
    }

    public function collection(Request $r, int $collection)
    {
        AdminAudit::record($r->user()->id, 'collection_view_requested', $collection);
        $c = $this->consentedCollections()->findOrFail($collection);
        $r->validate(['page' => 'nullable|integer|min:1']);
        $words = $c->words()->paginate(15, ['words.id', 'words.term', 'words.definition'])->through(fn ($w) => $w->only('id', 'term', 'definition'));

        return Inertia::render('Admin/Collection', ['collection' => $c->only('id', 'title', 'description', 'share_uuid'), 'words' => $words]);
    }

    public function updateCollection(Request $r, int $collection)
    {
        AdminAudit::record($r->user()->id, 'collection_edit_requested', $collection);
        $c = $this->consentedCollections()->findOrFail($collection);
        $d = $r->validate(['title' => 'required|string|max:150', 'description' => 'nullable|string|max:2000']);
        DB::transaction(function () use ($c, $d, $r) {
            $c->update($d);
            AdminAudit::record($r->user()->id, 'collection_updated', $c->id);
        });

        return back()->with('success', __('Collection updated.'));
    }

    public function disableSharing(Request $r, int $collection)
    {
        AdminAudit::record($r->user()->id, 'collection_disable_requested', $collection);
        DB::transaction(function () use ($r, $collection) {
            $c = $this->consentedCollections()->whereKey($collection)->lockForUpdate()->firstOrFail();
            $c->share_uuid = null;
            $c->save();
            $c->members()->detach();
            AdminAudit::record($r->user()->id, 'collection_sharing_disabled', $c->id);
        });

        return back()->with('success', __('Sharing disabled.'));
    }
}
