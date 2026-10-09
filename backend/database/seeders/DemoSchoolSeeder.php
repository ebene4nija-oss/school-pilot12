<?php

namespace Database\Seeders;

use App\Models\GradingScale;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Greenfield Academy: a filled-in school for demos.
 *
 *   php artisan db:seed --class=DemoSchoolSeeder
 *
 * Re-runnable: an existing `greenfield` school is removed first, with every
 * account that belongs to it, so the demo always starts from the same state.
 * Random choices come from a fixed seed, so the same students get the same
 * marks every time.
 *
 * Every account signs in with the password `password`. Emails end in
 * `.test`, which never delivers, so a demo broadcast cannot reach anyone.
 *
 * No health data, birth certificates or photos are seeded (doc §12): a demo
 * has no need for them.
 */
class DemoSchoolSeeder extends Seeder
{
    private const SUBDOMAIN = 'greenfield';

    private const DOMAIN = 'greenfield.test';

    private int $schoolId;

    private string $password;

    private CarbonImmutable $now;

    private const FIRST_NAMES_M = ['Chinedu', 'Tunde', 'Emeka', 'Ibrahim', 'Segun', 'Obinna', 'Kelechi', 'Musa', 'Femi', 'Uche', 'Dapo', 'Yusuf', 'Ikenna', 'Kayode', 'Bayo', 'Chidera', 'Olumide', 'Abdul', 'Tobi', 'Nnamdi'];

    private const FIRST_NAMES_F = ['Adaeze', 'Funmilayo', 'Ngozi', 'Aisha', 'Temitope', 'Chiamaka', 'Bisola', 'Zainab', 'Ifeoma', 'Kemi', 'Amaka', 'Halima', 'Yetunde', 'Nkechi', 'Folake', 'Oluchi', 'Hauwa', 'Tolani', 'Ebele', 'Damilola'];

    private const SURNAMES = ['Okafor', 'Adeyemi', 'Bello', 'Eze', 'Ogunleye', 'Nwosu', 'Abubakar', 'Olawale', 'Okonkwo', 'Adebayo', 'Ibrahim', 'Uchenna', 'Afolabi', 'Chukwu', 'Balogun', 'Obi', 'Lawal', 'Nnaji', 'Salami', 'Ekwueme', 'Oyelaran', 'Danjuma', 'Akinola', 'Onyeka'];

    private const STATES = ['Lagos', 'Ogun', 'Oyo', 'Anambra', 'Enugu', 'Imo', 'Kano', 'Kaduna', 'Edo', 'Delta', 'Rivers', 'Kwara'];

    public function run(): void
    {
        mt_srand(2026);

        $this->now = CarbonImmutable::now();
        $this->password = Hash::make('password');

        DB::transaction(function () {
            $this->removeExisting();
            $this->createSchool();

            $admin = $this->createStaffAccounts();
            [$session, $terms] = $this->createCalendar();
            $current = $terms['First Term'];

            $this->createAssessmentSettings($admin);
            $subjects = $this->createSubjects();
            $classes = $this->createClassesAndArms();
            $teachers = $this->createTeachers($classes, $subjects, $session, $admin);
            $students = $this->createStudentsAndParents($classes, $session);

            $this->enrolInSubjects($students, $classes, $subjects, $current);
            $this->createScores($students, $classes, $subjects, $current, $teachers);
            $this->createTermReports($students, $current, $teachers, $admin);
            $this->releaseResults($classes, $current, $admin);
            $this->createFees($students, $classes, $current);
            $this->createAttendance($students, $current);

            // Nursery and primary: taught by class teachers, with a specialist.
            [$early, $earlyStudents] = $this->createEarlyYears($subjects, $session, $current, $admin, count($students));
            $this->createFees($earlyStudents, $early, $current, count($students));
            $this->createAttendance($earlyStudents, $current);

            $this->createQuestionBank($classes, $early, $subjects);
            $this->createExamTimetable($classes, $early, $subjects, $current);
        });

        $this->command?->info('Greenfield Academy is ready: http://greenfield.localhost (or send X-School-Subdomain: greenfield).');
        $this->command?->table(['Role', 'Email', 'Password'], [
            ['School admin', 'admin@'.self::DOMAIN, 'password'],
            ['Bursar', 'bursar@'.self::DOMAIN, 'password'],
            ['Nurse', 'nurse@'.self::DOMAIN, 'password'],
            ['Teacher (JSS 1 Gold form teacher)', 'teacher@'.self::DOMAIN, 'password'],
            ['Primary 4 Gold class teacher', 'rose.eke@'.self::DOMAIN, 'password'],
            ['Parent (two children)', 'parent@'.self::DOMAIN, 'password'],
            ['Student (JSS 1 Gold)', 'student@'.self::DOMAIN, 'password'],
        ]);
    }

    /**
     * Delete a previous Greenfield and its accounts. Users hang off the school
     * through `user_profiles` rather than a foreign key, so they are collected
     * first; everything else cascades from the school row.
     */
    private function removeExisting(): void
    {
        $school = DB::table('schools')->where('subdomain', self::SUBDOMAIN)->first();

        if (! $school) {
            return;
        }

        $userIds = DB::table('user_profiles')->where('school_id', $school->id)->pluck('user_id');

        DB::table('schools')->where('id', $school->id)->delete();
        DB::table('users')->whereIn('id', $userIds)->delete();
    }

    private function createSchool(): void
    {
        $this->schoolId = DB::table('schools')->insertGetId($this->stamp([
            'name' => 'Greenfield Academy',
            'slug' => self::SUBDOMAIN,
            'subdomain' => self::SUBDOMAIN,
            'email' => 'info@'.self::DOMAIN,
            'phone' => '08012345678',
            'address' => '14 Admiralty Way, Lekki Phase 1, Lagos',
            'latitude' => 6.4478,
            'longitude' => 3.4723,
            'geofence_radius_meters' => 250,
            'status' => 'active',
            'is_platform' => false,
        ]));
    }

    private function createStaffAccounts(): int
    {
        $admin = $this->user('Mrs Grace Adebayo', 'admin', 'school_admin', '08030000001', 'female');
        $bursar = $this->user('Mr Paul Nwachukwu', 'bursar', 'bursar', '08030000002', 'male');
        $nurse = $this->user('Nurse Rita Okoro', 'nurse', 'nurse', '08030000003', 'female');

        foreach ([[$admin, 'GFA/ST/001', 'Principal', 450000], [$bursar, 'GFA/ST/002', 'Bursar', 220000], [$nurse, 'GFA/ST/003', 'School Nurse', 180000]] as [$id, $staffNo, $role, $salary]) {
            $this->staff($id, $staffNo, $role, $salary, 'B.Sc.');
        }

        return $admin;
    }

