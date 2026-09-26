<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A file a school sent with a report-card design request: a sample card, its
 * crest, a signature. Stored on the private disk; deleted when the request is
 * finished.
 */
class ReportCardDesignFile extends Model
{
    use BelongsToTenant;

    public const KINDS = [
        'sample' => 'Current report card',
        'crest' => 'Crest / logo',
        'signature' => 'Signature or stamp',
        'other' => 'Other',
    ];

    protected $fillable = ['request_id', 'school_id', 'kind', 'label', 'original_name', 'path', 'mime', 'size'];

    /** The storage path never leaves the server. */
    protected $hidden = ['path'];

    public function request()
    {
        return $this->belongsTo(ReportCardDesignRequest::class, 'request_id');
    }
}
