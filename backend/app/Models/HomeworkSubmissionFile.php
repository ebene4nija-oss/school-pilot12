<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HomeworkSubmissionFile extends Model
{
    use BelongsToTenant;

    protected $table = 'homework_submission_files';

    protected $fillable = ['school_id', 'submission_id', 'path', 'name', 'mime', 'size_bytes'];

    protected $hidden = ['path'];
}
