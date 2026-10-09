<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\BlogPost;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * The marketing site's blog, written in the operator console.
 *
 * Posts are Markdown; raw HTML is not rendered by the site. A video is a
 * YouTube or Vimeo link, never an uploaded file, so the site carries no video
 * weight of its own. The site reads published posts through /public/blog when
 * it is built, so a change shows after the next rebuild.
 */
class PlatformBlogController extends Controller
{
    private const DIR = 'website/blog';

    public function index()
    {
        return response()->json([
            'data' => BlogPost::query()->orderByDesc('updated_at')->get()
                ->map(fn (BlogPost $p) => [
                    'id' => $p->id,
                    'slug' => $p->slug,
                    'title' => $p->title,
                    'status' => $p->status,
                    'published_at' => $p->published_at,
                    'updated_at' => $p->updated_at,
                    'cover_url' => $this->coverUrl($p),
                    'category' => $p->category,
                    'video_placement' => $p->video_placement,
                ]),
            // The editor's "also show this video on" choices.
            'video_placements' => BlogPost::VIDEO_PLACEMENTS,
        ]);
    }

    public function show(int $id)
    {
        return response()->json(['data' => $this->present(BlogPost::findOrFail($id))]);
    }

    public function store(Request $request)
    {
        $post = BlogPost::create($this->validated($request, null));
        $this->audit($request, 'website.blog.created', $post);

        return response()->json(['data' => $this->present($post)], 201);
    }

    public function update(Request $request, int $id)
    {
        $post = BlogPost::findOrFail($id);
        $post->update($this->validated($request, $post));
        $this->audit($request, 'website.blog.updated', $post);

        return response()->json(['data' => $this->present($post->fresh())]);
    }

    public function destroy(Request $request, int $id)
    {
        $post = BlogPost::findOrFail($id);
        $this->removeCover($post);
        $post->delete();
        $this->audit($request, 'website.blog.deleted', $post);

        return response()->json(['message' => 'Post deleted.']);
    }

    public function uploadCover(Request $request, int $id)
    {
        $post = BlogPost::findOrFail($id);

        $request->validate([
            'image' => ['required', 'file', 'mimes:png,jpg,jpeg,webp', 'max:4096', 'dimensions:min_width=1200,min_height=630'],
        ], ['image.dimensions' => 'Use an image at least 1200 × 630 pixels; it is also the share preview.']);

        $this->removeCover($post);
        $file = $request->file('image');
        $path = $file->storeAs(self::DIR, $post->id.'-'.Str::lower(Str::random(8)).'.'.$file->extension(), 'public');
        $post->update(['cover_path' => $path]);
        $this->audit($request, 'website.blog.cover', $post);

        return response()->json(['data' => $this->present($post->fresh())], 201);
    }

    public function destroyCover(Request $request, int $id)
    {
        $post = BlogPost::findOrFail($id);
        $this->removeCover($post);
        $post->update(['cover_path' => null]);

        return response()->json(['data' => $this->present($post->fresh())]);
    }

    /**
     * An image placed inside a post by the editor. Public, like the cover:
     * the site shows it to every reader. Returns the address to embed.
     */
    public function uploadImage(Request $request)
    {
        $request->validate([
            'image' => ['required', 'file', 'mimes:png,jpg,jpeg,webp,gif', 'max:5120'],
        ]);

        $file = $request->file('image');
        $path = $file->storeAs(self::DIR.'/inline', now()->format('Ym').'-'.Str::lower(Str::random(12)).'.'.$file->extension(), 'public');

        return response()->json(['url' => Storage::disk('public')->url($path)], 201);
    }

    /** Public: published posts, with bodies, for the site's build. */
    public function publicIndex()
    {
        $posts = BlogPost::query()
            ->where('status', 'published')
            ->where('published_at', '<=', now())
            ->orderByDesc('published_at')
            ->limit(300)
            ->get();

        return response()->json([
            'data' => $posts->map(function (BlogPost $p) {
                $post = $this->present($p);
                unset($post['status']);

                return $post;
            })->values(),
        ]);
    }

