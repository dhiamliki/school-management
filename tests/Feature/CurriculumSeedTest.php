<?php

namespace Tests\Feature;

use App\Models\Lesson;
use App\Models\SchoolClass;
use App\Models\Teacher;
use App\Models\Timetable;
use App\Models\User;
use App\Support\Curriculum;
use Database\Seeders\LessonSeeder;
use Database\Seeders\SchoolClassSeeder;
use Database\Seeders\StudentSeeder;
use Database\Seeders\TeacherSeeder;
use Database\Seeders\TimetableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The shape of the seeded school: which subjects reach which grades, and
 * that the week it lays out is actually teachable.
 */
class CurriculumSeedTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([
            TeacherSeeder::class,
            SchoolClassSeeder::class,
            StudentSeeder::class,
            LessonSeeder::class,
            TimetableSeeder::class,
        ]);
    }

    public function test_the_school_runs_the_six_primary_grades(): void
    {
        $levels = SchoolClass::orderBy('level')->pluck('level')->unique()->values()->all();

        $this->assertSame(['1', '2', '3', '4', '5', '6'], $levels);

        // Grades are split into a varying number of sections, not a fixed one.
        $perGrade = SchoolClass::get()->groupBy('level')->map->count();
        $this->assertGreaterThanOrEqual(2, $perGrade->min());
        $this->assertLessThanOrEqual(3, $perGrade->max());
    }

    public function test_no_class_is_seeded_over_its_capacity(): void
    {
        foreach (SchoolClass::withCount('students')->get() as $class) {
            $this->assertLessThanOrEqual(
                $class->capacity,
                $class->students_count,
                "{$class->name} holds more pupils than it has places",
            );
        }
    }

    /** Français from 3eme, Anglais from 5eme, combined awakening below that. */
    public function test_subjects_only_reach_the_grades_that_study_them(): void
    {
        $lessons = Lesson::with('schoolClass')->get();

        $this->assertNotEmpty($lessons);

        foreach ($lessons as $lesson) {
            $grade = (int) $lesson->schoolClass->level;

            $this->assertTrue(
                Curriculum::subjectFitsGrade($lesson->subject, $grade),
                "{$lesson->subject} should not be taught to {$lesson->schoolClass->name}",
            );
        }
    }

    /** @return array<string, array{string, int}> */
    public static function introducedSubjectProvider(): array
    {
        return [
            'Français from 3ème' => ['Langue Française', 3],
            'Anglais from 5ème' => ['Langue Anglaise', 5],
            'Histoire-Géographie from 3ème' => ['Histoire-Géographie', 3],
        ];
    }

    #[DataProvider('introducedSubjectProvider')]
    public function test_a_subject_never_appears_below_the_grade_it_starts_at(string $subject, int $from): void
    {
        $taught = Lesson::with('schoolClass')->where('subject', $subject)->get();

        $this->assertNotEmpty($taught, "{$subject} should be taught somewhere");

        foreach ($taught as $lesson) {
            $this->assertGreaterThanOrEqual(
                $from,
                (int) $lesson->schoolClass->level,
                "{$subject} should not reach {$lesson->schoolClass->name}",
            );
        }
    }

    public function test_the_lower_band_studies_the_combined_awakening_subject(): void
    {
        $combined = Lesson::with('schoolClass')->where('subject', 'Éveil Scientifique et Civique')->get();

        $this->assertNotEmpty($combined);

        foreach ($combined as $lesson) {
            $this->assertLessThanOrEqual(2, (int) $lesson->schoolClass->level);
        }

        foreach (Lesson::with('schoolClass')->whereIn('subject', ['Éveil Scientifique', 'Éducation Civique'])->get() as $lesson) {
            $this->assertGreaterThanOrEqual(3, (int) $lesson->schoolClass->level);
        }
    }

    public function test_each_class_runs_its_band_weekly_hours(): void
    {
        foreach (SchoolClass::with('lessons')->get() as $class) {
            $grade = (int) $class->level;
            $slots = Timetable::whereIn('lesson_id', $class->lessons->pluck('id'))->count();

            $this->assertSame(
                Curriculum::weeklyHours($grade),
                $slots,
                "{$class->name} should run its band's weekly hours",
            );
        }

        // And the bands really do differ, so the assertion above means
        // something: 21 / 28 / 30.
        $this->assertSame(21, Curriculum::weeklyHours(1));
        $this->assertSame(28, Curriculum::weeklyHours(3));
        $this->assertSame(30, Curriculum::weeklyHours(6));
    }

    /** Nobody teaches both a 1ere and a 6eme class. */
    public function test_no_teacher_works_across_grade_bands(): void
    {
        $teachers = Teacher::with('lessons.schoolClass')->get();
        $withLessons = $teachers->filter(fn (Teacher $t) => $t->lessons->isNotEmpty());

        $this->assertNotEmpty($withLessons);

        foreach ($withLessons as $teacher) {
            $bands = $teacher->lessons
                ->map(fn ($lesson) => Curriculum::bandForGrade((int) $lesson->schoolClass->level))
                ->unique();

            $this->assertCount(
                1,
                $bands,
                "{$teacher->name} teaches in more than one band: ".$bands->implode(', '),
            );
        }
    }

    /** A subject running in several bands has different staff in each. */
    public function test_shared_subjects_have_different_teachers_per_band(): void
    {
        foreach (['Mathématiques', 'Langue Arabe', 'Éducation Physique'] as $subject) {
            $byBand = Lesson::with(['schoolClass', 'teacher'])
                ->where('subject', $subject)
                ->get()
                ->groupBy(fn ($lesson) => Curriculum::bandForGrade((int) $lesson->schoolClass->level))
                ->map(fn ($group) => $group->pluck('teacher_id')->unique()->values());

            $this->assertGreaterThan(1, $byBand->count(), "{$subject} should span bands");

            $seen = [];

            foreach ($byBand as $band => $teacherIds) {
                foreach ($teacherIds as $id) {
                    $this->assertArrayNotHasKey(
                        $id,
                        $seen,
                        "{$subject}: teacher {$id} appears in more than one band",
                    );
                    $seen[$id] = $band;
                }
            }
        }
    }

    public function test_a_band_can_share_a_subject_between_teachers(): void
    {
        $frenchTeachersPerBand = Lesson::with(['schoolClass', 'teacher'])
            ->where('subject', 'Langue Française')
            ->get()
            ->groupBy(fn ($lesson) => Curriculum::bandForGrade((int) $lesson->schoolClass->level))
            ->map(fn ($group) => $group->pluck('teacher_id')->unique()->count());

        foreach ($frenchTeachersPerBand as $band => $count) {
            $this->assertGreaterThan(1, $count, "{$band} band should share Français between teachers");
        }
    }

    /**
     * A titulaire once held 19 of a lower-band class 21 hours. This pins the
     * ceiling so the split cannot collapse back onto one person.
     */
    public function test_no_teacher_dominates_a_class_week(): void
    {
        $classes = SchoolClass::with('lessons')->get();

        $this->assertNotEmpty($classes);

        foreach ($classes as $class) {
            $hoursByTeacher = [];

            foreach ($class->lessons as $lesson) {
                $hours = Timetable::where('lesson_id', $lesson->id)->count();
                $hoursByTeacher[$lesson->teacher_id] = ($hoursByTeacher[$lesson->teacher_id] ?? 0) + $hours;
            }

            $total = array_sum($hoursByTeacher);

            $this->assertGreaterThan(0, $total, "{$class->name} should have a timetable");

            $share = max($hoursByTeacher) / $total;

            $this->assertLessThanOrEqual(
                Curriculum::MAX_CLASS_SHARE,
                $share,
                sprintf(
                    '%s: one teacher holds %.1f%% of the week, above the %.0f%% ceiling',
                    $class->name,
                    $share * 100,
                    Curriculum::MAX_CLASS_SHARE * 100,
                ),
            );

            // And the week is genuinely shared, not split two ways on paper.
            $this->assertGreaterThanOrEqual(
                3,
                count($hoursByTeacher),
                "{$class->name} should be taught by at least three teachers",
            );
        }
    }

    public function test_no_teacher_exceeds_a_full_weekly_load(): void
    {
        $teachers = Teacher::with('lessons')->get();
        $loads = [];

        foreach ($teachers as $teacher) {
            $loads[$teacher->name] = Timetable::whereIn('lesson_id', $teacher->lessons->pluck('id'))->count();
        }

        $this->assertNotEmpty(array_filter($loads));

        foreach ($loads as $name => $hours) {
            $this->assertLessThanOrEqual(
                Curriculum::MAX_WEEKLY_LOAD,
                $hours,
                "{$name} is timetabled for {$hours}h, above the {$hours}h ceiling",
            );
        }

        // The ceiling is only meaningful if somebody comes near it.
        $this->assertGreaterThanOrEqual(15, max($loads), 'the busiest teacher should carry a real load');
    }

    /**
     * Checked against the role table itself, not one seed run. Grades 1 and 3
     * only: the upper band has no titulaire, which the coherence tests cover.
     */
    public function test_the_role_split_respects_the_ceiling_by_construction(): void
    {
        foreach ([1, 3] as $grade) {
            $this->assertTrue(
                Curriculum::usesTitulaireModel(Curriculum::bandForGrade($grade)),
                "grade {$grade} should be staffed by a titulaire",
            );

            $total = Curriculum::weeklyHours($grade);
            $titulaire = Curriculum::hoursForRole('titulaire', $grade);
            $adjoint = Curriculum::hoursForRole('adjoint', $grade);

            $this->assertLessThanOrEqual(
                Curriculum::MAX_CLASS_SHARE,
                $titulaire / $total,
                "grade {$grade}: the titulaire share exceeds the ceiling",
            );

            $this->assertGreaterThan(0, $adjoint, "grade {$grade}: the core should be shared with an adjoint");
            $this->assertLessThan($titulaire, $adjoint, "grade {$grade}: the titulaire should still lead the class");
        }

        // And the upper band genuinely is not staffed that way, so the two
        // grades above are the whole of the titulaire model rather than a
        // selective reading of it.
        $this->assertFalse(Curriculum::usesTitulaireModel(Curriculum::UPPER));
        $this->assertTrue(Curriculum::isSpecialisedGrade(5));
        $this->assertTrue(Curriculum::isSpecialisedGrade(6));

        foreach ([5, 6] as $grade) {
            foreach (array_keys(Curriculum::hoursForGrade($grade)) as $subject) {
                $this->assertNotContains(
                    Curriculum::roleFor($subject, $grade),
                    ['titulaire', 'adjoint'],
                    "grade {$grade}: {$subject} should belong to a subject post",
                );
            }
        }
    }

    /**
     * Written out here rather than read from Curriculum on purpose: an
     * independent statement of what counts as related, so a change that merged
     * two unrelated subjects fails here rather than agreeing with itself.
     *
     * @return array<string, list<string>>
     */
    private function coherentGroups(): array
    {
        return [
            'arabe' => ['Langue Arabe'],
            'français' => ['Langue Française'],
            'anglais' => ['Langue Anglaise'],
            'mathématiques' => ['Mathématiques'],
            'sciences' => ['Sciences et Technologie'],
            'sciences humaines' => ['Histoire-Géographie'],
            'morale et citoyenneté' => ['Éducation Islamique', 'Éducation Civique'],
            'arts' => ['Éducation Artistique'],
            'sport' => ['Éducation Physique'],
        ];
    }

    /**
     * The titulaire model put one 6eme teacher in front of Arabic, maths,
     * science and Islamic studies at once, inside every cap. This makes that
     * impossible rather than unlikely.
     */
    public function test_no_upper_band_teacher_spans_unrelated_subjects(): void
    {
        $groups = $this->coherentGroups();
        $groupOf = [];

        foreach ($groups as $group => $subjects) {
            foreach ($subjects as $subject) {
                $groupOf[$subject] = $group;
            }
        }

        $upper = Teacher::with('lessons.schoolClass')->get()
            ->filter(fn (Teacher $t) => $t->lessons->isNotEmpty()
                && $t->lessons->every(fn ($l) => Curriculum::bandForGrade((int) $l->schoolClass->level) === Curriculum::UPPER));

        $this->assertGreaterThanOrEqual(
            9,
            $upper->count(),
            'the upper band should be staffed by at least one teacher per subject group',
        );

        foreach ($upper as $teacher) {
            $subjects = $teacher->lessons->pluck('subject')->unique()->values();

            foreach ($subjects as $subject) {
                $this->assertArrayHasKey(
                    $subject,
                    $groupOf,
                    "{$subject} is not in any declared subject group; the groups above need updating",
                );
            }

            $spanned = $subjects->map(fn (string $s) => $groupOf[$s])->unique()->values();

            $this->assertCount(
                1,
                $spanned,
                sprintf(
                    '%s teaches across %d subject groups (%s) via %s',
                    $teacher->name,
                    $spanned->count(),
                    $spanned->implode(', '),
                    $subjects->implode(', '),
                ),
            );
        }
    }

    /** One subject across several classes, rather than several subjects in one. */
    public function test_upper_band_teachers_carry_one_subject_across_classes(): void
    {
        $upper = Teacher::with('lessons.schoolClass')->get()
            ->filter(fn (Teacher $t) => $t->lessons->isNotEmpty()
                && $t->lessons->every(fn ($l) => Curriculum::bandForGrade((int) $l->schoolClass->level) === Curriculum::UPPER));

        $this->assertNotEmpty($upper);

        foreach ($upper as $teacher) {
            $subjects = $teacher->lessons->pluck('subject')->unique();
            $classes = $teacher->lessons->pluck('school_class_id')->unique();

            $this->assertLessThanOrEqual(
                2,
                $subjects->count(),
                "{$teacher->name} covers {$subjects->count()} subjects; a specialised post covers one, or two closely related",
            );

            $this->assertGreaterThanOrEqual(
                2,
                $classes->count(),
                "{$teacher->name} serves only {$classes->count()} class; a subject post spans several",
            );
        }

        // The staff list names the subject rather than a job title, so nobody
        // in this band reads as "Enseignement polyvalent".
        foreach ($upper as $teacher) {
            $this->assertStringNotContainsString(
                'polyvalent',
                (string) $teacher->subject,
                "{$teacher->name} should be listed by subject, not as a general post",
            );
        }
    }

    /** Asserted from the seeded rows, not from the role table. */
    public function test_the_lower_and_mid_bands_keep_the_titulaire_model(): void
    {
        $classes = SchoolClass::with('lessons')->get()
            ->filter(fn (SchoolClass $c) => Curriculum::usesTitulaireModel(Curriculum::bandForGrade((int) $c->level)));

        $this->assertCount(10, $classes, 'the lower and mid bands should hold ten classes');

        foreach ($classes as $class) {
            $hours = [];
            $subjects = [];

            foreach ($class->lessons as $lesson) {
                $count = Timetable::where('lesson_id', $lesson->id)->count();
                $hours[$lesson->teacher_id] = ($hours[$lesson->teacher_id] ?? 0) + $count;
                $subjects[$lesson->teacher_id][] = $lesson->subject;
            }

            arsort($hours);
            $lead = array_key_first($hours);
            $share = $hours[$lead] / array_sum($hours);

            // A main teacher, carrying a real share of the week but not all
            // of it: the band that specialises tops out at 27%.
            $this->assertGreaterThan(
                0.4,
                $share,
                "{$class->name} has no main teacher; the titulaire model should hold in this band",
            );
            $this->assertLessThanOrEqual(Curriculum::MAX_CLASS_SHARE, $share);

            $this->assertGreaterThanOrEqual(
                2,
                count(array_unique($subjects[$lead])),
                "{$class->name}: the titulaire should cover several subjects, not one",
            );
        }
    }

    public function test_samedi_is_a_half_day(): void
    {
        $samedi = Timetable::where('day_of_week', 'Samedi')->get();

        $this->assertNotEmpty($samedi, 'Samedi should be taught');

        $morning = array_column(Timetable::slotsFor('Samedi'), 0);

        foreach ($samedi as $slot) {
            $this->assertContains(
                substr((string) $slot->start_time, 0, 5),
                $morning,
                'Samedi should only run morning slots',
            );
        }

        // And the full days really are longer, so this is a genuine contrast.
        $this->assertGreaterThan(
            $samedi->pluck('start_time')->unique()->count(),
            Timetable::where('day_of_week', 'Lundi')->pluck('start_time')->unique()->count(),
        );
    }

    public function test_nobody_is_booked_in_two_places_at_once(): void
    {
        $seen = ['teacher' => [], 'class' => [], 'room' => []];

        foreach (Timetable::with('lesson')->get() as $slot) {
            $when = $slot->day_of_week.'|'.substr((string) $slot->start_time, 0, 5);

            $keys = [
                'teacher' => $slot->lesson->teacher_id.'@'.$when,
                'class' => $slot->lesson->school_class_id.'@'.$when,
                'room' => $slot->room.'@'.$when,
            ];

            foreach ($keys as $kind => $key) {
                $this->assertArrayNotHasKey($key, $seen[$kind], "{$kind} double-booked at {$when}");
                $seen[$kind][$key] = true;
            }
        }
    }

    public function test_the_api_rejects_a_day_outside_the_school_week(): void
    {
        $this->actingAs(User::factory()->create());

        // A lesson of its own, with a teacher, class and room nothing else
        // uses: this test is about the day whitelist, and a slot that also
        // happened to clash would fail for the wrong reason.
        $lesson = Lesson::factory()->create([
            'teacher_id' => Teacher::factory()->create()->id,
            'school_class_id' => SchoolClass::factory()->create()->id,
        ]);

        $payload = [
            'lesson_id' => $lesson->id,
            'start_time' => '08:00',
            'end_time' => '09:00',
            'room' => 'Salle libre',
        ];

        $this->postJson('/api/timetables', $payload + ['day_of_week' => 'Dimanche'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('day_of_week');

        $this->postJson('/api/timetables', $payload + ['day_of_week' => 'Samedi'])
            ->assertCreated();
    }
}