    /** @return array{0: int, 1: array<string, int>} */
    private function createCalendar(): array
    {
        $session = DB::table('academic_sessions')->insertGetId($this->stamp([
            'school_id' => $this->schoolId,
            'name' => '2026/2027',
            'start_date' => '2026-09-14',
            'end_date' => '2027-07-23',
            'is_current' => true,
        ]));

        $terms = [];
        foreach ([
            ['First Term', '2026-09-14', '2026-12-18', true],
            ['Second Term', '2027-01-11', '2027-04-09', false],
            ['Third Term', '2027-04-26', '2027-07-23', false],
        ] as [$name, $start, $end, $current]) {
            $terms[$name] = DB::table('terms')->insertGetId($this->stamp([
                'school_id' => $this->schoolId,
                'session_id' => $session,
                'name' => $name,
                'start_date' => $start,
                'end_date' => $end,
                'is_current' => $current,
            ]));
        }

        return [$session, $terms];
    }

    private function createAssessmentSettings(int $admin): void
    {
        DB::table('ca_schemes')->insert($this->stamp([
            'school_id' => $this->schoolId,
            'name' => 'Standard 20/20/60',
            'first_ca_weight' => 20,
            'second_ca_weight' => 20,
            'exam_weight' => 60,
            'is_default' => true,
            'components' => json_encode([
                ['key' => 'ca1', 'name' => '1st CA', 'max' => 20, 'kind' => 'ca'],
                ['key' => 'ca2', 'name' => '2nd CA', 'max' => 20, 'kind' => 'ca'],
                ['key' => 'exam', 'name' => 'Exam', 'max' => 60, 'kind' => 'exam'],
            ]),
        ]));

        DB::table('grading_scales')->insert($this->stamp([
            'school_id' => $this->schoolId,
            'level' => 'default',
            'name' => 'WAEC',
            'bands' => json_encode(GradingScale::WAEC),
            'updated_by' => $admin,
        ]));

        DB::table('school_report_settings')->insert($this->stamp([
            'school_id' => $this->schoolId,
            'affective_traits' => json_encode(['Punctuality', 'Neatness', 'Politeness', 'Honesty', 'Relationship with others']),
            'psychomotor_skills' => json_encode(['Handwriting', 'Sports', 'Drawing and painting', 'Musical skills']),
            'rating_scale' => json_encode([
                ['value' => '5', 'label' => 'Excellent'],
                ['value' => '4', 'label' => 'Very good'],
                ['value' => '3', 'label' => 'Good'],
                ['value' => '2', 'label' => 'Fair'],
                ['value' => '1', 'label' => 'Poor'],
            ]),
            'form_teacher_comment' => true,
            'principal_comment' => true,
            'principal_title' => 'Principal',
            'updated_by' => $admin,
        ]));
    }

    /** @return array{junior: array<string, int>, senior: array<string, int>} */
    /**
     * The subject list, each tagged with the levels it is taught at, so a
     * Nursery 2 picker shows Number Work and never Physics. Mathematics and
     * English run from nursery to SS 3 and carry no tag.
     */
    private function createSubjects(): array
    {
        $pj = ['primary', 'junior_secondary'];
        $pjs = ['primary', 'junior_secondary', 'senior_secondary'];
        $ss = ['senior_secondary'];

        // name => [code, category, department, levels]
        $all = [
            'Mathematics' => ['MTH', 'core', null, null],
            'English Language' => ['ENG', 'core', null, null],
            'Basic Science' => ['BSC', 'core', null, $pj],
            'Basic Technology' => ['BTE', 'core', null, ['junior_secondary']],
            'Social Studies' => ['SOS', 'core', null, $pj],
            'Civic Education' => ['CVE', 'core', null, $pjs],
            'Business Studies' => ['BUS', 'core', null, ['junior_secondary']],
            'Computer Studies' => ['CMP', 'core', null, $pjs],
            'Christian Religious Studies' => ['CRS', 'elective', null, $pj],
            'Yoruba' => ['YOR', 'elective', null, $pj],
            'Physics' => ['PHY', 'core', 'science', $ss],
            'Chemistry' => ['CHM', 'core', 'science', $ss],
            'Biology' => ['BIO', 'core', 'science', $ss],
            'Economics' => ['ECO', 'core', 'commercial', $ss],
            'Government' => ['GOV', 'core', 'arts', $ss],
            'Literature in English' => ['LIT', 'core', 'arts', $ss],
            'Number Work' => ['NUM', 'core', null, ['nursery']],
            'Letter Work' => ['LET', 'core', null, ['nursery']],
            'Rhymes and Songs' => ['RHY', 'core', null, ['nursery']],
            'Health Habits' => ['HHB', 'core', null, ['nursery']],
            'Verbal Reasoning' => ['VRB', 'core', null, ['primary']],
            'Quantitative Reasoning' => ['QTR', 'core', null, ['primary']],
            'French' => ['FRE', 'core', null, $pj],
        ];

        $ids = [];
        foreach ($all as $name => [$code, $category, $department, $levels]) {
            $ids[$name] = DB::table('subjects')->insertGetId($this->stamp([
                'school_id' => $this->schoolId,
                'name' => $name,
                'code' => $code,
                'category' => $category,
                'department' => $department,
                'level_groups' => $levels ? json_encode($levels) : null,
                'is_active' => true,
            ]));
        }

        $pick = fn (array $names) => array_intersect_key($ids, array_flip($names));

        return [
            'junior' => $pick(['Mathematics', 'English Language', 'Basic Science', 'Basic Technology', 'Social Studies', 'Civic Education', 'Business Studies', 'Computer Studies', 'Christian Religious Studies', 'Yoruba']),
            'senior' => $pick(['Mathematics', 'English Language', 'Civic Education', 'Computer Studies', 'Physics', 'Chemistry', 'Biology', 'Economics', 'Government', 'Literature in English']),
            'nursery' => $pick(['Number Work', 'Letter Work', 'Rhymes and Songs', 'Health Habits', 'English Language']),
            'primary' => $pick(['Mathematics', 'English Language', 'Basic Science', 'Social Studies', 'Verbal Reasoning', 'Quantitative Reasoning', 'French', 'Computer Studies']),
        ];
    }

    /** Which SS streams take a subject; null = every arm. */
    private const STREAM_SUBJECTS = [
        'Physics' => ['science'],
        'Chemistry' => ['science'],
        'Economics' => ['arts'],
        'Government' => ['arts'],
        'Literature in English' => ['arts'],
    ];

