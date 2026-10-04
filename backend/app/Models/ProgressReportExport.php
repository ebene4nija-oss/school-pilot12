<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A progress report someone downloaded, and the data fingerprint it was built from. */
class ProgressReportExport extends Model
{
    use BelongsToTenant;

    public const UPDATED_AT = null;

    protected $fillable = ['school_id', 'student_id', 'term_id', 'user_id', 'kind', 'data_hash', 'outdated'];

    protected $casts = ['outdated' => 'boolean'];
}
