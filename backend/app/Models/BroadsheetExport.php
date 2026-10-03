<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Who exported which approved version, in what format. */
class BroadsheetExport extends Model
{
    use BelongsToTenant;

    public const UPDATED_AT = null;

    protected $fillable = ['school_id', 'broadsheet_id', 'version', 'format', 'kind', 'user_id'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