    /**
     * @return array<int, array{id: int, name: string, level: string, arms: array<string, int>}>
     */
    private function createClassesAndArms(): array
    {
        $classes = [];
        foreach (['JSS 1', 'JSS 2', 'JSS 3', 'SS 1', 'SS 2', 'SS 3'] as $i => $name) {
            $level = str_starts_with($name, 'JSS') ? 'junior_secondary' : 'senior_secondary';

            $id = DB::table('classes')->insertGetId($this->stamp([
                'school_id' => $this->schoolId,
                'name' => $name,
                'level_category' => $level,
                // After Nursery 2 and Primary 4.
                'order_index' => $i + 3,
                'is_exit_class' => $name === 'SS 3',
            ]));

            $arms = [];
            foreach (['Gold', 'Blue'] as $arm) {
                $arms[$arm] = DB::table('arms')->insertGetId($this->stamp([
                    'school_id' => $this->schoolId,
                    'class_id' => $id,
                    'name' => $arm,
                    // SS is streamed: Gold the sciences, Blue the arts.
                    'stream' => $level === 'senior_secondary' ? ($arm === 'Gold' ? 'science' : 'arts') : null,
                    // JSS 2 Gold keeps the year's strongest students (staff only).
                    'is_merit' => $name === 'JSS 2' && $arm === 'Gold',
                ]));
            }

            $classes[] = ['id' => $id, 'name' => $name, 'level' => $level, 'arms' => $arms];
        }

        return $classes;
    }

    /**
     * Twenty-four teachers: twelve take the Gold arms and twelve the Blue
     * arms, each teaching one or two subjects across the arms of their level.
     * Each arm is taught on its own, so a school with twelve arms needs two
     * teachers per subject to keep within a teacher's day. The first twelve
     * are also the form teachers, one per arm.
     *
     * @return array{by_subject: array<string, array<int, int>>, form: array<int, int>}
     */
    private function createTeachers(array $classes, array $subjects, int $session, int $admin): array
    {
        // [name, gender, level taught, subjects]
        $roster = [
            ['Mr Chidi Okeke', 'male', 'junior', ['Mathematics']],
            ['Mrs Funke Alabi', 'female', 'junior', ['English Language']],
            ['Mr Sani Garba', 'male', 'junior', ['Basic Science', 'Basic Technology']],
            ['Mrs Joy Ekpo', 'female', 'junior', ['Social Studies', 'Civic Education']],
            ['Miss Tosin Ajayi', 'female', 'junior', ['Business Studies', 'Computer Studies']],
            ['Mr Kunle Oladipo', 'male', 'junior', ['Christian Religious Studies', 'Yoruba']],
            ['Mr Victor Eze', 'male', 'senior', ['Mathematics', 'Physics']],
            ['Mrs Ngozi Umeh', 'female', 'senior', ['English Language', 'Literature in English']],
            ['Dr Bola Hassan', 'female', 'senior', ['Chemistry', 'Biology']],
            ['Mr Ahmed Yusuf', 'male', 'senior', ['Economics', 'Government']],
            ['Mrs Esther Nwankwo', 'female', 'senior', ['Civic Education']],
            ['Mr David Obi', 'male', 'senior', ['Computer Studies']],
        ];

        // The Blue arms' subject teachers.
        $blue = [
            ['Mrs Halima Bello', 'female', 'junior', ['Mathematics']],
            ['Mr Tunde Bakare', 'male', 'junior', ['English Language']],
            ['Mrs Amaka Obi', 'female', 'junior', ['Basic Science', 'Basic Technology']],
            ['Mr Musa Danjuma', 'male', 'junior', ['Social Studies', 'Civic Education']],
            ['Mrs Bisi Adeyemi', 'female', 'junior', ['Business Studies', 'Computer Studies']],
            ['Mrs Grace Udoh', 'female', 'junior', ['Christian Religious Studies', 'Yoruba']],
            ['Mrs Ifeoma Nnaji', 'female', 'senior', ['Mathematics', 'Physics']],
            ['Mr Segun Afolabi', 'male', 'senior', ['English Language', 'Literature in English']],
            ['Mr Emeka Okafor', 'male', 'senior', ['Chemistry', 'Biology']],
            ['Mrs Zainab Lawal', 'female', 'senior', ['Economics', 'Government']],
            ['Mr Peter Akpan', 'male', 'senior', ['Civic Education']],
            ['Miss Kemi Ogunleye', 'female', 'senior', ['Computer Studies']],
        ];

        $bySubject = ['Gold' => ['junior' => [], 'senior' => []], 'Blue' => ['junior' => [], 'senior' => []]];
        $teacherIds = [];

        foreach (array_merge(array_map(fn ($t) => [...$t, 'Gold'], $roster), array_map(fn ($t) => [...$t, 'Blue'], $blue)) as $i => [$name, $gender, $level, $taught, $team]) {
            // The first teacher is the one a demo signs in as.
            $handle = $i === 0 ? 'teacher' : Str::slug(Str::after($name, ' '), '.');
            $id = $this->user($name, $handle, 'teacher', sprintf('0803100%04d', $i + 1), $gender);
            $teacherIds[] = $id;

            $this->staff($id, sprintf('GFA/ST/%03d', $i + 10), 'Teacher', 250000 + 10000 * ($i % 4), 'B.Ed.');

            DB::table('teacher_profiles')->insert($this->stamp([
                'school_id' => $this->schoolId,
                'user_id' => $id,
                'employee_id' => sprintf('GFA/ST/%03d', $i + 10),
                'qualification' => 'B.Ed.',
                'specialization' => $taught[0],
                'years_of_experience' => 3 + $i,
                'subjects_taught' => json_encode($taught),
                'date_joined' => '2020-09-01',
                'contract_type' => 'full_time',
            ]));

            foreach ($taught as $subject) {
                $bySubject[$team][$level][$subjects[$level][$subject]] = $id;
            }
        }

        $form = [];
        $armIndex = 0;

        foreach ($classes as $class) {
            $level = $class['level'] === 'junior_secondary' ? 'junior' : 'senior';

            foreach ($subjects[$level] as $name => $subjectId) {
                $streams = self::STREAM_SUBJECTS[$name] ?? null;

                DB::table('class_subjects')->insert($this->stamp([
                    'school_id' => $this->schoolId,
                    'class_id' => $class['id'],
                    'subject_id' => $subjectId,
                    'is_compulsory' => ! in_array($name, ['Christian Religious Studies', 'Yoruba'], true),
                    'streams' => $streams ? json_encode($streams) : null,
                ]));

                // Each arm has its own teacher for the subject — only where
                // the arm's stream takes it.
                foreach ($class['arms'] as $armName => $armId) {
                    if ($streams && $level === 'senior' && ! in_array($armName === 'Gold' ? 'science' : 'arts', $streams, true)) {
                        continue;
                    }

                    DB::table('teacher_subjects')->insert($this->stamp([
                        'school_id' => $this->schoolId,
                        'teacher_id' => $bySubject[$armName][$level][$subjectId],
                        'subject_id' => $subjectId,
                        'class_id' => $class['id'],
                        'arm_id' => $armId,
                        'is_primary' => true,
                    ]));
                }
            }

            foreach ($class['arms'] as $armId) {
                $form[$armId] = $teacherIds[$armIndex++];

                DB::table('class_teacher_assignments')->insert($this->stamp([
                    'school_id' => $this->schoolId,
                    'session_id' => $session,
                    'class_id' => $class['id'],
                    'arm_id' => $armId,
                    'form_teacher_id' => $form[$armId],
                    'assigned_by' => $admin,
                ]));
            }
        }

        return ['by_subject' => $bySubject, 'form' => $form];
    }

