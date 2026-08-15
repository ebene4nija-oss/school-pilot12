<?php

namespace App\Services\ReportCard;

use App\Models\AttendanceRecord;
use App\Models\ReportCardTemplate;
use App\Models\School;
use App\Models\ScoreEntry;
use App\Models\Student;
use App\Models\Term;
use Illuminate\Support\Str;

/**
 * Import, validation and rendering of school-authored report card designs.
 *
 * A Nigerian private school's report card is a marketing document as much as
 * an academic one: the crest, the motto, the house colours, the affective
 * traits grid the proprietor insists on. Forcing every school onto one layout
 * is the fastest way to lose a sale, so schools code their own against a
 * published contract (docs/report-card-template-contract.md) and import it.
 *
 * The import path is: parse -> render against sample data -> sanitise that
 * output -> report what was stripped -> store as a draft. Nothing goes live
 * until an admin explicitly activates it, and rendering sanitises again on
 * every pass, so an unsafe template is inert even if it slips past import.
 */
class ReportCardTemplateService
{
    public const MAX_BODY_BYTES = 500000;
    public const MAX_STYLES_BYTES = 200000;

    /**
     * A template that omits these is not a SchoolPilot report card.
     *
     * The verification token is non-negotiable: result forgery is endemic and
     * the QR-verifiable report card is a headline feature (doc §7.7). A school
     * cannot design it away, by accident or otherwise.
     */
    private const REQUIRED_TOKENS = [
        'student.name' => 'the student\'s name ({{ student.name }})',
        'scores' => 'the subject score table ({{#each scores}} … {{/each}})',
        'verification.' => 'the result verification QR code ({{ verification.qr_code_url }} or {{ verification.verify_url }})',
    ];

    /** Cheap pre-scan: things that are never an honest mistake. */
    private const HARD_REJECT_PATTERNS = [
        '/<\s*script\b/i' => 'a <script> tag',
        '/<\s*iframe\b/i' => 'an <iframe> tag',
        '/<\s*object\b/i' => 'an <object> tag',
        '/<\s*embed\b/i' => 'an <embed> tag',
        '/\son[a-z]+\s*=/i' => 'an inline event handler (onclick, onerror, …)',
        '/javascript\s*:/i' => 'a javascript: URL',
        '/<\?php/i' => 'PHP code',
        '/@php\b/i' => 'a Blade @php directive',
        '/\{\{\{/' => 'unescaped output ({{{ … }}}), which this engine does not support',
    ];

    public function __construct(
        private TemplateRenderer $renderer,
        private HtmlSanitizer $sanitizer
    ) {
    }

    // ------------------------------------------------------------------
    // Import
    // ------------------------------------------------------------------

