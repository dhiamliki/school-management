<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Lesson;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The attendance domain: the unique mark constraint, the derived counters on
 * Student, and the at-risk report.
 */
class AttendanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());
    }

    /**
     * Give a pupil an exact history, each mark against its own lesson so the
     * unique index is never the thing under test.
     */
    private function history(Student $student, int $present, int $absent, int $late, string $date = '2026-09-01'): void
    {
        $marks = array_merge(
            array_fill(0, $present, Attendance::PRESENT),
            array_fill(0, $absent, Attendance::ABSENT),
            array_fill(0, $late, Attendance::LATE),
        );

        foreach ($marks as $status) {
            Attendance::factory()
                ->forStudent($student)
                ->forLesson(Lesson::factory()->create())
                ->on($date)
                ->status($status)
                ->create();
        }
    }

    public function test_a_pupil_cannot_be_marked_twice_for_the_same_lesson_and_day(): void
    {
        $student = Student::factory()->create();
        $lesson = Lesson::factory()->create();

        $payload = [
            'student_id' => $student->id,
            'lesson_id' => $lesson->id,
            'date' => '2026-09-01',
            'status' => Attendance::PRESENT,
        ];

        $this->postJson('/api/attendances', $payload)->assertCreated();

        // The second attempt is refused as validation, not as a 500 from the
        // database constraint underneath.
        $this->postJson('/api/attendances', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('lesson_id');

        $this->assertSame(1, Attendance::count());
    }

    public function test_the_database_itself_enforces_the_unique_mark(): void
    {
        $student = Student::factory()->create();
        $lesson = Lesson::factory()->create();

        Attendance::factory()->forStudent($student)->forLesson($lesson)->on('2026-09-01')->create();

        $this->expectException(QueryException::class);

        Attendance::factory()->forStudent($student)->forLesson($lesson)->on('2026-09-01')->create();
    }

    /**
     * The nullable lesson_id is what makes full-day marking possible, so the
     * unique index must not collapse those rows together.
     */
    public function test_full_day_marks_are_not_blocked_by_the_unique_constraint(): void
    {
        $student = Student::factory()->create();

        Attendance::factory()->forStudent($student)->forLesson(null)->on('2026-09-01')->create();
        Attendance::factory()->forStudent($student)->forLesson(null)->on('2026-09-01')->create();

        $this->assertSame(2, Attendance::whereNull('lesson_id')->count());

        // And the endpoint accepts them too.
        $this->postJson('/api/attendances', [
            'student_id' => $student->id,
            'lesson_id' => null,
            'date' => '2026-09-01',
            'status' => Attendance::ABSENT,
        ])->assertCreated();
    }

    /**
     * Re-saving a day corrects the existing marks rather than duplicating or
     * failing, including for full-day rows the index cannot constrain.
     */
    public function test_bulk_marking_upserts_instead_of_duplicating(): void
    {
        $students = Student::factory()->count(3)->create();

        $body = fn (string $status) => [
            'date' => '2026-09-01',
            'lesson_id' => null,
            'marks' => $students->map(fn (Student $s) => ['student_id' => $s->id, 'status' => $status])->all(),
        ];

        $this->postJson('/api/attendances/bulk', $body(Attendance::PRESENT))
            ->assertOk()
            ->assertJsonPath('saved', 3);

        $this->postJson('/api/attendances/bulk', $body(Attendance::ABSENT))
            ->assertOk()
            ->assertJsonPath('saved', 3);

        $this->assertSame(3, Attendance::count(), 'the second save should correct, not duplicate');
        $this->assertSame(3, Attendance::where('status', Attendance::ABSENT)->count());
    }

    public function test_bulk_marking_rejects_an_unknown_status(): void
    {
        $student = Student::factory()->create();

        $this->postJson('/api/attendances/bulk', [
            'date' => '2026-09-01',
            'marks' => [['student_id' => $student->id, 'status' => 'absent-ish']],
        ])->assertUnprocessable()->assertJsonValidationErrors('marks.0.status');
    }

    public function test_attendance_rate_counts_present_marks_over_every_record(): void
    {
        $student = Student::factory()->create();

        // 17 présent, 2 absent, 1 retard = 20 records, 85% present.
        $this->history($student, present: 17, absent: 2, late: 1);

        $student->refresh();

        $this->assertSame(20, $student->attendanceRecordCount());
        $this->assertSame(2, $student->absenceCount());
        $this->assertSame(1, $student->lateCount());
        $this->assertSame(85.0, $student->attendanceRate());
    }

    public function test_attendance_rate_is_null_without_any_record(): void
    {
        $student = Student::factory()->create();

        $this->assertNull($student->attendanceRate());
        $this->assertSame(0, $student->absenceCount());
    }

    /**
     * The eager-loaded tallies and the lazy fallback must agree, since the
     * resource reads whichever is available.
     */
    public function test_the_preloaded_summary_matches_the_lazy_counts(): void
    {
        $student = Student::factory()->create();
        $this->history($student, present: 8, absent: 2, late: 0);

        $lazy = Student::find($student->id);
        $eager = Student::withAttendanceSummary()->find($student->id);

        $this->assertSame($lazy->attendanceRate(), $eager->attendanceRate());
        $this->assertSame($lazy->absenceCount(), $eager->absenceCount());
        $this->assertSame(80.0, $eager->attendanceRate());
    }

    public function test_the_student_endpoint_exposes_the_attendance_summary(): void
    {
        $student = Student::factory()->create();
        $this->history($student, present: 9, absent: 1, late: 0);

        $response = $this->getJson("/api/students/{$student->id}")
            ->assertOk()
            ->assertJsonPath('data.attendance.absences', 1)
            ->assertJsonPath('data.attendance.records', 10);

        // json_encode drops the .0 from a whole float, so compare numerically.
        $this->assertEquals(90.0, $response->json('data.attendance.rate'));
    }

    /**
     * Ranked by rate, not volume: the pupil with more absences but a lighter
     * timetable must not outrank a worse rate.
     */
    public function test_at_risk_defaults_to_the_absence_rate_worst_first(): void
    {
        $today = Carbon::today()->toDateString();

        $small = Student::factory()->create(['name' => 'Petite classe']);
        $often = Student::factory()->create(['name' => 'Occasionnel']);
        $fine = Student::factory()->create(['name' => 'Assidu']);

        // 8 of 40 marks: 20%.
        $this->history($small, present: 32, absent: 8, late: 0, date: $today);
        // 12 of 120 marks: 10%, and more absences in absolute terms.
        $this->history($often, present: 108, absent: 12, late: 0, date: $today);
        // 2 of 100: 2%.
        $this->history($fine, present: 98, absent: 2, late: 0, date: $today);

        $response = $this->getJson('/api/attendances/at-risk')->assertOk();

        $this->assertSame(
            ['Petite classe'],
            array_column($response->json('data'), 'name'),
            'only the pupil over the rate belongs on the list, whatever the raw counts say',
        );

        $this->assertSame(8, $response->json('data.0.absence_count'));
        $this->assertSame(40, $response->json('data.0.records_count'));
        $this->assertEquals(20.0, $response->json('data.0.absence_rate'));
        $this->assertSame('rate', $response->json('rule'));
        $this->assertEquals(15.0, $response->json('rate'));
    }

    /** The cutoff is exclusive, as "supérieur à 15 %" says. */
    public function test_a_pupil_exactly_on_the_cutoff_is_not_flagged(): void
    {
        $today = Carbon::today()->toDateString();

        $on = Student::factory()->create(['name' => 'Pile']);
        $over = Student::factory()->create(['name' => 'Au-dessus']);

        $this->history($on, present: 85, absent: 15, late: 0, date: $today);
        $this->history($over, present: 84, absent: 16, late: 0, date: $today);

        $this->getJson('/api/attendances/at-risk')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Au-dessus');
    }

    /**
     * Identical behaviour must give an identical verdict whether the pupil sits
     * 21 hours a week or 30.
     */
    public function test_the_rate_rule_treats_a_light_and_a_heavy_timetable_alike(): void
    {
        $today = Carbon::today()->toDateString();

        $lower = Student::factory()->create(['name' => 'Bande basse']);
        $upper = Student::factory()->create(['name' => 'Bande haute']);

        // Both are away for a fifth of their lessons.
        $this->history($lower, present: 32, absent: 8, late: 0, date: $today);
        $this->history($upper, present: 96, absent: 24, late: 0, date: $today);

        $names = array_column($this->getJson('/api/attendances/at-risk')->assertOk()->json('data'), 'name');
        sort($names);

        $this->assertSame(['Bande basse', 'Bande haute'], $names, 'the same rate is the same verdict');
    }

    public function test_the_rate_cutoff_is_configurable(): void
    {
        $today = Carbon::today()->toDateString();
        $student = Student::factory()->create();

        // 10 of 100 marks: 10%.
        $this->history($student, present: 90, absent: 10, late: 0, date: $today);

        $this->getJson('/api/attendances/at-risk?rate=5')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->getJson('/api/attendances/at-risk?rate=25')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    /**
     * A pupil with barely any marks can show any rate at all, and it means
     * nothing. The floor keeps them off a list meant to prompt a phone call.
     */
    public function test_a_pupil_with_too_few_marks_is_not_flagged(): void
    {
        $today = Carbon::today()->toDateString();
        $newcomer = Student::factory()->create(['name' => 'Nouvelle arrivée']);

        // 1 of 3 marks is 33%, on almost no evidence.
        $this->history($newcomer, present: 2, absent: 1, late: 0, date: $today);

        $this->getJson('/api/attendances/at-risk')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        // Lowering the floor lets them through, so the filter is the floor and
        // not something else quietly excluding them.
        $this->getJson('/api/attendances/at-risk?min_records=3')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_the_count_threshold_override_still_works(): void
    {
        $today = Carbon::today()->toDateString();

        $worst = Student::factory()->create(['name' => 'Pire']);
        $middle = Student::factory()->create(['name' => 'Moyen']);
        $safe = Student::factory()->create(['name' => 'Sûr']);

        $this->history($worst, present: 91, absent: 9, late: 0, date: $today);
        $this->history($middle, present: 94, absent: 6, late: 0, date: $today);
        // Exactly at the threshold: "exceeds" means strictly above it.
        $this->history($safe, present: 95, absent: 5, late: 0, date: $today);

        $response = $this->getJson('/api/attendances/at-risk?threshold=5')->assertOk();

        $this->assertSame(
            ['Pire', 'Moyen'],
            array_column($response->json('data'), 'name'),
            'worst first, and only those above the threshold',
        );
        $this->assertSame(9, $response->json('data.0.absence_count'));
        $this->assertSame('count', $response->json('rule'));
        $this->assertSame(5, $response->json('threshold'));

        // None of the three is over 15%, so the default rule reports nobody -
        // which is the whole point of the recalibration.
        $this->getJson('/api/attendances/at-risk')->assertOk()->assertJsonCount(0, 'data');
    }

    /**
     * The two rules answer different questions, so asking both at once is a
     * mistake rather than a precedence puzzle.
     */
    public function test_the_two_rules_cannot_be_combined(): void
    {
        $this->getJson('/api/attendances/at-risk?rate=10&threshold=5')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('rate');
    }

    /** Absences older than the window are history, not a current risk. */
    public function test_at_risk_only_counts_the_recent_window(): void
    {
        $student = Student::factory()->create();
        $this->history($student, present: 32, absent: 8, late: 0, date: Carbon::today()->subDays(120)->toDateString());

        $this->getJson('/api/attendances/at-risk')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        // Widening the window brings them back.
        $this->getJson('/api/attendances/at-risk?days=200')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_by_student_returns_only_that_pupils_history(): void
    {
        $student = Student::factory()->create();
        $other = Student::factory()->create();

        $this->history($student, present: 3, absent: 1, late: 0);
        $this->history($other, present: 2, absent: 0, late: 0);

        $response = $this->getJson("/api/attendances/by-student/{$student->id}")->assertOk();

        $this->assertSame(4, $response->json('meta.total'));

        foreach ($response->json('data') as $row) {
            $this->assertSame($student->id, $row['student_id']);
        }
    }

    public function test_deleting_a_lesson_keeps_the_mark_as_a_full_day_record(): void
    {
        $student = Student::factory()->create();
        $lesson = Lesson::factory()->create();

        $attendance = Attendance::factory()->forStudent($student)->forLesson($lesson)->create();

        $lesson->delete();

        $this->assertNull($attendance->fresh()->lesson_id, 'the mark should survive its lesson');
    }

    public function test_deleting_a_student_removes_their_marks(): void
    {
        $student = Student::factory()->create();
        Attendance::factory()->forStudent($student)->count(3)->create();

        $student->delete();

        $this->assertSame(0, Attendance::where('student_id', $student->id)->count());
    }

    public function test_the_index_can_be_filtered_by_date_and_class(): void
    {
        $class = SchoolClass::factory()->create();
        $student = Student::factory()->create(['school_class_id' => $class->id]);
        $other = Student::factory()->create();

        Attendance::factory()->forStudent($student)->on('2026-09-01')->create();
        Attendance::factory()->forStudent($student)->on('2026-09-02')->create();
        Attendance::factory()->forStudent($other)->on('2026-09-01')->create();

        $this->getJson('/api/attendances?date=2026-09-01')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson("/api/attendances?date=2026-09-01&school_class_id={$class->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_attendance_endpoints_require_authentication(): void
    {
        // Drop the acting-as guard rather than calling /api/logout, which
        // needs a stateful session the test client does not carry.
        $this->app['auth']->forgetGuards();

        $this->getJson('/api/attendances/at-risk')->assertUnauthorized();
    }

    /**
     * Every row carries a pupil, and StudentResource used to count on demand
     * when the tallies were not preloaded: 60 rows ran past 300 queries.
     */
    public function test_the_attendance_index_does_not_query_per_row(): void
    {
        $class = SchoolClass::factory()->create();
        $students = Student::factory()->count(20)->create(['school_class_id' => $class->id]);

        foreach ($students as $student) {
            $this->history($student, present: 2, absent: 1, late: 0);
        }

        DB::enableQueryLog();
        $this->getJson('/api/attendances?per_page=60')->assertOk()->assertJsonCount(60, 'data');
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThan(
            20,
            $queries,
            "the index should not count per row, but the request ran {$queries} queries",
        );
    }

    /** Only the roll-up is dropped; the identifying fields stay. */
    public function test_the_attendance_index_still_carries_the_nested_pupil(): void
    {
        $student = Student::factory()->create(['name' => 'Ines Hamdi']);
        $this->history($student, present: 1, absent: 0, late: 0);

        $this->getJson('/api/attendances')
            ->assertOk()
            ->assertJsonPath('data.0.student.id', $student->id)
            ->assertJsonPath('data.0.student.name', 'Ines Hamdi')
            ->assertJsonMissingPath('data.0.student.attendance');
    }

    /** The guard above must not have blanked a field the frontend uses. */
    public function test_the_roll_up_is_present_wherever_the_frontend_reads_it(): void
    {
        $class = SchoolClass::factory()->create();
        $student = Student::factory()->create(['school_class_id' => $class->id]);
        $this->history($student, present: 3, absent: 1, late: 0);

        // The pupils list, which shows a rate per row.
        $this->getJson('/api/students')
            ->assertOk()
            ->assertJsonPath('data.0.attendance.records', 4);

        // The pupil profile.
        $this->getJson("/api/students/{$student->id}")
            ->assertOk()
            ->assertJsonPath('data.attendance.records', 4);

        // The class profile, which shows a rate for every pupil on the roster.
        $this->getJson("/api/school-classes/{$class->id}")
            ->assertOk()
            ->assertJsonPath('data.students.0.attendance.records', 4);
    }

    public function test_bulk_marking_refuses_an_oversized_payload(): void
    {
        $student = Student::factory()->create();

        $marks = array_fill(0, 201, ['student_id' => $student->id, 'status' => Attendance::PRESENT]);

        $this->postJson('/api/attendances/bulk', [
            'date' => '2026-09-01',
            'marks' => $marks,
        ])->assertStatus(422)->assertJsonValidationErrors('marks');

        $this->assertSame(0, Attendance::count());
    }

    public function test_bulk_marking_accepts_a_payload_on_the_ceiling(): void
    {
        $students = Student::factory()->count(200)->create();

        $marks = $students
            ->map(fn (Student $student) => ['student_id' => $student->id, 'status' => Attendance::PRESENT])
            ->all();

        $this->postJson('/api/attendances/bulk', [
            'date' => '2026-09-01',
            'marks' => $marks,
        ])->assertOk()->assertJsonPath('saved', 200);
    }

    public function test_at_risk_reports_the_matricule_rather_than_a_dropped_column(): void
    {
        $student = Student::factory()->create(['matricule' => 'E-00042']);
        $this->history($student, present: 1, absent: 9, late: 0);

        $this->getJson('/api/attendances/at-risk')
            ->assertOk()
            ->assertJsonPath('data.0.matricule', 'E-00042')
            ->assertJsonMissingPath('data.0.email');
    }
}
