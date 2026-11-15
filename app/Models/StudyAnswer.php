<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StudyAnswer extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['had_error' => 'boolean', 'skipped' => 'boolean', 'attempt_no' => 'integer', 'question_index' => 'integer', 'correct' => 'boolean', 'self_assessed' => 'boolean'];
    }
}