    /**
     * Validate and store a template bundle as a draft.
     *
     * @param array $bundle decoded template.json (see the contract doc)
     * @throws TemplateException
     */
    public function import(array $bundle, int $schoolId, ?int $userId = null): ReportCardTemplate
    {
        $engine = $bundle['engine'] ?? null;
        if ($engine !== ReportCardTemplate::ENGINE) {
            throw new TemplateException(sprintf(
                'Bundle declares engine "%s"; this server renders "%s". Update the bundle to the current contract.',
                $engine ?? 'none',
                ReportCardTemplate::ENGINE
            ));
        }

        $name = trim((string) ($bundle['name'] ?? ''));
        if ($name === '') {
            throw new TemplateException('Bundle is missing a "name".');
        }

        $body = (string) ($bundle['body'] ?? '');
        if (trim($body) === '') {
            throw new TemplateException('Bundle is missing a "body" (the template markup).');
        }

        if (strlen($body) > self::MAX_BODY_BYTES) {
            throw new TemplateException('Template body exceeds ' . self::MAX_BODY_BYTES . ' bytes.');
        }

        $styles = (string) ($bundle['styles'] ?? '');
        if (strlen($styles) > self::MAX_STYLES_BYTES) {
            throw new TemplateException('Template stylesheet exceeds ' . self::MAX_STYLES_BYTES . ' bytes.');
        }

        $this->rejectObviouslyUnsafe($body);
        $this->assertRequiredTokens($body);

        // Parse errors surface here, at import, rather than on print night.
        $warnings = $this->renderer->lint($body);

        // Dry run against sample data: proves the template renders, and shows
        // the admin exactly what the sanitiser will strip.
        $sampleData = $bundle['sample_data'] ?? $this->sampleContext();
        $rendered = $this->renderer->render($body, $sampleData);
        $sanitised = $this->sanitizer->sanitize($rendered);
        $styleResult = $this->sanitizer->sanitizeCss($styles, self::MAX_STYLES_BYTES);

        $slug = Str::slug($bundle['slug'] ?? $name);
        $version = (int) ($bundle['version'] ?? 0);

        if ($version < 1) {
            $version = 1 + (int) ReportCardTemplate::withoutGlobalScopes()
                ->where('school_id', $schoolId)
                ->where('slug', $slug)
                ->max('version');
        }

        $existing = ReportCardTemplate::withoutGlobalScopes()
            ->where('school_id', $schoolId)
            ->where('slug', $slug)
            ->where('version', $version)
            ->first();

        if ($existing) {
            throw new TemplateException(sprintf(
                'Version %d of "%s" already exists. Bump the version in the bundle to import a revision.',
                $version,
                $slug
            ));
        }

        $attributes = [
            'school_id' => $schoolId,
            'name' => $name,
            'slug' => $slug,
            'version' => $version,
            'description' => $bundle['description'] ?? null,
            'engine' => ReportCardTemplate::ENGINE,
            'body' => $body,
            'styles' => $styleResult['css'],
            'page_settings' => $this->normalisePageSettings($bundle['page'] ?? $bundle['page_settings'] ?? []),
            'regions' => array_values((array) ($bundle['regions'] ?? [])),
            'validation_report' => [
                'warnings' => $warnings,
                'markup_removals' => $sanitised['removals'],
                'style_removals' => $styleResult['removals'],
                'sample_render_bytes' => strlen($sanitised['html']),
                'validated_at' => now()->toIso8601String(),
            ],
            'checksum' => hash('sha256', $body . '|' . $styleResult['css']),
            'status' => 'draft',
            'imported_by' => $userId,
            'imported_at' => now(),
        ];

        return ReportCardTemplate::create($attributes);
    }

    private function rejectObviouslyUnsafe(string $body): void
    {
        foreach (self::HARD_REJECT_PATTERNS as $pattern => $description) {
            if (preg_match($pattern, $body)) {
                throw new TemplateException(
                    "Template contains {$description}, which is not permitted. Report card templates are markup and CSS only — see the template contract for the supported tags and tokens."
                );
            }
        }
    }

    private function assertRequiredTokens(string $body): void
    {
        $missing = [];

        foreach (self::REQUIRED_TOKENS as $token => $description) {
            if (!str_contains($body, $token)) {
                $missing[] = $description;
            }
        }

        if ($missing !== []) {
            throw new TemplateException(
                'Template is missing required content: ' . implode('; ', $missing) . '.'
            );
        }
    }

    private function normalisePageSettings(array $settings): array
    {
        $size = strtoupper((string) ($settings['size'] ?? 'A4'));
        if (!in_array($size, ['A4', 'A5', 'LETTER', 'LEGAL'], true)) {
            $size = 'A4';
        }

        $orientation = strtolower((string) ($settings['orientation'] ?? 'portrait'));
        if (!in_array($orientation, ['portrait', 'landscape'], true)) {
            $orientation = 'portrait';
        }

        $margins = $settings['margins_mm'] ?? [];

        return [
            'size' => $size,
            'orientation' => $orientation,
            'margins_mm' => [
                'top' => min(50, max(0, (float) ($margins['top'] ?? 10))),
                'right' => min(50, max(0, (float) ($margins['right'] ?? 10))),
                'bottom' => min(50, max(0, (float) ($margins['bottom'] ?? 10))),
                'left' => min(50, max(0, (float) ($margins['left'] ?? 10))),
            ],
        ];
    }

