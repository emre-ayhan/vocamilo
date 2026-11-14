<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Word extends Model
{
    protected $fillable = ['term', 'definition', 'note', 'text_direction'];

    public function pools()
    {
        return $this->belongsToMany(WordPool::class, 'pool_word');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function collections()
    {
        return $this->belongsToMany(Collection::class);
    }
}
