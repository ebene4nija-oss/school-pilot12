<?php

namespace App\Services;

use App\Models\Arm;
use App\Models\Guardian;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\StudentEnrollment;
use App\Models\User;
use App\Models\UserProfile;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Bulk student import, in two phases.
 *
 * A school onboards by uploading the register — one sheet per class, typed by
 * whoever had the time, with the columns in whatever order the last school
 * secretary chose. The previous importer read three fixed positions (name,
 * email, gender), had no column for a class at all, and committed row by row,
 * so a sheet that failed halfway left half a class created and no way to tell
 * which half. Every one of those is addressed here:
 *
 *   - columns are matched by header name, in any order, with aliases
 *   - a class and arm can come from the sheet or be chosen for the whole file
 *   - `analyse` validates and writes nothing; `commit` writes in one
 *     transaction, all rows or none
 */
class StudentImportService
{
    /**
     * Header aliases. Everything is lower-cased and stripped of punctuation
     * before matching, so "Admission No." and "admission_number" land together.
     */
    private const COLUMN_ALIASES = [
        'admission_number' => ['admission number', 'admission no', 'adm no', 'admno', 'admission', 'reg number', 'registration number'],
        'first_name' => ['first name', 'firstname', 'given name'],
        'last_name' => ['last name', 'lastname', 'surname', 'family name'],
        'middle_name' => ['middle name', 'middlename', 'other name', 'other names'],
        'name' => ['name', 'full name', 'fullname', 'student name', 'names'],
        'email' => ['email', 'email address', 'e mail'],
        'gender' => ['gender', 'sex'],
        'date_of_birth' => ['date of birth', 'dob', 'birth date', 'birthdate'],
        'class' => ['class', 'class name', 'level'],
        'arm' => ['arm', 'stream', 'section', 'class arm'],
        'state_of_origin' => ['state of origin', 'state'],
        'lga' => ['lga', 'local government', 'local government area'],
        'religion' => ['religion'],
        'previous_school' => ['previous school', 'former school', 'last school'],
        'guardian_name' => ['guardian name', 'parent name', 'guardian', 'parent'],
        'guardian_phone' => ['guardian phone', 'parent phone', 'phone', 'phone number', 'guardian contact', 'parent contact'],
        'guardian_email' => ['guardian email', 'parent email'],
    ];