    // ------------------------------------------------------------------
    // Rendering
    // ------------------------------------------------------------------

    /**
     * Render a template to a complete, sanitised HTML document.
     *
     * Sanitisation happens on the *rendered output*, not the stored template.
     * That ordering matters: an HTML parser relocates stray text found inside
     * a <table> but outside a cell, which would silently move a
     * {{#each scores}} tag out of the table it belongs to. Rendering first
     * means the parser only ever sees finished markup. Data is escaped by the
     * renderer on the way in, so nothing from the database can inject markup.
     */
    public function render(?ReportCardTemplate $template, array $data): string
    {
        $body = $template?->body ?? $this->defaultTemplateBody();
        $styles = $template?->styles ?? $this->defaultTemplateStyles();
        $page = $template?->page_settings ?? ['size' => 'A4', 'orientation' => 'portrait', 'margins_mm' => ['top' => 10, 'right' => 10, 'bottom' => 10, 'left' => 10]];

        $rendered = $this->renderer->render($body, $data);
        $sanitised = $this->sanitizer->sanitize($rendered);
        $css = $this->sanitizer->sanitizeCss($styles, self::MAX_STYLES_BYTES)['css'];

        $margins = $page['margins_mm'] ?? [];
        $pageCss = sprintf(
            '@page { size: %s %s; margin: %smm %smm %smm %smm; }',
            strtolower($page['size'] ?? 'A4'),
            $page['orientation'] ?? 'portrait',
            $margins['top'] ?? 10,
            $margins['right'] ?? 10,
            $margins['bottom'] ?? 10,
            $margins['left'] ?? 10
        );

        $title = htmlspecialchars(
            ($data['student']['name'] ?? 'Student') . ' — ' . ($data['term']['name'] ?? 'Report Card'),
            ENT_QUOTES,
            'UTF-8'
        );

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>{$title}</title>
<style>
{$pageCss}
body { font-family: DejaVu Sans, sans-serif; margin: 0; }
{$css}
</style>
</head>
<body>
{$sanitised['html']}
</body>
</html>
HTML;
    }

    /** The active design for a school, or null to fall back to the default. */
    public function activeTemplateFor(int $schoolId): ?ReportCardTemplate
    {
        return ReportCardTemplate::withoutGlobalScopes()
            ->where('school_id', $schoolId)
            ->where('status', 'active')
            ->orderByDesc('version')
            ->first();
    }

    // ------------------------------------------------------------------
    // Data context
    // ------------------------------------------------------------------

