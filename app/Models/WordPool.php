<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WordPool extends Model
{
    protected $fillable = ['name', 'language'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function words()
    {
        return $this->belongsToMany(Word::class, 'pool_word');
    }
}