    /**
     * Read and validate a file without touching the database.
     *
     * @param  array  $options  default_class_id, default_arm_id, session_id
     */
    public function analyse(UploadedFile $file, int $schoolId, array $options = []): array
    {
        $parsed = $this->readRows($file);

        if (isset($parsed['error'])) {
            return ['fatal' => $parsed['error'], 'rows' => [], 'summary' => []];
        }

        $map = $parsed['map'];
        $classes = SchoolClass::withoutGlobalScopes()->where('school_id', $schoolId)->get();
        $arms = Arm::withoutGlobalScopes()->where('school_id', $schoolId)->get();

        $defaultClassId = $options['default_class_id'] ?? null;
        $defaultArmId = $options['default_arm_id'] ?? null;

        /*
         * Duplicate detection compares against the file as well as the
         * database. A sheet that lists the same child twice used to create two
         * student records, because each row was checked only against what was
         * already stored.
         */
        $existingEmails = User::whereIn('email', $this->columnValues($parsed['rows'], $map, 'email'))
            ->pluck('email')
            ->map(fn ($e) => strtolower($e))
            ->all();

        $existingAdmissions = Student::withoutGlobalScopes()
            ->where('school_id', $schoolId)
            ->whereNotNull('admission_number')
            ->pluck('admission_number')
            ->map(fn ($a) => strtolower(trim($a)))
            ->all();

        $seenEmails = [];
        $seenAdmissions = [];
        $rows = [];

        foreach ($parsed['rows'] as $index => $raw) {
            $lineNumber = $index + 2; // header is line 1
            $value = fn (string $key) => $this->cell($raw, $map, $key);

            $errors = [];

            $name = $this->resolveName($value('name'), $value('first_name'), $value('middle_name'), $value('last_name'));

            if ($name === null) {
                $errors[] = 'A name is required (either a "Name" column, or "First name" and "Surname").';
            }

            // Class: from the sheet if the sheet says, otherwise the one
            // chosen for the whole upload.
            $classId = $defaultClassId;
            $className = $value('class');

            if ($className !== null && $className !== '') {
                $match = $classes->first(fn ($c) => $this->normalise($c->name) === $this->normalise($className));

                if ($match) {
                    $classId = $match->id;
                } else {
                    $errors[] = "No class named \"{$className}\" in this school.";
                    $classId = null;
                }
            }

            /*
             * Placement is required for the preview/commit flow — an import
             * that leaves 400 children unplaced is the problem, not the
             * feature. The legacy `/students/import` endpoint predates class
             * columns entirely, so it opts out and keeps landing students
             * unplaced rather than failing every row of a file that was valid
             * yesterday.
             */
            if (! $classId && ($options['require_class'] ?? true)) {
                $errors[] = 'No class for this row — add a "Class" column or pick a class for the whole file.';
            }

            $armId = $defaultArmId;
            $armName = $value('arm');

            if ($armName !== null && $armName !== '' && $classId) {
                $match = $arms->first(fn ($a) => $a->class_id === $classId
                    && $this->normalise($a->name) === $this->normalise($armName));

                if ($match) {
                    $armId = $match->id;
                } else {
                    $errors[] = "No arm named \"{$armName}\" in that class.";
                    $armId = null;
                }
            }

            // The arm chosen for the file only applies to rows in its class.
            if ($armId && $classId && ! $arms->first(fn ($a) => $a->id === $armId && $a->class_id === $classId)) {
                $armId = null;
            }

            $admission = $value('admission_number');
            $admissionKey = $admission ? strtolower(trim($admission)) : null;

            if ($admissionKey) {
                if (in_array($admissionKey, $existingAdmissions, true)) {
                    $errors[] = "Admission number {$admission} already belongs to a student here.";
                } elseif (in_array($admissionKey, $seenAdmissions, true)) {
                    $errors[] = "Admission number {$admission} appears more than once in this file.";
                } else {
                    $seenAdmissions[] = $admissionKey;
                }
            }

            $email = $value('email');
            $emailKey = $email ? strtolower(trim($email)) : null;

            if ($emailKey) {
                if (! filter_var($emailKey, FILTER_VALIDATE_EMAIL)) {
                    $errors[] = "\"{$email}\" is not a valid email address.";
                } elseif (in_array($emailKey, $existingEmails, true)) {
                    $errors[] = "{$email} is already registered.";
                } elseif (in_array($emailKey, $seenEmails, true)) {
                    $errors[] = "{$email} appears more than once in this file.";
                } else {
                    $seenEmails[] = $emailKey;
                }
            }

            $gender = $this->normaliseGender($value('gender'));
            $dob = $this->normaliseDate($value('date_of_birth'));

            if ($value('date_of_birth') && $dob === null) {
                $errors[] = 'Date of birth is not a date I can read — use YYYY-MM-DD or DD/MM/YYYY.';
            }

            $rows[] = [
                'line' => $lineNumber,
                'status' => empty($errors) ? 'ready' : 'error',
                'errors' => $errors,
                'name' => $name,
                'email' => $emailKey,
                'admission_number' => $admission ?: null,
                'gender' => $gender,
                'date_of_birth' => $dob,
                'class_id' => $classId,
                'arm_id' => $armId,
                'state_of_origin' => $value('state_of_origin'),
                'lga' => $value('lga'),
                'religion' => $value('religion'),
                'previous_school' => $value('previous_school'),
                'guardian_name' => $value('guardian_name'),
                'guardian_phone' => $value('guardian_phone'),
                'guardian_email' => $value('guardian_email'),
            ];
        }

        $ready = array_filter($rows, fn ($r) => $r['status'] === 'ready');

        return [
            'rows' => $rows,
            'recognised_columns' => array_keys($map),
            'unrecognised_columns' => $parsed['unrecognised'],
            'summary' => [
                'total' => count($rows),
                'ready' => count($ready),
                'errors' => count($rows) - count($ready),
            ],
        ];
    }