    /**
     * Build the documented data model a template renders against.
     *
     * Everything a template can see is assembled here. Nothing else is
     * reachable — there is no way for a template to query for data it was not
     * given, which is what keeps one school's design from touching another's
     * records even if the two ever shared a render process.
     */
    public function buildContext(Student $student, Term $term, array $extra = []): array
    {
        $school = School::find($student->school_id);

        $scores = ScoreEntry::withoutGlobalScopes()
            ->where('student_id', $student->id)
            ->where('term_id', $term->id)
            ->with('subject')
            ->get();

        // Class averages and per-subject positions need the whole class.
        $classmateIds = Student::withoutGlobalScopes()
            ->where('school_id', $student->school_id)
            ->where('class_id', $student->class_id)
            ->pluck('id');

        $classScores = ScoreEntry::withoutGlobalScopes()
            ->where('term_id', $term->id)
            ->whereIn('student_id', $classmateIds)
            ->get();

        $bySubject = $classScores->groupBy('subject_id');

        $rows = [];
        foreach ($scores as $score) {
            $peers = $bySubject->get($score->subject_id, collect());
            $sorted = $peers->sortByDesc('total_score')->values();
            $position = $sorted->search(fn ($item) => $item->student_id === $student->id);

            $rows[] = [
                'subject' => $score->subject->name ?? 'Subject',
                'subject_code' => $score->subject->code ?? null,
                'first_ca' => (float) $score->first_ca,
                'second_ca' => (float) $score->second_ca,
                'ca_total' => (float) $score->first_ca + (float) $score->second_ca,
                'exam' => (float) $score->exam,
                'total' => (float) $score->total_score,
                'grade' => $score->grade,
                'position' => $position === false ? null : $position + 1,
                'class_average' => $peers->count() ? round($peers->avg('total_score'), 2) : null,
                'class_highest' => $peers->count() ? (float) $peers->max('total_score') : null,
                'class_lowest' => $peers->count() ? (float) $peers->min('total_score') : null,
                // Only an approved comment is ever exposed to a template. A
                // pending_approval remark must not reach a printed card.
                'teacher_comment' => $score->ai_comment_status === 'approved' ? $score->teacher_comment : null,
            ];
        }

        $total = $scores->sum('total_score');
        $average = $scores->count() ? round($total / $scores->count(), 2) : 0.0;

        // Overall class position.
        $classTotals = $classScores->groupBy('student_id')
            ->map(fn ($group) => $group->avg('total_score'))
            ->sortDesc()
            ->keys()
            ->values();
        $overallPosition = $classTotals->search($student->id);

        $attendance = $this->attendanceSummary($student, $term);

        /*
         * The card has always had a `comments.class_teacher` slot with no way
         * to say who the class teacher *is*. Now that form teachers are a
         * record, the name can be printed and signed for. Additive: templates
         * that never reference `staff` render exactly as before.
         */
        $formTeacher = \App\Models\ClassTeacherAssignment::formTeacherFor(
            (int) $student->school_id,
            (int) $term->session_id,
            (int) $student->class_id,
            $student->arm_id ? (int) $student->arm_id : null,
        );

        return array_replace_recursive([
            'staff' => [
                'form_teacher' => $formTeacher->name ?? ($extra['form_teacher_name'] ?? ''),
            ],
            'school' => [
                'name' => $school->name ?? '',
                'address' => $school->address ?? '',
                'phone' => $school->phone ?? '',
                'email' => $school->email ?? '',
                'logo_url' => $school->logo_url ?? '',
                'subdomain' => $school->subdomain ?? '',
            ],
            'student' => [
                'name' => $student->user->name ?? '',
                'admission_number' => $student->admission_number,
                'class' => $student->currentClass->name ?? '',
                'arm' => $student->currentArm->name ?? '',
                'gender' => $student->gender,
                'photo_url' => $student->passport_photo_path,
                'position' => $overallPosition === false ? null : $overallPosition + 1,
                'class_size' => $classmateIds->count(),
            ],
            'term' => [
                'name' => $term->name,
                'starts_on' => $term->start_date,
                'ends_on' => $term->end_date,
                'session' => $term->session->name ?? '',
                'next_term_begins' => $extra['next_term_begins'] ?? null,
            ],
            'summary' => [
                'subjects_count' => $scores->count(),
                'total_score' => round((float) $total, 2),
                'average' => $average,
                'overall_grade' => $this->gradeFor($average),
            ],
            'attendance' => $attendance,
            'scores' => $rows,
            'comments' => [
                'class_teacher' => $extra['class_teacher_comment'] ?? null,
                'principal' => $extra['principal_comment'] ?? null,
            ],
            'traits' => $extra['traits'] ?? [],
            'grading_scale' => $extra['grading_scale'] ?? $this->defaultGradingScale(),
            'verification' => $extra['verification'] ?? [],
            'generated_at' => now()->toDateString(),
        ], $extra['overrides'] ?? []);
    }

