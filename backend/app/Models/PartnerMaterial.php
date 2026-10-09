<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Sales material for partners. Platform-wide, so no tenant scope. */
class PartnerMaterial extends Model
{
    public const CATEGORIES = ['brochure', 'social', 'script', 'video', 'other'];

    public const KINDS = ['file', 'text', 'link'];

    protected $fillable = ['title', 'description', 'category', 'kind', 'file_path', 'file_name', 'mime', 'size_bytes', 'body', 'url', 'sort', 'is_published', 'created_by'];

    protected $casts = ['is_published' => 'boolean', 'sort' => 'integer', 'size_bytes' => 'integer'];

    /** A script with this partner's link, code and name filled in. */
    public function bodyFor(Partner $partner, string $link): ?string
    {
        return $this->body === null ? null : strtr($this->body, ['{link}' => $link, '{code}' => (string) $partner->code, '{name}' => (string) $partner->name]);
    }
}
