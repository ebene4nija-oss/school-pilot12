<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\CaScheme;
use App\Models\ClassTeacherAssignment;
use App\Models\GradingScale;
use App\Http\Controllers\Controller;
use App\Models\SchoolReportSettings;
use App\Models\Student;
use App\Models\StudentTermReport;
use App\Models\Term;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Report-card settings (chosen at onboarding) and the per-pupil ratings and
 * comments teachers enter each term.
 *
 * Who writes what:
 *  - ratings and the form teacher's comment: a school admin, or the class's
 *    form teacher or assistant for that session;
 *  - the principal's comment: a school admin only.
 */
class ReportEntryController extends Controller
{
    // ---------------------------------------------------------- settings

    public function settings()
    {
        $schoolId = $this->schoolId();

        if ($schoolId === null) {
            return response()->json(['message' => 'Choose a school first.'], 409);
        }

        $settings = SchoolReportSettings::forSchool($schoolId);
        $scheme = CaScheme::activeFor($schoolId);

        return response()->json([
            'configured' => $settings->exists,
            'settings' => $this->describe($settings),
            // The rest of the onboarding picture, so the setup screen and the
            // checklist can show it in one place.
            'ca_scheme' => ['name' => $scheme->name, 'saved' => $scheme->exists, 'components' => $scheme->parts()],
            'grading_scales_set' => GradingScale::where('school_id', $schoolId)->count(),
        ]);
    }

    public function updateSettings(Request $request)
    {
        $schoolId = $this->schoolId();

        $validated = $request->validate([
            'affective_traits' => 'present|array|max:20',
            'affective_traits.*' => 'required|string|max:40|distinct',
            'psychomotor_skills' => 'present|array|max:20',
            'psychomotor_skills.*' => 'required|string|max:40|distinct',
            'rating_scale' => 'required|array|min:2|max:10',
            'rating_scale.*.value' => 'required|string|max:5|distinct',
            'rating_scale.*.label' => 'nullable|string|max:30',
            'form_teacher_comment' => 'required|boolean',
            'principal_comment' => 'required|boolean',
            'principal_title' => 'required|string|max:40',
        ], [
            'affective_traits.*.distinct' => 'Each trait can appear once.',
            'psychomotor_skills.*.distinct' => 'Each skill can appear once.',
            'rating_scale.*.value.distinct' => 'Each rating can appear once.',
        ]);

        $clean = fn (array $list) => array_values(array_map(fn ($v) => trim($v), $list));

        $overlap = array_intersect(array_map('strtolower', $clean($validated['affective_traits'])), array_map('strtolower', $clean($validated['psychomotor_skills'])));
        if ($overlap !== []) {
            throw ValidationException::withMessages(['psychomotor_skills' => ['"'.reset($overlap).'" is listed as both a trait and a skill.']]);
        }

        $settings = SchoolReportSettings::withoutGlobalScopes()->updateOrCreate(
            ['school_id' => $schoolId],
            [
                'affective_traits' => $clean($validated['affective_traits']),
                'psychomotor_skills' => $clean($validated['psychomotor_skills']),
                'rating_scale' => array_values(array_map(fn ($o) => ['value' => trim($o['value']), 'label' => trim((string) ($o['label'] ?? '')) ?: null], $validated['rating_scale'])),
                'form_teacher_comment' => $validated['form_teacher_comment'],
                'principal_comment' => $validated['principal_comment'],
                'principal_title' => trim($validated['principal_title']),
                'updated_by' => $request->user()->id,
            ],
        );

        return response()->json(['message' => 'Report card settings saved.', 'settings' => $this->describe($settings)]);
    }

    // ----------------------------------------------------------- entries

    /** A class's pupils with their ratings and comments for a term. */
    public function index(Request $request)
    {
        $schoolId = $this->schoolId();

        $validated = $request->validate([
            'term_id' => ['required', Rule::exists('terms', 'id')->where('school_id', $schoolId)],
            'class_id' => ['required', Rule::exists('classes', 'id')->where('school_id', $schoolId)],
            'arm_id' => ['nullable', Rule::exists('arms', 'id')->where('school_id', $schoolId)],
        ]);

        $term = Term::find($validated['term_id']);
        $canForm = $this->canWriteForm($request, $schoolId, $term, (int) $validated['class_id'], $validated['arm_id'] ?? null);
        $isAdmin = $this->isAdmin($request);

        if (! $canForm && ! $isAdmin) {
            return response()->json(['message' => 'Only the form teacher of this class, or an administrator, can enter these.'], 403);
        }

        $students = $this->students($schoolId, (int) $validated['class_id'], $validated['arm_id'] ?? null);
        $entries = StudentTermReport::where('school_id', $schoolId)->where('term_id', $term->id)
            ->whereIn('student_id', $students->pluck('id'))->get()->keyBy('student_id');

        return response()->json([
            'settings' => $this->describe(SchoolReportSettings::forSchool($schoolId)),
            'can_write_form' => $canForm || $isAdmin,
            'can_write_principal' => $isAdmin,
            'students' => $students->map(fn (Student $s) => [
                'student_id' => $s->id,
                'name' => $s->user?->name,
                'admission_number' => $s->admission_number,
                'ratings' => (object) ($entries->get($s->id)?->ratings ?? []),
                'form_teacher_comment' => $entries->get($s->id)?->form_teacher_comment,
                'principal_comment' => $entries->get($s->id)?->principal_comment,
            ])->values(),
        ]);
    }

