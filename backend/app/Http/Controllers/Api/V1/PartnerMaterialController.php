<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Partner;
use App\Models\PartnerMaterial;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Sales material for partners: brochures, social images, message scripts and
 * links. SchoolPilot uploads and orders them in Super Admin; a partner sees
 * the published ones on their partner page, with scripts already carrying
 * their own referral link.
 *
 * Files live on the private disk: a partner downloads through the API, so a
 * file is never at a guessable public address.
 */
class PartnerMaterialController extends Controller
{
    private const DISK = 'local';

    private const DIR = 'partner-materials';

    // ------------------------------------------------------------ operator

    public function index(): JsonResponse
    {
        return response()->json(['data' => PartnerMaterial::orderBy('sort')->orderBy('id')->get()->map(fn ($m) => $this->present($m))]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $material = new PartnerMaterial($data + ['created_by' => $request->user()->id]);

        if ($material->kind === 'file') {
            $request->validate(['file' => $this->fileRule(true)]);
            $this->attach($material, $request->file('file'));
        }

        $material->save();
        $this->audit($request, 'partner_material.created', $material);

        return response()->json(['data' => $this->present($material)], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $material = PartnerMaterial::findOrFail($id);
        $material->fill($this->validated($request, $material));

        if ($request->hasFile('file')) {
            $request->validate(['file' => $this->fileRule(true)]);
            $this->removeFile($material);
            $this->attach($material, $request->file('file'));
        }

        if ($material->kind === 'file' && ! $material->file_path) {
            return response()->json(['message' => 'Upload the file for this material.', 'errors' => ['file' => ['Upload the file for this material.']]], 422);
        }

        $material->save();
        $this->audit($request, 'partner_material.updated', $material);

        return response()->json(['data' => $this->present($material)]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $material = PartnerMaterial::findOrFail($id);
        $this->removeFile($material);
        $material->delete();
        $this->audit($request, 'partner_material.deleted', $material);

        return response()->json(['message' => 'Material removed.']);
    }

    /** The operator previewing a file before publishing it. */
    public function operatorDownload(int $id): StreamedResponse
    {
        return $this->stream(PartnerMaterial::findOrFail($id));
    }

    // ------------------------------------------------------------ partner

    public function forPartner(Request $request): JsonResponse
    {
        $partner = Partner::where('user_id', $request->user()->id)->firstOrFail();
        $link = $partner->referralLink();

        return response()->json([
            'referral_link' => $link,
            'data' => PartnerMaterial::where('is_published', true)->orderBy('sort')->orderBy('id')->get()
                ->map(fn ($m) => ['body' => $m->bodyFor($partner, $link)] + $this->present($m, false)),
        ]);
    }

    public function partnerDownload(Request $request, int $id): StreamedResponse
    {
        Partner::where('user_id', $request->user()->id)->where('status', 'active')->firstOrFail();

        return $this->stream(PartnerMaterial::where('is_published', true)->findOrFail($id));
    }

    // ------------------------------------------------------------ helpers

    private function validated(Request $request, ?PartnerMaterial $material = null): array
    {
        $s = $material ? 'sometimes|' : '';
        $kind = $request->input('kind', $material?->kind);

        return $request->validate([
            'title' => $s.'required|string|max:160',
            'description' => 'sometimes|nullable|string|max:500',
            'category' => $s.'required|'.Rule::in(PartnerMaterial::CATEGORIES)->__toString(),
            'kind' => $s.'required|'.Rule::in(PartnerMaterial::KINDS)->__toString(),
            'body' => [$kind === 'text' && ! $material ? 'required' : 'sometimes', 'nullable', 'string', 'max:5000'],
            'url' => [$kind === 'link' && ! $material ? 'required' : 'sometimes', 'nullable', 'url:https', 'max:500'],
            'sort' => 'sometimes|integer|min:0|max:10000',
            'is_published' => 'sometimes|boolean',
        ]);
    }

    /** PDFs, images, short videos and office documents; nothing executable. */
    private function fileRule(bool $required): array
    {
        return [$required ? 'required' : 'nullable', 'file', 'max:51200', 'mimes:pdf,png,jpg,jpeg,webp,gif,mp4,docx,pptx,xlsx,zip'];
    }

    private function attach(PartnerMaterial $material, UploadedFile $file): void
    {
        $name = Str::limit(preg_replace('/[^A-Za-z0-9._ -]/', '', $file->getClientOriginalName()) ?: 'file', 120, '');
        $material->file_path = $file->storeAs(self::DIR, Str::lower(Str::random(16)).'.'.$file->extension(), self::DISK);
        $material->file_name = $name;
        $material->mime = $file->getMimeType();
        $material->size_bytes = $file->getSize();
    }

    private function removeFile(PartnerMaterial $material): void
    {
        if ($material->file_path) {
            Storage::disk(self::DISK)->delete($material->file_path);
        }
        $material->file_path = null;
    }

    private function stream(PartnerMaterial $material): StreamedResponse
    {
        abort_unless($material->kind === 'file' && $material->file_path && Storage::disk(self::DISK)->exists($material->file_path), 404);

        return Storage::disk(self::DISK)->download($material->file_path, $material->file_name ?: basename($material->file_path));
    }

    private function present(PartnerMaterial $m, bool $operator = true): array
    {
        return [
            'id' => $m->id,
            'title' => $m->title,
            'description' => $m->description,
            'category' => $m->category,
            'kind' => $m->kind,
            'file_name' => $m->file_name,
            'mime' => $m->mime,
            'size_bytes' => $m->size_bytes,
            'url' => $m->url,
            'sort' => $m->sort,
        ] + ($operator ? ['body' => $m->body, 'is_published' => $m->is_published, 'updated_at' => $m->updated_at?->toIso8601String()] : []);
    }

    private function audit(Request $request, string $action, PartnerMaterial $m): void
    {
        AuditLog::create([
            'school_id' => null, 'user_id' => $request->user()->id, 'action' => $action,
            'auditable_type' => PartnerMaterial::class, 'auditable_id' => $m->id, 'new_values' => ['title' => $m->title, 'kind' => $m->kind],
        ]);
    }
}