    private function validated(Request $request, ?BlogPost $post): array
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:160'],
            'slug' => ['nullable', 'string', 'max:100', 'regex:/^[a-z0-9]+(-[a-z0-9]+)*$/', Rule::unique('blog_posts', 'slug')->ignore($post?->id)],
            'excerpt' => ['required', 'string', 'max:300'],
            'body' => ['required', 'string', 'max:100000'],
            'cover_alt' => ['nullable', 'string', 'max:200'],
            'video_url' => ['nullable', 'string', 'max:300'],
            'video_placement' => ['nullable', Rule::in(array_keys(BlogPost::VIDEO_PLACEMENTS))],
            'meta_title' => ['nullable', 'string', 'max:70'],
            'meta_description' => ['nullable', 'string', 'max:170'],
            'author_name' => ['nullable', 'string', 'max:80'],
            'status' => ['required', Rule::in(['draft', 'published'])],
            'category' => ['nullable', 'string', 'max:60'],
            'tags' => ['nullable', 'array', 'max:12'],
            'tags.*' => ['nullable', 'string', 'max:40'],
            // A future date schedules the post: the site shows it from the first rebuild after then.
            'publish_at' => ['nullable', 'date'],
        ]);
        $publishAt = $data['publish_at'] ?? null;
        unset($data['publish_at']);
        $data['category'] = ($data['category'] ?? null) ? trim($data['category']) : null;
        $data['tags'] = collect($data['tags'] ?? [])->map(fn ($t) => trim($t))->filter()->unique(fn ($t) => mb_strtolower($t))->values()->all() ?: null;

        if (! empty($data['video_url']) && BlogPost::parseVideo($data['video_url']) === null) {
            abort(response()->json([
                'message' => 'That is not a YouTube or Vimeo link.',
                'errors' => ['video_url' => ['Paste a YouTube or Vimeo video link.']],
            ], 422));
        }

        $data['slug'] = ($data['slug'] ?? null) ?: $post?->slug ?: $this->uniqueSlug($data['title']);
        $data['author_name'] = ($data['author_name'] ?? null) ?: 'SchoolPilot team';
        $data['video_url'] = ($data['video_url'] ?? null) ?: null;

        // A placement shows the post's video somewhere else on the site; without a video there is nothing to show.
        if (! empty($data['video_placement']) && ! $data['video_url']) {
            abort(response()->json([
                'message' => 'Add a video before choosing where else it appears.',
                'errors' => ['video_placement' => ['Add a YouTube or Vimeo link first.']],
            ], 422));
        }
        $data['video_placement'] = ($data['video_placement'] ?? null) ?: null;

        // Stamped once, the first time the post goes live.
        // Or the date the operator chose, which may be in the future.
        $data['published_at'] = $data['status'] === 'published'
            ? ($publishAt ? \Illuminate\Support\Carbon::parse($publishAt) : ($post?->published_at ?? now()))
            : ($publishAt ? \Illuminate\Support\Carbon::parse($publishAt) : $post?->published_at);

        return $data;
    }

    private function uniqueSlug(string $title): string
    {
        $base = Str::slug(Str::limit($title, 80, '')) ?: 'post';
        $slug = $base;

        for ($i = 2; BlogPost::where('slug', $slug)->exists(); $i++) {
            $slug = "{$base}-{$i}";
        }

        return $slug;
    }

    private function present(BlogPost $p): array
    {
        return [
            'id' => $p->id,
            'slug' => $p->slug,
            'title' => $p->title,
            'excerpt' => $p->excerpt,
            'body' => $p->body,
            'cover_url' => $this->coverUrl($p),
            'cover_alt' => $p->cover_alt,
            'video_url' => $p->video_url,
            'video' => BlogPost::parseVideo($p->video_url),
            'video_placement' => $p->video_placement,
            'meta_title' => $p->meta_title,
            'meta_description' => $p->meta_description,
            'author_name' => $p->author_name,
            'category' => $p->category,
            'tags' => $p->tags ?? [],
            'reading_minutes' => max(1, (int) round(str_word_count(strip_tags((string) $p->body)) / 220)),
            'status' => $p->status,
            'published_at' => $p->published_at,
            'updated_at' => $p->updated_at,
        ];
    }

    private function coverUrl(BlogPost $p): ?string
    {
        return $p->cover_path ? Storage::disk('public')->url($p->cover_path) : null;
    }

    private function removeCover(BlogPost $p): void
    {
        if ($p->cover_path) {
            Storage::disk('public')->delete($p->cover_path);
        }
    }

    private function audit(Request $request, string $action, BlogPost $post): void
    {
        AuditLog::create([
            'school_id' => null,
            'user_id' => $request->user()->id,
            'action' => $action,
            'auditable_type' => BlogPost::class,
            'auditable_id' => $post->id,
            'new_values' => ['slug' => $post->slug, 'status' => $post->status],
            'ip_address' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 255),
        ]);
    }
}