    /**
     * Create the students. One transaction: either the whole batch lands or
     * nothing does.
     *
     * @param  array  $rows  rows from `analyse`, already validated
     */
    public function commit(array $rows, int $schoolId, ?int $sessionId, int $performedBy): array
    {
        $ready = array_values(array_filter($rows, fn ($r) => ($r['status'] ?? null) === 'ready'));

        if (empty($ready)) {
            return ['created' => 0, 'student_ids' => []];
        }

        $school = School::find($schoolId);
        $emailDomain = ($school->subdomain ?? 'school') . '.local';

        return DB::transaction(function () use ($ready, $schoolId, $sessionId, $performedBy, $emailDomain) {
            $studentIds = [];

            // Guardians are deduplicated by phone across the file: one family
            // with three children is one parent account, not three.
            $guardianCache = [];

            $sequence = (int) Student::withoutGlobalScopes()->where('school_id', $schoolId)->count();

            foreach ($ready as $row) {
                $sequence++;

                $admission = $row['admission_number']
                    ?: sprintf('%s/%04d', now()->format('Y'), $sequence);

                /*
                 * Most Nigerian primary pupils have no email of their own, and
                 * demanding one produced sheets full of invented addresses.
                 * A placeholder on a non-routable domain keeps the unique
                 * constraint on `users.email` satisfied without pretending the
                 * child can be mailed; the parent's address is the one that
                 * matters and it is captured separately.
                 */
                $email = $row['email'] ?: Str::slug($admission) . '@' . $emailDomain;

                $user = User::create([
                    'name' => $row['name'],
                    'email' => $email,
                    /*
                     * Not a hash — a random string, which `Hash::check` can
                     * never match, so the account has no working password
                     * until the student sets one through "Forgot password".
                     * Deliberately not `Hash::make`: bcrypt on a 600-row
                     * register is thirty seconds of CPU inside one request,
                     * which is what used to time these imports out.
                     */
                    'password' => bin2hex(random_bytes(32)),
                ]);

                UserProfile::create([
                    'school_id' => $schoolId,
                    'user_id' => $user->id,
                    'role' => 'student',
                ]);

                $student = Student::create([
                    'school_id' => $schoolId,
                    'user_id' => $user->id,
                    'class_id' => $row['class_id'],
                    'arm_id' => $row['arm_id'],
                    'admission_number' => $admission,
                    'gender' => $row['gender'] ?? 'male',
                    'date_of_birth' => $row['date_of_birth'],
                    'state_of_origin' => $row['state_of_origin'] ?? null,
                    'lga' => $row['lga'] ?? null,
                    'religion' => $row['religion'] ?? null,
                    'previous_school' => $row['previous_school'] ?? null,
                    'status' => 'active',
                ]);

                /*
                 * An imported student joins the session immediately, or the
                 * first rollover after an import would skip the entire intake.
                 * Needs a class: an enrolment is a placement, and the legacy
                 * endpoint can still produce students without one.
                 */
                if ($sessionId && $row['class_id']) {
                    StudentEnrollment::create([
                        'school_id' => $schoolId,
                        'student_id' => $student->id,
                        'session_id' => $sessionId,
                        'class_id' => $row['class_id'],
                        'arm_id' => $row['arm_id'],
                        'status' => 'active',
                        'enrolled_on' => now()->toDateString(),
                        'recorded_by' => $performedBy,
                    ]);
                }

                if (! empty($row['guardian_name'])) {
                    $guardian = $this->resolveGuardian(
                        $row,
                        $schoolId,
                        $emailDomain,
                        $guardianCache,
                        $sequence,
                    );

                    $student->guardians()->attach($guardian->id, ['is_primary' => true]);
                }

                $studentIds[] = $student->id;
            }

            return ['created' => count($studentIds), 'student_ids' => $studentIds];
        });
    }

    /**
     * A parent account per family, keyed on phone number where there is one.
     */
    private function resolveGuardian(
        array $row,
        int $schoolId,
        string $emailDomain,
        array &$cache,
        int $sequence,
    ): Guardian {
        $phone = $row['guardian_phone'] ? preg_replace('/\D+/', '', $row['guardian_phone']) : null;
        $cacheKey = $phone ?: 'name:' . $this->normalise($row['guardian_name']);

        if (isset($cache[$cacheKey])) {
            return $cache[$cacheKey];
        }

        // An existing parent in this school with the same phone is the same
        // person — a second child should attach, not duplicate the account.
        if ($phone) {
            $existing = Guardian::withoutGlobalScopes()
                ->where('school_id', $schoolId)
                ->whereHas('user.userProfile', fn ($q) => $q->where('phone', $phone))
                ->first();

            if ($existing) {
                return $cache[$cacheKey] = $existing;
            }
        }

        $email = $row['guardian_email'] ?: 'guardian-' . ($phone ?: $sequence) . '@' . $emailDomain;

        $user = User::firstOrCreate(
            ['email' => strtolower($email)],
            [
                'name' => $row['guardian_name'],
                'password' => bin2hex(random_bytes(32)),
            ]
        );

        UserProfile::firstOrCreate(
            ['user_id' => $user->id],
            ['school_id' => $schoolId, 'role' => 'parent', 'phone' => $phone]
        );

        $guardian = Guardian::firstOrCreate(
            ['school_id' => $schoolId, 'user_id' => $user->id],
            ['relationship' => 'parent']
        );

        return $cache[$cacheKey] = $guardian;
    }