    /**
     * Eight students per arm. Most families have one child here; every fourth
     * family has two, so the parent portal's child switcher has something to
     * switch between.
     *
     * @return array<int, array{id: int, user_id: int, name: string, class: array, arm_id: int, track: string}>
     */
    private function createStudentsAndParents(array $classes, int $session): array
    {
        $students = [];
        $admissionNo = 1;
        $family = null;
        $familyIndex = 0;

        foreach ($classes as $classIndex => $class) {
            foreach ($class['arms'] as $armName => $armId) {
                for ($n = 0; $n < 8; $n++) {
                    $gender = mt_rand(0, 1) ? 'male' : 'female';
                    $first = $this->pick($gender === 'male' ? self::FIRST_NAMES_M : self::FIRST_NAMES_F);

                    // A sibling joins the previous family's surname and parent.
                    // The pattern starts with a pair, so the demo parent (the
                    // first family) has two children.
                    $isSibling = $family !== null && $familyIndex % 4 === 1;
                    if (! $isSibling) {
                        $family = ['surname' => $this->pick(self::SURNAMES), 'guardian' => null];
                    }
                    $familyIndex++;

                    $name = "{$first} {$family['surname']}";
                    $number = sprintf('GFA/2026/%04d', $admissionNo);
                    $isDemoStudent = $classIndex === 0 && $armName === 'Gold' && $n === 0;

                    $handle = $isDemoStudent ? 'student' : strtolower('gfa'.sprintf('%04d', $admissionNo));
                    $userId = $this->user($name, $handle, 'student', null, $gender);
                    $admissionNo++;

                    // Ages run 11 in JSS 1 to 16 in SS 3.
                    $birthYear = 2015 - $classIndex;

                    $studentId = DB::table('students')->insertGetId($this->stamp([
                        'school_id' => $this->schoolId,
                        'user_id' => $userId,
                        'class_id' => $class['id'],
                        'arm_id' => $armId,
                        'admission_number' => $number,
                        'date_of_birth' => sprintf('%d-%02d-%02d', $birthYear, mt_rand(1, 12), mt_rand(1, 28)),
                        'gender' => $gender,
                        'state_of_origin' => $this->pick(self::STATES),
                        'religion' => mt_rand(0, 2) ? 'Christianity' : 'Islam',
                        'status' => 'active',
                    ]));

                    DB::table('student_enrollments')->insert($this->stamp([
                        'school_id' => $this->schoolId,
                        'student_id' => $studentId,
                        'session_id' => $session,
                        'class_id' => $class['id'],
                        'arm_id' => $armId,
                        'status' => 'active',
                        'enrolled_on' => '2026-09-14',
                    ]));

                    DB::table('student_gamifications')->insertOrIgnore($this->stamp([
                        'school_id' => $this->schoolId,
                        'student_id' => $studentId,
                    ]));

                    if ($family['guardian'] === null) {
                        $family['guardian'] = $this->createParent($family['surname'], $isDemoStudent);
                    }

                    DB::table('student_guardian')->insert($this->stamp([
                        'student_id' => $studentId,
                        'guardian_id' => $family['guardian'],
                        'is_primary' => true,
                    ]));

                    $students[] = [
                        'id' => $studentId,
                        'user_id' => $userId,
                        'name' => $name,
                        'first' => $first,
                        'class' => $class,
                        'arm_id' => $armId,
                        // Seniors follow their arm's stream: Gold sciences, Blue arts.
                        'track' => $armName === 'Gold' ? 'science' : 'arts',
                    ];
                }
            }
        }

        return $students;
    }

    private function createParent(string $surname, bool $isDemoParent): int
    {
        static $count = 0;
        $count++;

        $isFather = mt_rand(0, 1) === 1;
        $first = $this->pick($isFather ? self::FIRST_NAMES_M : self::FIRST_NAMES_F);
        $title = $isFather ? 'Mr' : 'Mrs';
        $phone = sprintf('0806%07d', 1000000 + $count);

        $handle = $isDemoParent ? 'parent' : sprintf('parent%03d', $count);
        $userId = $this->user("{$title} {$first} {$surname}", $handle, 'parent', $phone, $isFather ? 'male' : 'female');

        $occupation = $this->pick(['Engineer', 'Trader', 'Banker', 'Civil servant', 'Doctor', 'Lawyer', 'Teacher', 'Business owner', 'Accountant', 'Pharmacist']);

        DB::table('parent_profiles')->insert($this->stamp([
            'school_id' => $this->schoolId,
            'user_id' => $userId,
            'occupation' => $occupation,
            'relationship_to_student' => $isFather ? 'Father' : 'Mother',
            'preferred_contact_method' => 'sms',
        ]));

        return DB::table('guardians')->insertGetId($this->stamp([
            'school_id' => $this->schoolId,
            'user_id' => $userId,
            'occupation' => $occupation,
            'relationship' => $isFather ? 'father' : 'mother',
        ]));
    }

    /** Which subjects a student sits: all of JSS; the core plus one track in SS. */
    private function subjectsFor(array $student, array $subjects): array
    {
        if ($student['class']['level'] === 'junior_secondary') {
            return $subjects['junior'];
        }

        $drop = $student['track'] === 'science'
            ? ['Economics', 'Government', 'Literature in English']
            : ['Physics', 'Chemistry'];

        return array_diff_key($subjects['senior'], array_flip($drop));
    }

