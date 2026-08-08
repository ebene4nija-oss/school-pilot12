<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class CbtMediaAsset extends Model
{
    use HasFactory, BelongsToTenant;

    protected $fillable = [
        'school_id',
        'uploaded_by',
        'disk',
        'path',
        'thumbnail_path',
        'mime_type',
        'extension',
        'byte_size',
        'width',
        'height',
        'checksum',
        'alt_text',
        'caption',
    ];

    protected $casts = [
        'byte_size' => 'integer',
        'width' => 'integer',
        'height' => 'integer',
    ];

    protected $appends = ['url', 'thumbnail_url'];

    public function getUrlAttribute(): string
    {
        return $this->publicUrl($this->path);
    }

    public function getThumbnailUrlAttribute(): ?string
    {
        return $this->thumbnail_path ? $this->publicUrl($this->thumbnail_path) : null;
    }

    private function publicUrl(?string $path): string
    {
        if (!$path) {
            return '';
        }

        try {
            return Storage::disk($this->disk ?: 'public')->url($path);
        } catch (\Throwable $e) {
            // Disks without a configured URL (e.g. the private 'local' disk in
            // tests) still need a stable, resolvable reference for clients.
            return '/storage/' . ltrim($path, '/');
        }
    }

    /**
     * The shape an offline exam client caches: enough to fetch the file and
     * prove afterwards that what it cached is what the server sent.
     */
    public function toManifestEntry(): array
    {
        return [
            'asset_id' => $this->id,
            'url' => $this->url,
            'thumbnail_url' => $this->thumbnail_url,
            'checksum' => $this->checksum,
            'byte_size' => $this->byte_size,
            'mime_type' => $this->mime_type,
            'width' => $this->width,
            'height' => $this->height,
            'alt_text' => $this->alt_text,
            'caption' => $this->caption,
        ];
    }
}
