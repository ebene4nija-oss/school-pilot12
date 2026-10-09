<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/** One version of the offline CBT lab client installer, linked by URL. */
class OfflineClientRelease extends Model
{
    public const STATUSES = ['draft', 'published', 'retired'];

    protected $fillable = [
        'version', 'download_url', 'sha256', 'size_bytes', 'notes', 'is_required', 'status', 'published_at', 'created_by',
    ];

    protected $casts = ['is_required' => 'boolean', 'published_at' => 'datetime', 'size_bytes' => 'integer', 'downloads' => 'integer'];

    /**
     * Published releases, newest version first. Ordered by version, not by
     * date, so retiring a bad release rolls the link back to the one before.
     */
    public static function published(): Collection
    {
        return static::where('status', 'published')->get()
            ->sort(fn (self $a, self $b) => version_compare($b->version, $a->version))
            ->values();
    }

    /** The release the download link serves, or null when none is published. */
    public static function current(): ?self
    {
        return static::published()->first();
    }

    /** The oldest version a client may still run: the newest published release marked required. */
    public static function minimumSupported(): ?string
    {
        return static::published()->first(fn (self $r) => $r->is_required)?->version;
    }
}
