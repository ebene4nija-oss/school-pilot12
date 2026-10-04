<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\DomainRefusal;
use App\Http\Controllers\Controller;
use App\Models\LearningMaterial;
use App\Models\LearningMaterialAccess;
use App\Models\LiveClass;
use App\Models\LmsSetting;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Services\Lms\LmsService;
use App\Services\Results\AuthorisationFailure;
use App\Support\InAppNotifier;
use App\Support\Sections;
use App\Support\TeacherScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * The school's learning library (TASK 14 §4): files and links organised by
 * section, class, subject and term, shared with the people they are for.
 * Files are served through this controller, never from a public URL, so an
 * address passed outside the school opens nothing.
 */
class LearningMaterialController extends Controller
{
    /** Accepted uploads, by kind. Executables and archives are never accepted. */
    public const TYPES = [
        'pdf' => ['pdf'],
        'document' => ['doc', 'docx', 'odt', 'rtf', 'txt'],
        'presentation' => ['ppt', 'pptx', 'odp'],
        'image' => ['jpg', 'jpeg', 'png', 'gif', 'webp'],
        'video' => ['mp4', 'webm', '3gp', 'mov'],
        'audio' => ['mp3', 'm4a', 'aac', 'ogg', 'wav'],
    ];

    public function __construct(private LmsService $lms)
    {
    }

    public function settings(): JsonResponse
    {
        return response()->json(LmsSetting::for($this->schoolId()));
    }

    public function updateSettings(Request $request): JsonResponse
    {
        $data = $request->validate([
            'require_material_approval' => 'sometimes|boolean',
            'parents_see_materials' => 'sometimes|boolean',
            'parent_assist_sections' => 'sometimes|array',
            'parent_assist_sections.*' => 'in:nursery,primary,secondary',
            'present_percent' => 'sometimes|integer|min:10|max:100',
            'late_grace_minutes' => 'sometimes|integer|min:0|max:60',
            'recording_allowed' => 'sometimes|boolean',
            'recording_notice' => 'sometimes|nullable|string|max:2000',
            'max_upload_mb' => 'sometimes|integer|min:1|max:200',
        ]);

        if (($data['recording_allowed'] ?? false) && empty($data['recording_notice'] ?? LmsSetting::for($this->schoolId())->recording_notice)) {
            return response()->json(['message' => 'Write the recording notice families will see before allowing recordings.', 'errors' => ['recording_notice' => ['Required when recordings are allowed.']]], 422);
        }

        $s = LmsSetting::updateOrCreate(['school_id' => $this->schoolId()], $data);
        $this->lms->audit($request->user(), 'lms.settings_updated', LmsSetting::class, $s->id, $data);

        return response()->json($s->fresh());
    }

    // ==================================================================

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $role = $user->userProfile?->role;
        $schoolId = $this->schoolId();

        if ($role === 'student') {
            $student = Student::where('user_id', $user->id)->first();
            $query = $student ? $this->lms->visibleTo($student) : LearningMaterial::whereRaw('1 = 0');
        } elseif ($role === 'parent') {
            if (! LmsSetting::for($schoolId)->parents_see_materials) {
                return response()->json(['data' => [], 'message' => 'The school shares learning materials with students directly.']);
            }
            $child = Student::whereHas('guardians', fn ($q) => $q->where('guardians.user_id', $user->id))
                ->when($request->filled('student_id'), fn ($q) => $q->whereKey($request->integer('student_id')))->first();
            $query = $child ? $this->lms->visibleTo($child) : LearningMaterial::whereRaw('1 = 0');
        } else {
            $query = LearningMaterial::where('school_id', $schoolId);
            if ($role === 'teacher') {
                // The published library, plus their own drafts and anything awaiting review.
                $query->where(fn ($q) => $q->where('status', 'published')->orWhere('uploaded_by', $user->id)->orWhere('status', 'pending_approval'));
            }
            $query->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
                ->when($request->boolean('mine'), fn ($q) => $q->where('uploaded_by', $user->id));
        }

