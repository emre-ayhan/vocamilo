<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Collection extends Model
{
    protected $fillable = ['title', 'description'];

    public function members()
    {
        return $this->belongsToMany(User::class, 'collection_members')->withTimestamps();
    }

    public static function accessibleTo(int $userId, int $id): self
    {
        return self::whereKey($id)->where(fn ($q) => $q->where('user_id', $userId)->orWhere(fn ($q) => $q->whereNotNull('share_uuid')->whereHas('members', fn ($q) => $q->where('users.id', $userId))))->firstOrFail();
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
