<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A marketing-site blog post, written in Markdown in the operator console. */
class BlogPost extends Model
{
    protected $fillable = [
        'slug', 'title', 'excerpt', 'body', 'cover_path', 'cover_alt', 'video_url',
        'meta_title', 'meta_description', 'author_name', 'status', 'published_at',
        'category', 'tags',
    ];

    protected $casts = ['published_at' => 'datetime', 'tags' => 'array'];

    /**
     * A YouTube or Vimeo link reduced to provider and id, or null when it is
     * neither. Only these two are embedded, so a link can never point the
     * site's iframe somewhere else.
     *
     * @return array{provider: string, id: string}|null
     */
    public static function parseVideo(?string $url): ?array
    {
        if (! $url) {
            return null;
        }

        $parts = parse_url(trim($url));
        $host = strtolower(preg_replace('/^(www|m)\./', '', $parts['host'] ?? ''));
        $path = $parts['path'] ?? '';
        parse_str($parts['query'] ?? '', $query);

        if (! in_array($parts['scheme'] ?? '', ['http', 'https'], true)) {
            return null;
        }

        $id = null;

        if ($host === 'youtu.be') {
            $id = ltrim($path, '/');
        } elseif ($host === 'youtube.com' || $host === 'youtube-nocookie.com') {
            if ($path === '/watch') {
                $id = $query['v'] ?? null;
            } elseif (preg_match('#^/(embed|shorts|live)/([^/]+)#', $path, $m)) {
                $id = $m[2];
            }
        }

        if ($id !== null && preg_match('/^[A-Za-z0-9_-]{11}$/', (string) $id)) {
            return ['provider' => 'youtube', 'id' => $id];
        }

        if (($host === 'vimeo.com' || $host === 'player.vimeo.com') && preg_match('#^(?:/video)?/(\d{6,12})(?:/|$)#', $path, $m)) {
            return ['provider' => 'vimeo', 'id' => $m[1]];
        }

        return null;
    }
}