    private function attendanceSummary(Student $student, Term $term): array
    {
        if (!\Illuminate\Support\Facades\Schema::hasTable('attendance_records')) {
            return ['present' => 0, 'absent' => 0, 'total' => 0, 'percentage' => 0.0];
        }

        $records = AttendanceRecord::withoutGlobalScopes()
            ->where('student_id', $student->id)
            ->where('term_id', $term->id)
            ->get();

        $total = $records->count();
        $present = $records->whereIn('status', ['present', 'late'])->count();

        return [
            'present' => $present,
            'absent' => $total - $present,
            'total' => $total,
            'percentage' => $total ? round(($present / $total) * 100, 1) : 0.0,
        ];
    }

    private function gradeFor(float $score): string
    {
        return match (true) {
            $score >= 75 => 'A1',
            $score >= 70 => 'B2',
            $score >= 65 => 'B3',
            $score >= 60 => 'C4',
            $score >= 55 => 'C5',
            $score >= 50 => 'C6',
            $score >= 45 => 'D7',
            $score >= 40 => 'E8',
            default => 'F9',
        };
    }

    private function defaultGradingScale(): array
    {
        return [
            ['grade' => 'A1', 'range' => '75 - 100', 'remark' => 'Excellent'],
            ['grade' => 'B2', 'range' => '70 - 74', 'remark' => 'Very Good'],
            ['grade' => 'B3', 'range' => '65 - 69', 'remark' => 'Good'],
            ['grade' => 'C4', 'range' => '60 - 64', 'remark' => 'Credit'],
            ['grade' => 'C5', 'range' => '55 - 59', 'remark' => 'Credit'],
            ['grade' => 'C6', 'range' => '50 - 54', 'remark' => 'Credit'],
            ['grade' => 'D7', 'range' => '45 - 49', 'remark' => 'Pass'],
            ['grade' => 'E8', 'range' => '40 - 44', 'remark' => 'Pass'],
            ['grade' => 'F9', 'range' => '0 - 39', 'remark' => 'Fail'],
        ];
    }

