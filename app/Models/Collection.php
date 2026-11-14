<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Exceptions\HttpResponseException;

class Collection extends Model
{
    public const PROGRESS_CONSENT_VERSION = 2;

    protected $fillable = ['title', 'description'];

    public function members()
    {
        return $this->belongsToMany(User::class, 'collection_members')->withPivot('progress_consented_at', 'progress_consent_version')->withTimestamps();
    }

    public function blockedUsers()
    {
        return $this->belongsToMany(User::class, 'collection_blocks')->withTimestamps();
    }

    public function isBlocked(int $userId): bool
    {
        return $this->blockedUsers()->where('users.id', $userId)->exists();
    }

    public static function accessibleTo(int $userId, int $id): self
    {
        $collection = self::whereKey($id)->where(fn ($q) => $q->where('user_id', $userId)->orWhere(fn ($q) => $q->whereNotNull('share_uuid')->whereDoesntHave('blockedUsers', fn ($q) => $q->where('users.id', $userId))->whereHas('members', fn ($q) => $q->where('users.id', $userId))))->firstOrFail();
        if ($collection->user_id !== $userId && ! $collection->members()->where('users.id', $userId)->wherePivotNotNull('progress_consented_at')->wherePivot('progress_consent_version', self::PROGRESS_CONSENT_VERSION)->exists()) {
            $url = '/collections/'.$id.'/consent';
            throw new HttpResponseException(request()->expectsJson()
                ? response()->json(['message' => 'Consent is required to share previous and future collection progress and allow owner email search.', 'needs_consent' => true, 'consent_url' => $url], 403)
                : redirect($url));
        }

        return $collection;
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function words()
    {
        return $this->belongsToMany(Word::class)->orderBy('words.id');
    }
}
