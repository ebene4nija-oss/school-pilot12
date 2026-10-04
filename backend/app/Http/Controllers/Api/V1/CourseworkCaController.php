<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\DomainRefusal;
use App\Http\Controllers\Controller;
use App\Services\Results\CourseworkCa;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Filling a CA from homework and quizzes (TASK 08, 21). The subject teacher
 * decides which work counts and adjusts every mark before applying it.
 */
class CourseworkCaController extends Controller
{
    public function __construct(private CourseworkCa $ca)
    {
    }

    public function sources(Request $request): JsonResponse
    {
        [$term, $class, $subject] = $this->scope($request);

        return response()->json($this->ca->sources($this->schoolId(), $term, $class, $subject) + [
            'history' => $this->ca->history($this->schoolId(), $term, $class, $subject),
        ]);
    }

    public function suggest(Request $request): JsonResponse
    {
        [$term, $class, $subject] = $this->scope($request);
        $data = $request->validate([
            'component' => 'required|string|max:20',
            'homework_ids' => 'array', 'homework_ids.*' => 'integer',
            'exam_ids' => 'array', 'exam_ids.*' => 'integer',
            'missing' => 'sometimes|in:zero,skip',
        ]);

        try {
            return response()->json($this->ca->suggest($this->schoolId(), $term, $class, $subject, $data['component'], $data['homework_ids'] ?? [], $data['exam_ids'] ?? [], $data['missing'] ?? 'zero'));
        } catch (DomainRefusal $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }
    }

    public function apply(Request $request): JsonResponse
    {
        [$term, $class, $subject] = $this->scope($request);
        $data = $request->validate([
            'component' => 'required|string|max:20',
            'homework_ids' => 'array', 'homework_ids.*' => 'integer',
            'exam_ids' => 'array', 'exam_ids.*' => 'integer',
            'missing' => 'sometimes|in:zero,skip',
            'rows' => 'required|array|min:1',
            'rows.*.student_id' => 'required|integer',
            'rows.*.score' => 'nullable|numeric|min:0',
            'rows.*.suggested' => 'nullable|numeric|min:0',
        ]);

        try {
            $result = $this->ca->apply($this->schoolId(), $term, $class, $subject, $data['component'], $data['rows'], [
                'homework_ids' => $data['homework_ids'] ?? [], 'exam_ids' => $data['exam_ids'] ?? [], 'missing' => $data['missing'] ?? 'zero',
            ], $request->user()->id);
        } catch (DomainRefusal $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }

        return response()->json($result + ['message' => "{$result['saved']} mark(s) entered".($result['refused'] ? ', '.count($result['refused']).' refused.' : '.')]);
    }

    /** Term, class and subject — and only a subject's own teacher (or an admin) works on it. */
    private function scope(Request $request): array
    {
        $schoolId = $this->schoolId();
        $data = $request->validate([
            'term_id' => ['required', 'integer', Rule::exists('terms', 'id')->where('school_id', $schoolId)],
            'class_id' => ['required', 'integer', Rule::exists('classes', 'id')->where('school_id', $schoolId)],
            'subject_id' => ['required', 'integer', Rule::exists('subjects', 'id')->where('school_id', $schoolId)],
        ]);

        $role = $request->user()->userProfile?->role;
        if (! in_array($role, ['super_admin', 'school_admin'], true)) {
            $teaches = DB::table('teacher_subjects')->where('school_id', $schoolId)->where('teacher_id', $request->user()->id)->where('subject_id', $data['subject_id'])
                ->where(fn ($q) => $q->where('class_id', $data['class_id'])->orWhereNull('class_id'))->exists();
            abort_unless($teaches, 403, 'Only the subject teacher fills this CA.');
        }

        return [(int) $data['term_id'], (int) $data['class_id'], (int) $data['subject_id']];
    }
}