    /**
     * Realistic sample data for previews and import dry-runs. Deliberately
     * fictional — a preview must never render a real child's record.
     */
    public function sampleContext(): array
    {
        return [
            'school' => [
                'name' => 'Graceland College',
                'address' => '14 Awolowo Way, Ikeja, Lagos',
                'phone' => '+234 801 234 5678',
                'email' => 'info@graceland.example',
                'logo_url' => '',
                'subdomain' => 'graceland',
            ],
            'student' => [
                'name' => 'Adaeze Nwosu',
                'admission_number' => 'GC/2024/0187',
                'class' => 'JSS 2',
                'arm' => 'Gold',
                'gender' => 'female',
                'photo_url' => '',
                'position' => 3,
                'class_size' => 42,
            ],
            'term' => [
                'name' => 'First Term',
                'starts_on' => '2026-09-14',
                'ends_on' => '2026-12-11',
                'session' => '2026/2027',
                'next_term_begins' => '2027-01-11',
            ],
            'summary' => [
                'subjects_count' => 4,
                'total_score' => 302.0,
                'average' => 75.5,
                'overall_grade' => 'A1',
            ],
            'attendance' => ['present' => 58, 'absent' => 4, 'total' => 62, 'percentage' => 93.5],
            'scores' => [
                ['subject' => 'Mathematics', 'subject_code' => 'MTH', 'first_ca' => 17.0, 'second_ca' => 16.0, 'ca_total' => 33.0, 'exam' => 51.0, 'total' => 84.0, 'grade' => 'A1', 'position' => 2, 'class_average' => 61.4, 'class_highest' => 91.0, 'class_lowest' => 28.0, 'teacher_comment' => 'Strong grasp of algebraic manipulation.'],
                ['subject' => 'English Language', 'subject_code' => 'ENG', 'first_ca' => 15.0, 'second_ca' => 14.0, 'ca_total' => 29.0, 'exam' => 44.0, 'total' => 73.0, 'grade' => 'B2', 'position' => 6, 'class_average' => 64.2, 'class_highest' => 88.0, 'class_lowest' => 35.0, 'teacher_comment' => 'Comprehension is solid; work on essay structure.'],
                ['subject' => 'Basic Science', 'subject_code' => 'BSC', 'first_ca' => 18.0, 'second_ca' => 17.0, 'ca_total' => 35.0, 'exam' => 43.0, 'total' => 78.0, 'grade' => 'A1', 'position' => 1, 'class_average' => 58.9, 'class_highest' => 78.0, 'class_lowest' => 22.0, 'teacher_comment' => null],
                ['subject' => 'Civic Education', 'subject_code' => 'CIV', 'first_ca' => 14.0, 'second_ca' => 13.0, 'ca_total' => 27.0, 'exam' => 40.0, 'total' => 67.0, 'grade' => 'B3', 'position' => 9, 'class_average' => 66.1, 'class_highest' => 85.0, 'class_lowest' => 41.0, 'teacher_comment' => null],
            ],
            'comments' => [
                'class_teacher' => 'A conscientious student who contributes well in class.',
                'principal' => 'A commendable result. Keep it up next term.',
            ],
            'traits' => [
                ['name' => 'Punctuality', 'rating' => 5],
                ['name' => 'Neatness', 'rating' => 4],
                ['name' => 'Cooperation', 'rating' => 5],
            ],
            'grading_scale' => $this->defaultGradingScale(),
            'verification' => [
                'token' => 'SAMPLE-TOKEN-0000',
                'verify_url' => 'https://example.test/api/v1/verify-result/SAMPLE-TOKEN-0000',
                'qr_code_url' => '',
            ],
            'generated_at' => '2026-12-12',
        ];
    }

    // ------------------------------------------------------------------
    // Built-in fallback design
    // ------------------------------------------------------------------