    private function enrolInSubjects(array $students, array $classes, array $subjects, int $term): void
    {
        $rows = [];
        foreach ($students as $student) {
            foreach ($this->subjectsFor($student, $subjects) as $subjectId) {
                $rows[] = $this->stamp([
                    'school_id' => $this->schoolId,
                    'student_id' => $student['id'],
                    'subject_id' => $subjectId,
                    'term_id' => $term,
                    'enrollment_type' => 'compulsory',
                    'status' => 'active',
                    'enrolled_at' => $this->now,
                ]);
            }
        }

        foreach (array_chunk($rows, 200) as $chunk) {
            DB::table('student_subjects')->insert($chunk);
        }
    }

    /**
     * A full term of marks. Each student has an underlying ability, so a pupil
     * who is strong in Mathematics is usually strong elsewhere, which is what
     * makes a broadsheet look like a real one.
     *
     * Subject remarks are teacher-approved, except a handful still waiting in
     * `pending_approval` so the review queue has something in it.
     */
    private function createScores(array $students, array $classes, array $subjects, int $term, array $teachers): void
    {
        $ability = [];
        foreach ($students as $student) {
            $ability[$student['id']] = mt_rand(35, 92);
        }

        $rows = [];
        $pending = 0;

        foreach ($students as $student) {
            foreach ($this->subjectsFor($student, $subjects) as $subjectName => $subjectId) {
                $target = max(15, min(99, $ability[$student['id']] + mt_rand(-12, 12)));

                $ca1 = $this->mark($target, 20);
                $ca2 = $this->mark($target, 20);
                $exam = $this->mark($target, 60);
                $total = $ca1 + $ca2 + $exam;
                [$grade, $remark] = $this->grade($total);

                $status = 'approved';
                $review = mt_rand(0, 3) === 0 ? 'edited' : 'approved_as_is';
                if ($pending < 15 && $student['class']['name'] === 'JSS 1' && mt_rand(0, 3) === 0) {
                    $status = 'pending_approval';
                    $review = null;
                    $pending++;
                }

                $rows[] = $this->stamp([
                    'school_id' => $this->schoolId,
                    'term_id' => $term,
                    'student_id' => $student['id'],
                    'subject_id' => $subjectId,
                    'first_ca' => $ca1,
                    'second_ca' => $ca2,
                    'exam' => $exam,
                    'total_score' => $total,
                    'grade' => $grade,
                    'component_scores' => json_encode(['ca1' => $ca1, 'ca2' => $ca2, 'exam' => $exam]),
                    'teacher_comment' => $this->subjectRemark($student['first'], $subjectName, $total, $remark),
                    'ai_comment_status' => $status,
                    'ai_comment_review' => $review,
                    'ai_comment_generated_at' => $this->now->subDays(3),
                    'ai_comment_reviewed_at' => $status === 'approved' ? $this->now->subDays(2) : null,
                    // Filled in below, once every mark in the arm is known.
                    'position_in_class' => null,
                    '_arm' => $student['arm_id'],
                ]);
            }
        }

        // Rank within each arm, per subject: 1st, 2nd, … with ties sharing.
        $groups = [];
        foreach ($rows as $i => $row) {
            $groups[$row['_arm'].'-'.$row['subject_id']][] = $i;
        }
        foreach ($groups as $indexes) {
            usort($indexes, fn ($a, $b) => $rows[$b]['total_score'] <=> $rows[$a]['total_score']);
            $previous = null;
            $position = 0;
            foreach ($indexes as $rank => $i) {
                if ($rows[$i]['total_score'] !== $previous) {
                    $position = $rank + 1;
                    $previous = $rows[$i]['total_score'];
                }
                $rows[$i]['position_in_class'] = $position;
            }
        }

        $rows = array_map(function ($row) {
            unset($row['_arm']);

            return $row;
        }, $rows);

        foreach (array_chunk($rows, 100) as $chunk) {
            DB::table('score_entries')->insert($chunk);
        }
    }

    private function createTermReports(array $students, int $term, array $teachers, int $admin): void
    {
        $traits = ['Punctuality', 'Neatness', 'Politeness', 'Honesty', 'Relationship with others', 'Handwriting', 'Sports', 'Drawing and painting', 'Musical skills'];

        $formComments = [
            '%s is a diligent and well-behaved pupil. Keep it up.',
            '%s has made steady progress this term and participates well in class.',
            '%s is capable of more. Better attention in class will help.',
            '%s is cheerful and helpful to classmates. A good term overall.',
            '%s should spend more time on revision at home.',
        ];

        $principalComments = [
            'A good result. Keep working hard.',
            'Satisfactory. There is room for improvement.',
            'An excellent performance. Well done.',
            'Work harder next term.',
        ];

        $rows = [];
        foreach ($students as $student) {
            $ratings = [];
            foreach ($traits as $trait) {
                $ratings[$trait] = (string) mt_rand(3, 5);
            }

            $rows[] = $this->stamp([
                'school_id' => $this->schoolId,
                'student_id' => $student['id'],
                'term_id' => $term,
                'ratings' => json_encode($ratings),
                'form_teacher_comment' => sprintf($this->pick($formComments), $student['first']),
                'principal_comment' => $this->pick($principalComments),
                'updated_by' => $teachers['form'][$student['arm_id']],
            ]);
        }

        foreach (array_chunk($rows, 100) as $chunk) {
            DB::table('student_term_reports')->insert($chunk);
        }
    }

    /** Results are out for every class except SS 3, so both states can be shown. */
    private function releaseResults(array $classes, int $term, int $admin): void
    {
        foreach ($classes as $class) {
            if ($class['name'] === 'SS 3') {
                continue;
            }

            DB::table('result_releases')->insert($this->stamp([
                'school_id' => $this->schoolId,
                'term_id' => $term,
                'class_id' => $class['id'],
                'is_released' => true,
                'released_by' => $admin,
                'released_at' => $this->now->subDay(),
            ]));
        }
    }

