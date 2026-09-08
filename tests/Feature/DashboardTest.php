<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Lesson;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\Timetable;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The two dashboard feeds: today's lessons, and the derived activity list.
 */
class DashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * Freeze the clock on a known weekday so the day-matching is tested
     * rather than whatever day the suite happens to run on.
     */
    private function freezeOn(string $date): void
    {
        Carbon::setTestNow(Carbon::parse($date.' 09:00:00'));
    }

    private function slotOn(string $day, string $start, string $end, string $title = 'Cours'): Timetable
    {
        $lesson = Lesson::factory()->create([
            'title' => $title,
            'teacher_id' => Teacher::factory()->create(['name' => 'Amel Bouazizi'])->id,
            'school_class_id' => SchoolClass::factory()->create(['name' => '3ème année B'])->id,
        ]);

        return Timetable::factory()->at($day, $start, $end)->create([
            'lesson_id' => $lesson->id,
            'room' => 'Salle 101',
        ]);
    }

    public function test_today_schedule_returns_only_todays_lessons_in_order(): void
    {
        // 2026-09-02 is a Wednesday.
        $this->freezeOn('2026-09-02');

        $this->slotOn('Mercredi', '11:15', '12:15', 'Deuxième');
        $this->slotOn('Mercredi', '08:00', '09:00', 'Première');
        $this->slotOn('Jeudi', '08:00', '09:00', 'Demain');

        $response = $this->getJson('/api/dashboard/today-schedule')->assertOk();

        $response->assertJsonPath('day', 'Mercredi')
            ->assertJsonPath('is_school_day', true)
            ->assertJsonPath('date', '2026-09-02')
            ->assertJsonCount(2, 'data');

        $this->assertSame(['Première', 'Deuxième'], array_column($response->json('data'), 'title'));
        $this->assertSame('08:00', $response->json('data.0.start_time'));
        $this->assertSame('Amel Bouazizi', $response->json('data.0.teacher'));
        $this->assertSame('3ème année B', $response->json('data.0.school_class'));
        $this->assertSame('Salle 101', $response->json('data.0.room'));
    }

    /**
     * Every teaching day resolves to its own French name.
     */
    public function test_each_weekday_matches_its_french_name(): void
    {
        $days = [
            '2026-08-31' => 'Lundi',
            '2026-09-01' => 'Mardi',
            '2026-09-02' => 'Mercredi',
            '2026-09-03' => 'Jeudi',
            '2026-09-04' => 'Vendredi',
            '2026-09-05' => 'Samedi',
        ];

        foreach ($days as $date => $expected) {
            $this->freezeOn($date);

            $this->getJson('/api/dashboard/today-schedule')
                ->assertOk()
                ->assertJsonPath('day', $expected)
                ->assertJsonPath('is_school_day', true);
        }
    }

    public function test_sunday_reports_the_school_as_closed(): void
    {
        // 2026-09-06 is a Sunday.
        $this->freezeOn('2026-09-06');

        $this->slotOn('Lundi', '08:00', '09:00');

        $this->getJson('/api/dashboard/today-schedule')
            ->assertOk()
            ->assertJsonPath('is_school_day', false)
            ->assertJsonPath('day', null)
            ->assertJsonCount(0, 'data');
    }

    public function test_recent_activity_labels_creations_and_edits(): void
    {
        $student = Student::factory()->create(['name' => 'Nouvel Élève']);

        $activity = $this->getJson('/api/dashboard/recent-activity')->assertOk()->json('data');

        $entry = collect($activity)->firstWhere('type', 'student');
        $this->assertSame('created', $entry['action']);
        $this->assertStringContainsString('Nouvel élève ajouté', $entry['label']);
        $this->assertStringContainsString('Nouvel Élève', $entry['label']);

        // Touching the row later turns it into an edit.
        Carbon::setTestNow(Carbon::now()->addHour());
        $student->update(['name' => 'Élève Renommé']);
        Carbon::setTestNow();

        $updated = collect($this->getJson('/api/dashboard/recent-activity')->json('data'))
            ->firstWhere('type', 'student');

        $this->assertSame('updated', $updated['action']);
        $this->assertStringContainsString('Élève modifié', $updated['label']);
    }

    public function test_recent_activity_reports_absences_but_not_presences(): void
    {
        $student = Student::factory()->create(['name' => 'Anis Saidi']);

        Attendance::factory()->forStudent($student)->absent()->create();
        Attendance::factory()->forStudent($student)->present()->count(5)->create();

        $activity = collect($this->getJson('/api/dashboard/recent-activity')->assertOk()->json('data'));
        $marks = $activity->where('type', 'attendance');

        $this->assertCount(1, $marks, 'only the absence belongs in the feed');
        $this->assertStringContainsString('Absence enregistrée', $marks->first()['label']);
        $this->assertStringContainsString('Anis Saidi', $marks->first()['label']);
    }

    /**
     * One busy table must not crowd out the rest of the feed.
     */
    public function test_no_single_table_can_fill_the_activity_feed(): void
    {
        Student::factory()->count(30)->create();
        Teacher::factory()->count(3)->create();

        $activity = collect($this->getJson('/api/dashboard/recent-activity')->assertOk()->json('data'));

        $this->assertLessThanOrEqual(5, $activity->where('type', 'student')->count());
        $this->assertNotEmpty($activity->where('type', 'teacher'));
    }

    public function test_recent_activity_is_newest_first_and_honours_a_limit(): void
    {
        Student::factory()->count(3)->create();
        Teacher::factory()->count(3)->create();
        SchoolClass::factory()->count(3)->create();

        $activity = $this->getJson('/api/dashboard/recent-activity?limit=4')->assertOk()->json('data');

        $this->assertCount(4, $activity);

        $timestamps = array_column($activity, 'at');
        $sorted = $timestamps;
        rsort($sorted);
        $this->assertSame($sorted, $timestamps, 'entries should be newest first');
    }

    /**
     * The donut's numbers. "Non renseigné" is a bucket in its own right, not
     * a rounding error to be folded into one of the other two.
     */
    public function test_stats_report_the_gender_split_including_unrecorded_pupils(): void
    {
        Student::factory()->count(4)->create(['gender' => Student::MALE]);
        Student::factory()->count(3)->create(['gender' => Student::FEMALE]);
        Student::factory()->count(2)->create(['gender' => null]);

        $this->getJson('/api/dashboard/stats')
            ->assertOk()
            ->assertJsonPath('gender.male', 4)
            ->assertJsonPath('gender.female', 3)
            ->assertJsonPath('gender.unknown', 2)
            ->assertJsonPath('gender.total', 9);
    }

    /**
     * The stat-tile badges. Each is derived from something already recorded,
     * so each has to survive a change in the underlying data.
     */
    public function test_stats_report_real_context_for_the_tile_badges(): void
    {
        $full = SchoolClass::factory()->create(['capacity' => 10]);
        $half = SchoolClass::factory()->create(['capacity' => 10]);

        Student::factory()->count(10)->create(['school_class_id' => $full->id]);
        Student::factory()->count(5)->create(['school_class_id' => $half->id]);
        // Not enrolled anywhere, so it takes up no place.
        Student::factory()->create(['school_class_id' => null]);

        Teacher::factory()->create(['subject' => 'Mathématiques']);
        Teacher::factory()->create(['subject' => 'Mathématiques']);
        Teacher::factory()->create(['subject' => 'Arabe']);

        $this->getJson('/api/dashboard/stats')
            ->assertOk()
            // 15 pupils enrolled across 20 declared places.
            ->assertJsonPath('context.class_occupancy', 75)
            // Distinct subjects, not teachers.
            ->assertJsonPath('context.teacher_subjects', 2);
    }

    /**
     * No class states a capacity, so there is nothing to be a percentage of.
     */
    public function test_occupancy_is_null_rather_than_a_division_by_zero(): void
    {
        $this->getJson('/api/dashboard/stats')
            ->assertOk()
            ->assertJsonPath('context.class_occupancy', null);
    }

    /**
     * The trend arrow needs two real registers. One day of marks is not a
     * comparison, and the dashboard must not manufacture one.
     */
    public function test_no_attendance_change_is_reported_without_both_days(): void
    {
        // 2026-09-03 is a Thursday, so the previous school day is Wednesday.
        $this->freezeOn('2026-09-03');

        $student = Student::factory()->create();
        Attendance::factory()->forStudent($student)->present()->on('2026-09-03')->create();

        $this->getJson('/api/dashboard/stats')
            ->assertOk()
            ->assertJsonPath('today.rate', 100)
            ->assertJsonPath('today.previous.date', '2026-09-02')
            ->assertJsonPath('today.previous.day', 'Mercredi')
            ->assertJsonPath('today.previous.rate', null)
            ->assertJsonPath('today.change', null);
    }

    public function test_attendance_change_compares_today_against_the_previous_school_day(): void
    {
        $this->freezeOn('2026-09-03');

        $student = Student::factory()->create();

        // Yesterday: one of two present, 50%.
        Attendance::factory()->forStudent($student)->present()->on('2026-09-02')->create();
        Attendance::factory()->forStudent($student)->absent()->on('2026-09-02')->create();
        // Today: both present, 100%.
        Attendance::factory()->forStudent($student)->present()->on('2026-09-03')->count(2)->create();

        $this->getJson('/api/dashboard/stats')
            ->assertOk()
            ->assertJsonPath('today.rate', 100)
            ->assertJsonPath('today.previous.rate', 50)
            ->assertJsonPath('today.change', 50);
    }

    /**
     * Monday looks back to Saturday. Sunday is not a day with no attendance,
     * it is a day the school was shut.
     */
    public function test_monday_compares_against_saturday_not_sunday(): void
    {
        // 2026-09-07 is a Monday; 2026-09-06 the Sunday before it.
        $this->freezeOn('2026-09-07');

        $this->getJson('/api/dashboard/stats')
            ->assertOk()
            ->assertJsonPath('today.previous.date', '2026-09-05')
            ->assertJsonPath('today.previous.day', 'Samedi');
    }

    public function test_attendance_week_covers_six_teaching_days_oldest_first(): void
    {
        $this->freezeOn('2026-09-03');

        $days = $this->getJson('/api/dashboard/attendance-week')->assertOk()->json('data');

        $this->assertCount(6, $days);
        $this->assertSame(
            ['2026-08-28', '2026-08-29', '2026-08-31', '2026-09-01', '2026-09-02', '2026-09-03'],
            array_column($days, 'date'),
            'the Sunday should be stepped over, not reported as an empty day',
        );
        $this->assertSame(
            ['Vendredi', 'Samedi', 'Lundi', 'Mardi', 'Mercredi', 'Jeudi'],
            array_column($days, 'day'),
        );

        // Samedi is the half day, and the only one flagged as such.
        $this->assertSame([false, true, false, false, false, false], array_column($days, 'half_day'));
    }

    public function test_attendance_week_counts_each_status_and_keeps_full_day_marks(): void
    {
        $this->freezeOn('2026-09-03');

        $student = Student::factory()->create();

        Attendance::factory()->forStudent($student)->present()->on('2026-09-02')->count(3)->create();
        Attendance::factory()->forStudent($student)->late()->on('2026-09-02')->create();
        // No lesson attached: a full-day absence still belongs in the chart.
        Attendance::factory()->forStudent($student)->absent()->forLesson(null)->on('2026-09-02')->create();

        $days = collect($this->getJson('/api/dashboard/attendance-week')->assertOk()->json('data'));
        $wednesday = $days->firstWhere('date', '2026-09-02');

        $this->assertSame(3, $wednesday['present']);
        $this->assertSame(1, $wednesday['late']);
        $this->assertSame(1, $wednesday['absent']);
        $this->assertSame(5, $wednesday['marked']);
        // A retard counts against the rate, as it does everywhere else.
        $this->assertSame(60, $wednesday['rate']);
    }

    /**
     * A Sunday still shows the week that has just finished rather than an
     * empty chart.
     */
    public function test_attendance_week_on_a_sunday_ends_at_saturday(): void
    {
        // 2026-09-06 is a Sunday.
        $this->freezeOn('2026-09-06');

        $days = $this->getJson('/api/dashboard/attendance-week')->assertOk()->json('data');

        $this->assertSame('2026-09-05', end($days)['date']);
        $this->assertSame('Samedi', end($days)['day']);
    }

    public function test_dashboard_endpoints_require_authentication(): void
    {
        // Drop the acting-as guard rather than calling /api/logout, which
        // needs a stateful session the test client does not carry.
        $this->app['auth']->forgetGuards();

        $this->getJson('/api/dashboard/today-schedule')->assertUnauthorized();
        $this->getJson('/api/dashboard/recent-activity')->assertUnauthorized();
        $this->getJson('/api/dashboard/attendance-week')->assertUnauthorized();
        $this->getJson('/api/dashboard/stats')->assertUnauthorized();
    }
}