    /**
     * Shipped default. A school that never imports anything still gets a
     * clean, correct report card — and this doubles as the worked example the
     * contract doc points at.
     */
    public function defaultTemplateBody(): string
    {
        return <<<'TPL'
<section class="report">
  <header class="masthead">
    {{#if school.logo_url}}<img class="crest" src="{{ school.logo_url }}" alt="{{ school.name }} crest">{{/if}}
    <div class="masthead-text">
      <h1>{{ school.name | upper }}</h1>
      <p class="address">{{ school.address }}</p>
      <p class="doc-title">{{ term.name }} Report Sheet — {{ term.session }}</p>
    </div>
  </header>

  <table class="bio">
    <tr>
      <th>Name</th><td>{{ student.name }}</td>
      <th>Admission No.</th><td>{{ student.admission_number }}</td>
    </tr>
    <tr>
      <th>Class</th><td>{{ student.class }} {{ student.arm }}</td>
      <th>Position</th><td>{{ student.position | ordinal }} of {{ student.class_size }}</td>
    </tr>
    <tr>
      <th>Attendance</th><td>{{ attendance.present }} / {{ attendance.total }} ({{ attendance.percentage | percent }})</td>
      <th>Average</th><td>{{ summary.average | number:1 }} ({{ summary.overall_grade }})</td>
    </tr>
  </table>

  <table class="scores">
    <thead>
      <tr>
        <th>#</th><th class="subject">Subject</th><th>1st CA</th><th>2nd CA</th>
        <th>Exam</th><th>Total</th><th>Grade</th><th>Pos.</th><th>Class Avg.</th>
      </tr>
    </thead>
    <tbody>
      {{#each scores}}
      <tr>
        <td>{{@number}}</td>
        <td class="subject">{{ this.subject }}</td>
        <td>{{ this.first_ca | number:1 }}</td>
        <td>{{ this.second_ca | number:1 }}</td>
        <td>{{ this.exam | number:1 }}</td>
        <td class="total">{{ this.total | number:1 }}</td>
        <td class="{{ this.grade | grade_colour }}">{{ this.grade }}</td>
        <td>{{ this.position | ordinal | default:"—" }}</td>
        <td>{{ this.class_average | number:1 | default:"—" }}</td>
      </tr>
      {{else}}
      <tr><td colspan="9">No scores have been recorded for this term.</td></tr>
      {{/each}}
    </tbody>
  </table>

  {{#if traits}}
  <table class="traits">
    <caption>Affective &amp; Psychomotor Traits</caption>
    {{#each traits}}
    <tr><th>{{ this.name }}</th><td>{{ this.rating }} / 5</td></tr>
    {{/each}}
  </table>
  {{/if}}

  <div class="comments">
    {{#if comments.class_teacher}}
    <p><strong>Class Teacher:</strong> {{ comments.class_teacher }}</p>
    {{/if}}
    {{#if comments.principal}}
    <p><strong>Principal:</strong> {{ comments.principal }}</p>
    {{/if}}
  </div>

  <table class="key">
    <tr>{{#each grading_scale}}<th>{{ this.grade }}</th>{{/each}}</tr>
    <tr>{{#each grading_scale}}<td>{{ this.range }}</td>{{/each}}</tr>
  </table>

  <footer class="verify">
    {{#if verification.qr_code_url}}
    <img class="qr" src="{{ verification.qr_code_url }}" alt="Result verification QR code">
    {{/if}}
    <p>Verify this result at {{ verification.verify_url }}</p>
    <p class="meta">Issued {{ generated_at | date:"j F Y" }} · Next term begins {{ term.next_term_begins | date:"j F Y" | default:"to be announced" }}</p>
  </footer>
</section>
TPL;
    }

    public function defaultTemplateStyles(): string
    {
        return <<<'CSS'
.report { font-size: 11px; color: #1a1a1a; }
.masthead { display: flex; align-items: center; border-bottom: 3px double #1a1a1a; padding-bottom: 8px; }
.masthead .crest { width: 64px; height: 64px; object-fit: contain; margin-right: 12px; }
.masthead h1 { font-size: 18px; margin: 0; letter-spacing: 1px; }
.masthead .address { margin: 2px 0; font-size: 10px; color: #555; }
.masthead .doc-title { margin: 4px 0 0; font-weight: bold; }
table { width: 100%; border-collapse: collapse; margin-top: 10px; }
.bio th { text-align: left; background: #f2f4f7; width: 14%; }
.bio th, .bio td { border: 1px solid #d0d5dd; padding: 4px 6px; }
.scores th, .scores td { border: 1px solid #d0d5dd; padding: 4px; text-align: center; }
.scores th { background: #f2f4f7; }
.scores .subject { text-align: left; }
.scores .total { font-weight: bold; }
.grade-excellent { background: #e7f6ec; font-weight: bold; }
.grade-very-good { background: #f0f7ea; }
.grade-credit { background: #fdf6e3; }
.grade-pass { background: #fdf0e3; }
.grade-fail { background: #fdeaea; font-weight: bold; }
.traits caption { text-align: left; font-weight: bold; padding: 6px 0 2px; }
.traits th, .traits td { border: 1px solid #d0d5dd; padding: 3px 6px; text-align: left; }
.comments { margin-top: 12px; }
.comments p { margin: 4px 0; }
.key th, .key td { border: 1px solid #d0d5dd; padding: 2px; text-align: center; font-size: 9px; }
.verify { margin-top: 14px; border-top: 1px solid #d0d5dd; padding-top: 8px; text-align: center; font-size: 9px; color: #555; }
.verify .qr { width: 76px; height: 76px; }
CSS;
    }
}