    /**
     * School fees for the term: tuition by level plus two levies, invoiced to
     * every student. About half have paid in full, a quarter in part, and a
     * quarter not at all, which is what a bursar's dashboard looks like three
     * weeks into term.
     */
    private function createFees(array $students, array $classes, int $term, int $offset = 0): void
    {
        $structures = [];
        foreach ($classes as $class) {
            $tuition = match ($class['level']) {
                'nursery' => 120000,
                'primary' => 150000,
                'junior_secondary' => 185000,
                default => 210000,
            };

            foreach ([
                ["{$class['name']} Tuition", $tuition, true],
                ["{$class['name']} ICT and Laboratory Levy", 15000, true],
                ["{$class['name']} PTA Levy", 5000, false],
            ] as [$title, $amount, $mandatory]) {
                $structures[$class['id']][] = [
                    'id' => DB::table('fee_structures')->insertGetId($this->stamp([
                        'school_id' => $this->schoolId,
                        'term_id' => $term,
                        'class_id' => $class['id'],
                        'title' => $title,
                        'amount' => $amount,
                        'is_mandatory' => $mandatory,
                    ])),
                    'title' => $title,
                    'amount' => $amount,
                ];
            }
        }

        foreach ($students as $i => $student) {
            $lines = $structures[$student['class']['id']];
            $total = array_sum(array_column($lines, 'amount'));

            $paid = match (true) {
                $i % 4 === 0 || $i % 4 === 1 => $total,
                $i % 4 === 2 => (int) round($total * mt_rand(30, 70) / 100, -3),
                default => 0,
            };

            $invoiceId = DB::table('invoices')->insertGetId($this->stamp([
                'school_id' => $this->schoolId,
                'term_id' => $term,
                'student_id' => $student['id'],
                'invoice_number' => sprintf('GFA-INV-2627-1-%04d', $offset + $i + 1),
                'total_amount' => $total,
                'amount_paid' => $paid,
                'status' => $paid === 0 ? 'unpaid' : ($paid >= $total ? 'paid' : 'partial'),
                'due_date' => '2026-10-09',
            ]));

            foreach ($lines as $line) {
                DB::table('invoice_items')->insert($this->stamp([
                    'school_id' => $this->schoolId,
                    'invoice_id' => $invoiceId,
                    'fee_structure_id' => $line['id'],
                    'title' => $line['title'],
                    'amount' => $line['amount'],
                ]));
            }

            if ($paid > 0) {
                DB::table('payments')->insert($this->stamp([
                    'school_id' => $this->schoolId,
                    'invoice_id' => $invoiceId,
                    'reference' => sprintf('GFA-DEMO-%06d', $offset + $i + 1),
                    'amount' => $paid,
                    'gateway' => $this->pick(['paystack', 'paystack', 'flutterwave', 'bank_transfer', 'cash']),
                    'status' => 'successful',
                    'paid_at' => $this->now->subDays(mt_rand(1, 14)),
                ]));
            }
        }
    }

    /** The last ten school days, up to today: mostly present, a few late or absent. */
    private function createAttendance(array $students, int $term): void
    {
        $days = [];
        for ($d = $this->now->startOfDay(); count($days) < 10; $d = $d->subDay()) {
            if (! $d->isWeekend() && $d->gte(CarbonImmutable::parse('2026-09-14'))) {
                $days[] = $d->toDateString();
            }
            if ($d->lt(CarbonImmutable::parse('2026-09-14'))) {
                break;
            }
        }

        $rows = [];
        foreach ($students as $student) {
            foreach ($days as $day) {
                $roll = mt_rand(1, 100);
                $rows[] = $this->stamp([
                    'school_id' => $this->schoolId,
                    'term_id' => $term,
                    'student_id' => $student['id'],
                    'date' => $day,
                    'status' => $roll <= 88 ? 'present' : ($roll <= 94 ? 'late' : ($roll <= 98 ? 'absent' : 'excused')),
                ]);
            }
        }

        foreach (array_chunk($rows, 200) as $chunk) {
            DB::table('attendance_records')->insert($chunk);
        }
    }

    /**
     * Nursery 2 and Primary 4 (two arms). Each arm's class teacher teaches
     * every subject, which is how the school's nursery and primary sections
     * are set to work; Primary 4 has a French specialist who takes French
     * from both class teachers. Eight pupils an arm, with parents.
     *
     * @return array{0: array<int, array>, 1: array<int, array>} classes, students
     */
    private function createEarlyYears(array $subjects, int $session, int $term, int $admin, int $admissionOffset): array
    {
        $classes = [];
        foreach ([['Nursery 2', 'nursery', ['Gold']], ['Primary 4', 'primary', ['Gold', 'Blue']]] as $i => [$name, $level, $armNames]) {
            $id = DB::table('classes')->insertGetId($this->stamp([
                'school_id' => $this->schoolId, 'name' => $name, 'level_category' => $level, 'order_index' => $i + 1, 'is_exit_class' => false,
            ]));

            $arms = [];
            foreach ($armNames as $arm) {
                $arms[$arm] = DB::table('arms')->insertGetId($this->stamp(['school_id' => $this->schoolId, 'class_id' => $id, 'name' => $arm]));
            }

            foreach ($subjects[$level] as $subjectId) {
                DB::table('class_subjects')->insert($this->stamp([
                    'school_id' => $this->schoolId, 'class_id' => $id, 'subject_id' => $subjectId, 'is_compulsory' => true,
                ]));
            }

            $classes[] = ['id' => $id, 'name' => $name, 'level' => $level, 'arms' => $arms];
        }

        // [name, gender, class index, arm] — the class teachers; then the specialist.
        $staff = [
            ['Mrs Patience Okon', 'female', 0, 'Gold'],
            ['Mrs Rose Eke', 'female', 1, 'Gold'],
            ['Mr Daniel Ojo', 'male', 1, 'Blue'],
            ['Mme Claire Mensah', 'female', null, null],
        ];

        foreach ($staff as $i => [$name, $gender, $classIndex, $arm]) {
            $no = sprintf('GFA/ST/%03d', 40 + $i);
            $id = $this->user($name, Str::slug(Str::after($name, ' '), '.'), 'teacher', sprintf('0803200%04d', $i + 1), $gender);
            $this->staff($id, $no, 'Teacher', 220000, $classIndex === null ? 'B.A. French' : 'NCE');

            DB::table('teacher_profiles')->insert($this->stamp([
                'school_id' => $this->schoolId, 'user_id' => $id, 'employee_id' => $no,
                'qualification' => $classIndex === null ? 'B.A. French' : 'NCE',
                'specialization' => $classIndex === null ? 'French' : 'Early years',
                'years_of_experience' => 4 + $i,
                'subjects_taught' => json_encode($classIndex === null ? ['French'] : ['All subjects']),
                'date_joined' => '2021-09-01', 'contract_type' => 'full_time',
            ]));

            if ($classIndex !== null) {
                DB::table('class_teacher_assignments')->insert($this->stamp([
                    'school_id' => $this->schoolId, 'session_id' => $session, 'class_id' => $classes[$classIndex]['id'],
                    'arm_id' => $classes[$classIndex]['arms'][$arm], 'form_teacher_id' => $id, 'assigned_by' => $admin,
                ]));
            } else {
                // French in both Primary 4 arms is the specialist's, not the class teachers'.
                DB::table('teacher_subjects')->insert($this->stamp([
                    'school_id' => $this->schoolId, 'teacher_id' => $id, 'subject_id' => $subjects['primary']['French'],
                    'class_id' => $classes[1]['id'], 'is_primary' => true,
                ]));
            }
        }

        $students = [];
        $n = $admissionOffset;
        foreach ($classes as $c => $class) {
            foreach ($class['arms'] as $armName => $armId) {
                for ($k = 0; $k < 8; $k++) {
                    $n++;
                    $gender = mt_rand(0, 1) ? 'male' : 'female';
                    $first = $this->pick($gender === 'male' ? self::FIRST_NAMES_M : self::FIRST_NAMES_F);
                    $surname = $this->pick(self::SURNAMES);
                    $userId = $this->user("{$first} {$surname}", sprintf('gfa%04d', $n), 'student', null, $gender);

                    $studentId = DB::table('students')->insertGetId($this->stamp([
                        'school_id' => $this->schoolId, 'user_id' => $userId, 'class_id' => $class['id'], 'arm_id' => $armId,
                        'admission_number' => sprintf('GFA/2026/%04d', $n),
                        // Nursery 2 about 4, Primary 4 about 8.
                        'date_of_birth' => sprintf('%d-%02d-%02d', $c === 0 ? 2022 : 2018, mt_rand(1, 12), mt_rand(1, 28)),
                        'gender' => $gender, 'state_of_origin' => $this->pick(self::STATES),
                        'religion' => mt_rand(0, 2) ? 'Christianity' : 'Islam', 'status' => 'active',
                    ]));

                    DB::table('student_enrollments')->insert($this->stamp([
                        'school_id' => $this->schoolId, 'student_id' => $studentId, 'session_id' => $session,
                        'class_id' => $class['id'], 'arm_id' => $armId, 'status' => 'active', 'enrolled_on' => '2026-09-14',
                    ]));

                    DB::table('student_gamifications')->insertOrIgnore($this->stamp(['school_id' => $this->schoolId, 'student_id' => $studentId]));

                    DB::table('student_guardian')->insert($this->stamp([
                        'student_id' => $studentId, 'guardian_id' => $this->createParent($surname, false), 'is_primary' => true,
                    ]));

                    foreach ($subjects[$class['level']] as $subjectId) {
                        DB::table('student_subjects')->insert($this->stamp([
                            'school_id' => $this->schoolId, 'student_id' => $studentId, 'subject_id' => $subjectId, 'term_id' => $term,
                            'enrollment_type' => 'compulsory', 'status' => 'active', 'enrolled_at' => $this->now,
                        ]));
                    }

                    $students[] = ['id' => $studentId, 'user_id' => $userId, 'name' => "{$first} {$surname}", 'first' => $first, 'class' => $class, 'arm_id' => $armId, 'track' => null];
                }
            }
        }

        return [$classes, $students];
    }

