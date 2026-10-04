<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A deliberate, logged regrade (CBT instructions §10.6). */
class CbtRegradeJob extends Model
{
    use BelongsToTenant;

    public const ACTIONS = [
        'change_key' => 'Change the answer key',
        'accept_answers' => 'Accept more than one answer',
        'credit_all' => 'Give everyone full marks',
        'drop' => 'Drop the question and rescale',
    ];

    protected $fillable = [
        'school_id', 'exam_id', 'question_id', 'action', 'params', 'scope', 'reason', 'params_hash', 'status',
        'summary', 'before', 'requested_by', 'applied_at', 'rolled_back_by', 'rolled_back_at',
    ];

    protected $casts = ['params' => 'array', 'summary' => 'array', 'applied_at' => 'datetime', 'rolled_back_at' => 'datetime'];

    protected $hidden = ['before'];
}
