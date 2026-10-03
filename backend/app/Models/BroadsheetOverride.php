<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A validation warning accepted by someone with authority, with the reason. */
class BroadsheetOverride extends Model
{
    use BelongsToTenant;

    protected $fillable = ['school_id', 'broadsheet_id', 'issue_code', 'issue_key', 'reason', 'user_id'];
}