    /**
     * A question bank filed by class: JSS 1 Mathematics, SS 2 Physics and
     * Primary 4 Mathematics, by the teachers who teach them, plus a handful
     * of older English questions with no class yet ("Unsorted") to show the
     * bulk filing tools on.
     */
    private function createQuestionBank(array $classes, array $early, array $subjects): void
    {
        $byName = fn (string $name) => collect(array_merge($classes, $early))->firstWhere('name', $name)['id'];
        $teacher = fn (string $name) => DB::table('users')->where('name', $name)->whereIn('id', DB::table('user_profiles')->where('school_id', $this->schoolId)->pluck('user_id'))->value('id');

        $sets = [
            ['JSS 1', 'Mathematics', 'Mr Chidi Okeke', [
                ['Fractions', 'easy', 'What is 3/4 + 1/4?', ['A' => '1', 'B' => '4/8', 'C' => '3/16', 'D' => '1/2'], 'A'],
                ['Fractions', 'medium', 'Simplify 12/18.', ['A' => '2/3', 'B' => '3/4', 'C' => '6/9', 'D' => '4/6'], 'A'],
                ['Whole numbers', 'easy', 'What is the place value of 7 in 4,718?', ['A' => 'Units', 'B' => 'Tens', 'C' => 'Hundreds', 'D' => 'Thousands'], 'C'],
                ['Whole numbers', 'medium', 'Round 2,846 to the nearest hundred.', ['A' => '2,800', 'B' => '2,850', 'C' => '2,900', 'D' => '3,000'], 'A'],
                ['Algebra', 'medium', 'If x + 5 = 12, what is x?', ['A' => '5', 'B' => '7', 'C' => '12', 'D' => '17'], 'B'],
                ['Algebra', 'hard', 'Solve 3x - 4 = 11.', ['A' => '3', 'B' => '4', 'C' => '5', 'D' => '7'], 'C'],
            ]],
            ['SS 2', 'Physics', 'Mr Victor Eze', [
                ['Motion', 'easy', 'The SI unit of force is the', ['A' => 'Joule', 'B' => 'Newton', 'C' => 'Watt', 'D' => 'Pascal'], 'B'],
                ['Motion', 'medium', 'A car moves 100 m in 20 s. Its average speed is', ['A' => '2 m/s', 'B' => '5 m/s', 'C' => '20 m/s', 'D' => '2000 m/s'], 'B'],
                ['Energy', 'medium', 'Which of these is a scalar quantity?', ['A' => 'Velocity', 'B' => 'Force', 'C' => 'Energy', 'D' => 'Displacement'], 'C'],
                ['Electricity', 'hard', 'A 12 V battery drives 3 A through a resistor. Its resistance is', ['A' => '4 Ω', 'B' => '9 Ω', 'C' => '15 Ω', 'D' => '36 Ω'], 'A'],
                ['Waves', 'medium', 'Sound cannot travel through', ['A' => 'Water', 'B' => 'Steel', 'C' => 'Air', 'D' => 'A vacuum'], 'D'],
            ]],
            ['Primary 4', 'Mathematics', 'Mrs Rose Eke', [
                ['Addition', 'easy', 'What is 245 + 132?', ['A' => '367', 'B' => '377', 'C' => '387', 'D' => '477'], 'B'],
                ['Multiplication', 'easy', 'What is 7 × 8?', ['A' => '54', 'B' => '56', 'C' => '58', 'D' => '64'], 'B'],
                ['Money', 'medium', 'Ada has ₦500 and buys a pen for ₦150. How much is left?', ['A' => '₦250', 'B' => '₦300', 'C' => '₦350', 'D' => '₦650'], 'C'],
                ['Time', 'medium', 'How many minutes are in 2 hours?', ['A' => '60', 'B' => '100', 'C' => '120', 'D' => '200'], 'C'],
            ]],
            [null, 'English Language', 'Mrs Funke Alabi', [
                ['Grammar', 'easy', 'Choose the plural of "child".', ['A' => 'Childs', 'B' => 'Children', 'C' => 'Childes', 'D' => 'Childrens'], 'B'],
                ['Grammar', 'medium', 'Which word is a verb? "The girl sings beautifully."', ['A' => 'girl', 'B' => 'sings', 'C' => 'beautifully', 'D' => 'the'], 'B'],
                ['Vocabulary', 'medium', 'The opposite of "generous" is', ['A' => 'kind', 'B' => 'mean', 'C' => 'rich', 'D' => 'happy'], 'B'],
                ['Comprehension', 'easy', 'A synonym of "begin" is', ['A' => 'end', 'B' => 'start', 'C' => 'stop', 'D' => 'wait'], 'B'],
            ]],
        ];

        $rows = [];
        foreach ($sets as [$className, $subject, $author, $questions]) {
            foreach ($questions as [$topic, $difficulty, $text, $options, $answer]) {
                $rows[] = $this->stamp([
                    'school_id' => $this->schoolId,
                    'subject_id' => DB::table('subjects')->where('school_id', $this->schoolId)->where('name', $subject)->value('id'),
                    'class_id' => $className ? $byName($className) : null,
                    'topic' => $topic, 'question' => $text, 'options' => json_encode($options), 'correct_answer' => $answer,
                    'difficulty' => $difficulty, 'question_type' => 'multiple_choice', 'marks' => 1,
                    'status' => 'approved', 'created_by' => $teacher($author),
                ]);
            }
        }

        DB::table('question_bank')->insert($rows);
    }

