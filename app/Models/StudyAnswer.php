<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StudyAnswer extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['correct' => 'boolean', 'self_assessed' => 'boolean'];
    }
}