        $rows = $query
            ->when($request->filled('class_id'), fn ($q) => $q->where('class_id', $request->integer('class_id')))
            ->when($request->filled('subject_id'), fn ($q) => $q->where('subject_id', $request->integer('subject_id')))
            ->when($request->filled('term_id'), fn ($q) => $q->where('term_id', $request->integer('term_id')))
            ->when($request->filled('section'), fn ($q) => $q->where('section', $request->input('section')))
            ->when($request->filled('q'), fn ($q) => $q->where(fn ($w) => $w->where('title', 'like', '%'.$request->input('q').'%')->orWhere('topic', 'like', '%'.$request->input('q').'%')))
            ->with(['uploader:id,name', 'schoolClass:id,name', 'subject:id,name', 'targets'])
            ->orderByDesc('id')->limit(300)->get();

        // Pending items are shown only to the people who can review them.
        if ($role === 'teacher') {
            $rows = $rows->filter(fn ($m) => $m->status !== 'pending_approval' || (int) $m->uploaded_by === $user->id || $this->lms->canReview($user, $m))->values();
        }

        return response()->json(['data' => $rows->map(fn ($m) => $this->present($m, $request))]);
    }

    public function store(Request $request): JsonResponse
    {
        $schoolId = $this->schoolId();
        $settings = LmsSetting::for($schoolId);
        $data = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string|max:4000',
            'file' => 'required_without:url|file|max:'.($settings->max_upload_mb * 1024),
            'url' => 'required_without:file|nullable|url|max:2000',
            'class_id' => ['nullable', Rule::exists('classes', 'id')->where('school_id', $schoolId)],
            'subject_id' => ['nullable', Rule::exists('subjects', 'id')->where('school_id', $schoolId)],
            'term_id' => ['nullable', Rule::exists('terms', 'id')->where('school_id', $schoolId)],
            'section' => 'nullable|in:nursery,primary,secondary',
            'topic' => 'nullable|string|max:255',
            'downloadable' => 'nullable|boolean',
            'draft' => 'nullable|boolean',
            'targets' => 'required|array|min:1|max:50',
            'targets.*.target_type' => 'required|in:class,arm,section,student,live_class',
            'targets.*.class_id' => ['nullable', 'required_if:targets.*.target_type,class,arm', Rule::exists('classes', 'id')->where('school_id', $schoolId)],
            'targets.*.arm_id' => 'nullable|required_if:targets.*.target_type,arm|integer|exists:arms,id',
            'targets.*.section' => 'nullable|required_if:targets.*.target_type,section|in:nursery,primary,secondary',
            'targets.*.student_id' => ['nullable', 'required_if:targets.*.target_type,student', Rule::exists('students', 'id')->where('school_id', $schoolId)],
            'targets.*.live_class_id' => ['nullable', 'required_if:targets.*.target_type,live_class', Rule::exists('live_classes', 'id')->where('school_id', $schoolId)],
        ]);

        $type = 'link';
        if ($request->hasFile('file')) {
            $ext = strtolower($request->file('file')->getClientOriginalExtension());
            $type = collect(self::TYPES)->search(fn ($exts) => in_array($ext, $exts, true));
            if ($type === false) {
                return response()->json(['message' => 'That file type is not accepted. Use PDF, Word, PowerPoint, images, video or audio.', 'errors' => ['file' => ['Unsupported file type.']]], 422);
            }
        }

        try {
            $this->lms->assertTargets($request->user(), $schoolId, $data['targets']);
        } catch (AuthorisationFailure $e) {
            return response()->json(['message' => $e->getMessage()], 403);
        }

        $status = ($data['draft'] ?? false) ? 'draft' : ($settings->require_material_approval && ! $this->lms->isAdmin($request->user()) ? 'pending_approval' : 'published');

        $material = DB::transaction(function () use ($request, $data, $schoolId, $type, $status) {
            $file = $request->file('file');
            $path = $file?->storeAs("lms/materials/{$schoolId}", Str::uuid().'.'.strtolower($file->getClientOriginalExtension()), 'local');

            $m = LearningMaterial::create([
                'school_id' => $schoolId, 'title' => $data['title'], 'description' => $data['description'] ?? null, 'type' => $type,
                'file_path' => $path ?: null, 'file_name' => $file?->getClientOriginalName(), 'mime' => $file?->getMimeType(), 'size_bytes' => $file?->getSize(),
                'url' => $file ? null : $data['url'],
                'class_id' => $data['class_id'] ?? null, 'subject_id' => $data['subject_id'] ?? null, 'term_id' => $data['term_id'] ?? null,
                'section' => $data['section'] ?? $this->lms->sectionOfClass($data['class_id'] ?? null),
                'session_id' => TeacherScope::currentSessionId($schoolId), 'topic' => $data['topic'] ?? null,
                'uploaded_by' => $request->user()->id, 'status' => $status, 'published_at' => $status === 'published' ? now() : null,
                'downloadable' => $data['downloadable'] ?? true,
            ]);
            foreach ($data['targets'] as $t) {
                LearningMaterialAccess::create(['school_id' => $schoolId, 'material_id' => $m->id] + array_intersect_key($t, array_flip(['target_type', 'class_id', 'arm_id', 'section', 'student_id', 'live_class_id'])));
            }

            return $m;
        });

        $this->lms->audit($request->user(), 'lms.material_uploaded', LearningMaterial::class, $material->id, ['title' => $material->title, 'status' => $status]);
        if ($status === 'published') {
            $this->announce($material);
        }

        return response()->json(['material' => $this->present($material->fresh(['targets']), $request), 'message' => $status === 'pending_approval' ? 'Uploaded. It will be visible once a reviewer approves it.' : ($status === 'draft' ? 'Saved as a draft.' : 'Published.')], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $m = LearningMaterial::where('school_id', $this->schoolId())->findOrFail($id);
        abort_unless((int) $m->uploaded_by === $request->user()->id || $this->lms->isAdmin($request->user()), 403, 'Only the uploader or an admin can edit this material.');

        $data = $request->validate([
            'title' => 'sometimes|string|max:255', 'description' => 'sometimes|nullable|string|max:4000', 'topic' => 'sometimes|nullable|string|max:255',
            'downloadable' => 'sometimes|boolean', 'publish' => 'sometimes|boolean',
        ]);

        if ($request->boolean('publish') && in_array($m->status, ['draft', 'rejected'], true)) {
            $needsReview = LmsSetting::for($m->school_id)->require_material_approval && ! $this->lms->isAdmin($request->user());
            $data['status'] = $needsReview ? 'pending_approval' : 'published';
            $data['published_at'] = $needsReview ? null : now();
        }
        unset($data['publish']);
        $m->update($data);
        if (($data['status'] ?? null) === 'published') {
            $this->announce($m);
        }

        return response()->json(['material' => $this->present($m->fresh(['targets']), $request)]);
    }

    public function review(Request $request, int $id): JsonResponse
    {
        $m = LearningMaterial::where('school_id', $this->schoolId())->where('status', 'pending_approval')->findOrFail($id);
        abort_unless($this->lms->canReview($request->user(), $m), 403, 'Only the head of department, the section head or an admin reviews materials.');
        $data = $request->validate(['approve' => 'required|boolean', 'note' => 'nullable|required_if:approve,false|string|max:500']);

        $m->update(['status' => $data['approve'] ? 'published' : 'rejected', 'reviewed_by' => $request->user()->id, 'reviewed_at' => now(), 'review_note' => $data['note'] ?? null, 'published_at' => $data['approve'] ? now() : null]);
        $this->lms->audit($request->user(), $data['approve'] ? 'lms.material_approved' : 'lms.material_rejected', LearningMaterial::class, $m->id, ['note' => $data['note'] ?? null]);
        InAppNotifier::send($m->school_id, [(int) $m->uploaded_by], 'lms', $data['approve'] ? 'Material approved' : 'Material returned', $data['approve'] ? "\"{$m->title}\" is now published." : "\"{$m->title}\" was returned: {$data['note']}");
        if ($data['approve']) {
            $this->announce($m);
        }

        return response()->json(['material' => $this->present($m->fresh(['targets']), $request)]);
    }

    public function archive(Request $request, int $id): JsonResponse
    {
        $m = LearningMaterial::where('school_id', $this->schoolId())->findOrFail($id);
        abort_unless((int) $m->uploaded_by === $request->user()->id || $this->lms->isAdmin($request->user()), 403);
        $m->update(['status' => 'archived']);
        $this->lms->audit($request->user(), 'lms.material_archived', LearningMaterial::class, $m->id, []);

        return response()->json(['message' => 'Archived. Students no longer see it; the record is kept.']);
    }

    /**
     * Open or download. Served as a file response, which honours Range
     * requests, so an interrupted download on a weak connection resumes
     * rather than starting again (§12).
     */
    public function download(Request $request, int $id)
    {
        $m = LearningMaterial::where('school_id', $this->schoolId())->findOrFail($id);
        abort_unless($this->lms->canSee($request->user(), $m), 404);

        $this->recordOpen($request, $m);

        if ($m->type === 'link') {
            return response()->json(['url' => $m->url]);
        }
        abort_unless($m->file_path && Storage::disk('local')->exists($m->file_path), 404, 'The file is no longer available.');

        $path = Storage::disk('local')->path($m->file_path);
        $headers = ['Content-Type' => $m->mime ?: 'application/octet-stream', 'X-Content-Type-Options' => 'nosniff'];

        return $m->downloadable
            ? response()->download($path, $m->file_name ?: basename($path), $headers)
            : response()->file($path, $headers + ['Content-Disposition' => 'inline']);
    }

    /**
     * A link the phone's own browser or PDF viewer can open. The app's token
     * lives in secure storage and never leaves it, so instead of handing it to
     * the browser the API signs a URL for this one file, valid for ten minutes.
     */
    public function link(Request $request, int $id): JsonResponse
    {
        $m = LearningMaterial::where('school_id', $this->schoolId())->findOrFail($id);
        abort_unless($this->lms->canSee($request->user(), $m), 404);
        $this->recordOpen($request, $m);

        if ($m->type === 'link') {
            return response()->json(['url' => $m->url, 'expires_at' => null]);
        }
        abort_unless($m->file_path && Storage::disk('local')->exists($m->file_path), 404, 'The file is no longer available.');
        $expires = now()->addMinutes(10);

        return response()->json([
            'url' => \Illuminate\Support\Facades\URL::temporarySignedRoute('learning-materials.file', $expires, ['id' => $m->id]),
            'expires_at' => $expires->toIso8601String(),
        ]);
    }

    /** The file behind a signed link. The signature is the permission. */
    public function signedFile(int $id)
    {
        $m = LearningMaterial::withoutGlobalScopes()->where('status', 'published')->findOrFail($id);
        abort_unless($m->file_path && Storage::disk('local')->exists($m->file_path), 404, 'The file is no longer available.');
        $path = Storage::disk('local')->path($m->file_path);
        $headers = ['Content-Type' => $m->mime ?: 'application/octet-stream', 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store'];

        return $m->downloadable
            ? response()->download($path, $m->file_name ?: basename($path), $headers)
            : response()->file($path, $headers + ['Content-Disposition' => 'inline']);
    }

    /** Who it reached: targeted students, and which of them opened it (§8.2). */
    public function reach(Request $request, int $id): JsonResponse
    {
        $m = LearningMaterial::where('school_id', $this->schoolId())->findOrFail($id);
        abort_unless((int) $m->uploaded_by === $request->user()->id || $this->lms->isAdmin($request->user()) || $this->lms->canReview($request->user(), $m), 403);

        $students = $this->targetStudents($m)->load('user:id,name');
        $opened = DB::table('learning_material_views')->where('material_id', $m->id)->whereNotNull('student_id')->pluck('last_opened_at', 'student_id');

        return response()->json([
            'targeted' => $students->count(),
            'opened' => $students->filter(fn ($s) => isset($opened[$s->id]))->count(),
            'students' => $students->map(fn ($s) => ['student_id' => $s->id, 'name' => $s->user?->name, 'last_opened_at' => $opened[$s->id] ?? null])->sortBy('name')->values(),
        ]);
    }

    // ==================================================================

    private function recordOpen(Request $request, LearningMaterial $m): void
    {
        $user = $request->user();
        $student = $user->userProfile?->role === 'student' ? Student::where('user_id', $user->id)->value('id') : null;
        $row = DB::table('learning_material_views')->where('material_id', $m->id)->where('user_id', $user->id)->first();
        if ($row) {
            DB::table('learning_material_views')->where('id', $row->id)->update(['opens' => $row->opens + 1, 'last_opened_at' => now(), 'updated_at' => now()]);
        } else {
            DB::table('learning_material_views')->insert(['school_id' => $m->school_id, 'material_id' => $m->id, 'user_id' => $user->id, 'student_id' => $student, 'opens' => 1, 'first_opened_at' => now(), 'last_opened_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    /** Every student a material is shared with. */
    public function targetStudents(LearningMaterial $m): \Illuminate\Support\Collection
    {
        $ids = collect();
        foreach ($m->targets()->get() as $t) {
            $q = Student::where('school_id', $m->school_id)->where('status', 'active');
            $ids = $ids->merge(match ($t->target_type) {
                'class' => $q->where('class_id', $t->class_id)->pluck('id'),
                'arm' => $q->where('class_id', $t->class_id)->where('arm_id', $t->arm_id)->pluck('id'),
                'section' => $q->whereIn('class_id', SchoolClass::where('school_id', $m->school_id)->get(['id', 'level_category'])
                    ->filter(fn ($c) => Sections::sectionOf(Sections::levelGroupOf($c->level_category)) === $t->section)->pluck('id'))->pluck('id'),
                'student' => collect([$t->student_id]),
                'live_class' => ($lc = LiveClass::find($t->live_class_id)) ? $q->where('class_id', $lc->class_id)->when($lc->arm_id, fn ($w) => $w->where('arm_id', $lc->arm_id))->pluck('id') : collect(),
                default => collect(),
            });
        }

        return Student::whereIn('id', $ids->unique()->all() ?: [0])->get();
    }

    private function announce(LearningMaterial $m): void
    {
        $users = $this->targetStudents($m)->pluck('user_id')->map(fn ($id) => (int) $id)->all();
        InAppNotifier::send($m->school_id, $users, 'lms', 'New learning material', "\"{$m->title}\" has been shared with you.");
    }

    private function present(LearningMaterial $m, Request $request): array
    {
        $staff = in_array($request->user()->userProfile?->role, ['school_admin', 'super_admin', 'teacher'], true);

        return $m->only(['id', 'title', 'description', 'type', 'file_name', 'mime', 'size_bytes', 'section', 'class_id', 'subject_id', 'term_id', 'topic', 'status', 'downloadable', 'published_at', 'review_note']) + [
            'url' => $m->type === 'link' ? $m->url : null,
            'class' => $m->schoolClass?->name,
            'subject' => $m->subject?->name,
            'uploaded_by' => $m->uploader?->name,
            'mine' => (int) $m->uploaded_by === $request->user()->id,
            'targets' => $staff ? $m->targets->map(fn ($t) => $t->only(['target_type', 'class_id', 'arm_id', 'section', 'student_id', 'live_class_id']))->values() : null,
        ];
    }
}