    /**
     * Venues and a draft first-term exam timetable. SS 2 papers are written in
     * the Main Hall by everyone taking the subject (Physics: the science arm;
     * Literature: the arts arm); JSS 1 and Primary 4 write in their own
     * classrooms.
     */
    private function createExamTimetable(array $classes, array $early, array $subjects, int $term): void
    {
        $hall = DB::table('venues')->insertGetId($this->stamp(['school_id' => $this->schoolId, 'name' => 'Main Hall', 'kind' => 'hall', 'capacity' => 120, 'shared' => true, 'active' => true]));
        DB::table('venues')->insert($this->stamp(['school_id' => $this->schoolId, 'name' => 'Science Lab', 'kind' => 'lab', 'capacity' => 40, 'shared' => false, 'active' => true]));

        $timetable = DB::table('exam_timetables')->insertGetId($this->stamp([
            'school_id' => $this->schoolId, 'term_id' => $term, 'name' => 'First Term Examinations', 'status' => 'draft',
        ]));

        $class = fn (string $name) => collect(array_merge($classes, $early))->firstWhere('name', $name)['id'];
        $subject = fn (string $name) => DB::table('subjects')->where('school_id', $this->schoolId)->where('name', $name)->value('id');
        $day = fn (int $n) => CarbonImmutable::parse('2026-12-07')->addDays($n)->toDateString();

        // [class, subject, day, start, end, where]
        $papers = [
            ['SS 2', 'Physics', 0, '09:00', '11:00', 'hall'],
            ['SS 2', 'Literature in English', 0, '12:00', '14:00', 'hall'],
            ['SS 2', 'Mathematics', 1, '09:00', '11:30', 'hall'],
            ['JSS 1', 'Mathematics', 1, '09:00', '10:30', 'own'],
            ['JSS 1', 'English Language', 2, '09:00', '10:30', 'own'],
            ['Primary 4', 'French', 2, '11:00', '12:00', 'own'],
        ];

        foreach ($papers as [$className, $subjectName, $offset, $start, $end, $where]) {
            DB::table('exam_sittings')->insert($this->stamp([
                'school_id' => $this->schoolId, 'exam_timetable_id' => $timetable,
                'class_id' => $class($className), 'arm_id' => null, 'subject_id' => $subject($subjectName),
                'exam_date' => $day($offset), 'start_time' => "{$start}:00", 'end_time' => "{$end}:00",
                'venue_mode' => $where === 'hall' ? 'hall' : 'own_classrooms',
                'venue_id' => $where === 'hall' ? $hall : null,
                'venue' => $where === 'hall' ? 'Main Hall' : null,
            ]));
        }
    }

    /* ----------------------------------------------------------------- */

    private function user(string $name, string $handle, string $role, ?string $phone, ?string $gender): int
    {
        $id = DB::table('users')->insertGetId($this->stamp([
            'name' => $name,
            'email' => "{$handle}@".self::DOMAIN,
            'email_verified_at' => $this->now,
            'password' => $this->password,
            'status' => 'active',
        ]));

        DB::table('user_profiles')->insert($this->stamp([
            'school_id' => $this->schoolId,
            'user_id' => $id,
            'role' => $role,
            'phone' => $phone,
            'gender' => $gender,
            'nationality' => 'Nigerian',
        ]));

        return $id;
    }

    private function staff(int $userId, string $staffNo, string $designation, int $salary, string $qualification): void
    {
        DB::table('staff')->insert($this->stamp([
            'school_id' => $this->schoolId,
            'user_id' => $userId,
            'staff_id' => $staffNo,
            'designation' => $designation,
            'qualification' => $qualification,
            'salary' => $salary,
            'employment_date' => '2020-09-01',
        ]));
    }

    /** A mark out of $max near the student's target percentage. */
    private function mark(int $target, int $max): int
    {
        $pct = max(0, min(100, $target + mt_rand(-8, 8)));

        return (int) round($max * $pct / 100);
    }

    /** @return array{0: string, 1: string} */
    private function grade(float $total): array
    {
        foreach (GradingScale::WAEC as $band) {
            if ($total >= $band['min']) {
                return [$band['grade'], $band['remark']];
            }
        }

        return ['F9', 'Fail'];
    }

    private function subjectRemark(string $first, string $subject, int $total, string $remark): string
    {
        return match (true) {
            $total >= 75 => "{$first} shows an excellent grasp of {$subject}. Keep it up.",
            $total >= 60 => "{$first} has done well in {$subject} this term.",
            $total >= 45 => "{$first} has a fair understanding of {$subject} but needs more practice.",
            default => "{$first} is struggling with {$subject} and needs extra support.",
        };
    }

    private function pick(array $items): mixed
    {
        return $items[mt_rand(0, count($items) - 1)];
    }

    private function stamp(array $row): array
    {
        return $row + ['created_at' => $this->now, 'updated_at' => $this->now];
    }
}