    // ─── Parsing ────────────────────────────────────────────────────

    /**
     * Rows keyed by recognised column.
     *
     * CSV only. The old validator accepted `xlsx` and then handed the binary
     * to `str_getcsv`, which produced a screenful of nonsense rows and no
     * explanation — an .xlsx is now refused with a sentence saying what to do.
     */
    private function readRows(UploadedFile $file): array
    {
        $extension = strtolower($file->getClientOriginalExtension());

        if (in_array($extension, ['xlsx', 'xls'], true)) {
            return ['error' => 'Excel files are not supported. In Excel choose File → Save As → CSV, then upload that.'];
        }

        $handle = fopen($file->getRealPath(), 'r');

        if ($handle === false) {
            return ['error' => 'That file could not be opened.'];
        }

        $header = fgetcsv($handle);

        if ($header === false) {
            fclose($handle);

            return ['error' => 'The file is empty.'];
        }

        // Excel writes a BOM on the first cell of a CSV export; left in place
        // it makes the first column unrecognisable.
        $header[0] = preg_replace('/^\x{FEFF}/u', '', (string) $header[0]);

        [$map, $unrecognised] = $this->mapHeader($header);

        $rows = [];

        while (($row = fgetcsv($handle)) !== false) {
            // A trailing newline yields a single empty cell, not a student.
            if (count(array_filter($row, fn ($cell) => trim((string) $cell) !== '')) === 0) {
                continue;
            }

            $rows[] = $row;
        }

        fclose($handle);

        return ['map' => $map, 'unrecognised' => $unrecognised, 'rows' => $rows];
    }

    /**
     * @return array{0: array<string,int>, 1: array<int,string>}
     */
    private function mapHeader(array $header): array
    {
        $map = [];
        $unrecognised = [];

        foreach ($header as $position => $label) {
            $normalised = $this->normalise($label);
            $matched = null;

            foreach (self::COLUMN_ALIASES as $field => $aliases) {
                if (in_array($normalised, array_map(fn ($a) => $this->normalise($a), $aliases), true)) {
                    $matched = $field;
                    break;
                }
            }

            if ($matched && ! isset($map[$matched])) {
                $map[$matched] = $position;
            } elseif (! $matched && trim((string) $label) !== '') {
                $unrecognised[] = trim((string) $label);
            }
        }

        /*
         * A sheet with no recognisable header at all is treated as the legacy
         * three-column layout — name, email, gender — which is what the first
         * importer assumed and what the sample files in circulation still
         * look like.
         */
        if (empty($map)) {
            $map = ['name' => 0, 'email' => 1, 'gender' => 2];
        }

        return [$map, $unrecognised];
    }

    private function cell(array $row, array $map, string $field): ?string
    {
        if (! isset($map[$field])) {
            return null;
        }

        $value = $row[$map[$field]] ?? null;
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function columnValues(array $rows, array $map, string $field): array
    {
        if (! isset($map[$field])) {
            return [];
        }

        return array_values(array_filter(array_map(
            fn ($row) => strtolower(trim((string) ($row[$map[$field]] ?? ''))),
            $rows
        )));
    }

    private function resolveName(?string $name, ?string $first, ?string $middle, ?string $last): ?string
    {
        if ($name) {
            return $name;
        }

        $parts = array_filter([$first, $middle, $last]);

        return empty($parts) ? null : implode(' ', $parts);
    }

    private function normalise(?string $value): string
    {
        return trim(preg_replace('/[^a-z0-9]+/', ' ', strtolower((string) $value)));
    }

    private function normaliseGender(?string $value): string
    {
        return match (strtolower(trim((string) $value))) {
            'f', 'female', 'girl' => 'female',
            'm', 'male', 'boy' => 'male',
            default => 'male',
        };
    }

    /**
     * Dates come in as 2015-04-09, 09/04/2015 or 9 April 2015.
     *
     * Day-first for the slashed form: a Nigerian register is written
     * DD/MM/YYYY, and reading 03/04/2015 as March would quietly shift a whole
     * intake's birthdays.
     */
    private function normaliseDate(?string $value): ?string
    {
        if (! $value) {
            return null;
        }

        $value = trim($value);

        foreach (['Y-m-d', 'd/m/Y', 'd-m-Y', 'd/m/y', 'j F Y', 'j M Y'] as $format) {
            $date = \DateTime::createFromFormat('!' . $format, $value);

            if ($date && $date->format($format) === $value) {
                return $date->format('Y-m-d');
            }
        }

        return null;
    }
}