    /** Save a class's ratings and comments. Rows left out are untouched. */
    public function update(Request $request)
    {
        $schoolId = $this->schoolId();

        $validated = $request->validate([
            'term_id' => ['required', Rule::exists('terms', 'id')->where('school_id', $schoolId)],
            'class_id' => ['required', Rule::exists('classes', 'id')->where('school_id', $schoolId)],
            'arm_id' => ['nullable', Rule::exists('arms', 'id')->where('school_id', $schoolId)],
            'entries' => 'required|array|min:1|max:200',
            'entries.*.student_id' => 'required|integer',
            'entries.*.ratings' => 'nullable|array',
            'entries.*.form_teacher_comment' => 'nullable|string|max:1000',
            'entries.*.principal_comment' => 'nullable|string|max:1000',
        ]);

        $term = Term::find($validated['term_id']);
        $isAdmin = $this->isAdmin($request);
        $canForm = $isAdmin || $this->canWriteForm($request, $schoolId, $term, (int) $validated['class_id'], $validated['arm_id'] ?? null);

        if (! $canForm) {
            return response()->json(['message' => 'Only the form teacher of this class, or an administrator, can enter these.'], 403);
        }

        $settings = SchoolReportSettings::forSchool($schoolId);
        $itemNames = array_column($settings->items(), 'name');
        $values = array_map(fn ($o) => (string) $o['value'], $settings->rating_scale ?? []);
        $inClass = $this->students($schoolId, (int) $validated['class_id'], $validated['arm_id'] ?? null)->pluck('id')->all();

        $errors = [];
        foreach ($validated['entries'] as $i => $entry) {
            if (! in_array((int) $entry['student_id'], $inClass, true)) {
                $errors["entries.{$i}.student_id"] = ['That student is not in this class.'];
            }
            foreach ($entry['ratings'] ?? [] as $name => $value) {
                if (! in_array($name, $itemNames, true)) {
                    $errors["entries.{$i}.ratings"] = ["\"{$name}\" is not one of the school's rated items."];
                } elseif ($value !== null && $value !== '' && ! in_array((string) $value, $values, true)) {
                    $errors["entries.{$i}.ratings"] = ["\"{$value}\" is not on the school's rating scale."];
                }
            }
            if (array_key_exists('principal_comment', $entry) && ! $isAdmin) {
                $errors["entries.{$i}.principal_comment"] = ["Only an administrator writes the {$settings->principal_title}'s comment."];
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        DB::transaction(function () use ($validated, $schoolId, $term, $request) {
            foreach ($validated['entries'] as $entry) {
                $report = StudentTermReport::firstOrNew(['student_id' => $entry['student_id'], 'term_id' => $term->id], ['school_id' => $schoolId]);

                if (array_key_exists('ratings', $entry)) {
                    $report->ratings = array_filter($entry['ratings'] ?? [], fn ($v) => $v !== null && $v !== '');
                }
                if (array_key_exists('form_teacher_comment', $entry)) {
                    $report->form_teacher_comment = trim((string) $entry['form_teacher_comment']) ?: null;
                }
                if (array_key_exists('principal_comment', $entry)) {
                    $report->principal_comment = trim((string) $entry['principal_comment']) ?: null;
                }

                $report->school_id = $schoolId;
                $report->updated_by = $request->user()->id;
                $report->save();
            }
        });

        return response()->json(['message' => count($validated['entries']).' pupil(s) saved.']);
    }

    // ---------------------------------------------------------- internals

    private function describe(SchoolReportSettings $s): array
    {
        return [
            'affective_traits' => $s->affective_traits ?? [],
            'psychomotor_skills' => $s->psychomotor_skills ?? [],
            'rating_scale' => $s->rating_scale ?? [],
            'form_teacher_comment' => (bool) $s->form_teacher_comment,
            'principal_comment' => (bool) $s->principal_comment,
            'principal_title' => $s->principal_title ?? 'Principal',
        ];
    }

    private function students(int $schoolId, int $classId, $armId)
    {
        return Student::where('school_id', $schoolId)
            ->where('class_id', $classId)
            ->when($armId, fn ($q) => $q->where('arm_id', $armId))
            ->where('status', 'active')
            ->with('user:id,name')
            ->get()
            ->sortBy(fn ($s) => $s->user?->name)
            ->values();
    }

    private function isAdmin(Request $request): bool
    {
        return in_array($request->user()?->userProfile?->role, ['school_admin', 'super_admin'], true);
    }

    /** Form teacher or assistant of this class (and arm, if given) in the term's session. */
    private function canWriteForm(Request $request, int $schoolId, Term $term, int $classId, $armId): bool
    {
        $userId = $request->user()->id;

        return ClassTeacherAssignment::withoutGlobalScopes()
            ->where('school_id', $schoolId)
            ->where('session_id', $term->session_id)
            ->where('class_id', $classId)
            ->when($armId, fn ($q) => $q->where(fn ($w) => $w->where('arm_id', $armId)->orWhereNull('arm_id')))
            ->where(fn ($q) => $q->where('form_teacher_id', $userId)->orWhere('assistant_teacher_id', $userId))
            ->exists();
    }
}
