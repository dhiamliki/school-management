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
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The three profile endpoints. Each show() has to carry everything its page
 * needs in one request, while the matching index() stays lean - the extra
 * relations are whenLoaded, and nothing loads them on a list.
 */
class ProfileEndpointTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());
    }

    /**
     * A class with one teacher, one lesson, one timetable slot and one pupil.
     *
     * @return array{class: SchoolClass, teacher: Teacher, lesson: Lesson, student: Student, slot: Timetable}
     */
    private function school(): array
    {
        $class = SchoolClass::factory()->create(['name' => '3ème année B', 'level' => '3', 'capacity' => 30]);
        $teacher = Teacher::factory()->create(['name' => 'Salma Ben Ali', 'subject' => 'Mathématiques']);

        $lesson = Lesson::factory()->create([
            'title' => 'Numération et calcul',
            'subject' => 'Mathématiques',
            'teacher_id' => $teacher->id,
            'school_class_id' => $class->id,
        ]);

        $slot = Timetable::factory()->at('Mardi', '08:00', '09:00')->create([
            'lesson_id' => $lesson->id,
            'room' => 'Salle 106',
        ]);

        $student = Student::factory()->create(['name' => 'Anis Saidi', 'school_class_id' => $class->id]);

        return compact('class', 'teacher', 'lesson', 'student', 'slot');
    }

    public function test_student_show_carries_the_whole_profile(): void
    {
        ['student' => $student, 'lesson' => $lesson] = $this->school();

        Attendance::factory()->forStudent($student)->forLesson($lesson)->on('2026-09-01')->absent()->create();
        Attendance::factory()->forStudent($student)->forLesson(null)->on('2026-08-31')->present()->count(3)->create();

        $response = $this->getJson("/api/students/{$student->id}")->assertOk();

        // Own fields and class.
        $response->assertJsonPath('data.name', 'Anis Saidi')
            ->assertJsonPath('data.school_class.name', '3ème année B');

        // Attendance summary: 1 absent of 4 records = 75%.
        $response->assertJsonPath('data.attendance.records', 4)
            ->assertJsonPath('data.attendance.absences', 1)
            ->assertJsonPath('data.attendance.lates', 0);
        $this->assertEquals(75.0, $response->json('data.attendance.rate'));

        // Recent history, newest first, with its lesson attached.
        $this->assertCount(4, $response->json('data.attendance_history'));
        $this->assertSame('2026-09-01', $response->json('data.attendance_history.0.date'));
        $this->assertSame('absent', $response->json('data.attendance_history.0.status'));
        $this->assertSame('Numération et calcul', $response->json('data.attendance_history.0.lesson.title'));

        // The class's lessons, each carrying the slots the profile lays out.
        $this->assertSame('Numération et calcul', $response->json('data.school_class.lessons.0.title'));
        $this->assertSame('Salma Ben Ali', $response->json('data.school_class.lessons.0.teacher.name'));
        $this->assertSame('Mardi', $response->json('data.school_class.lessons.0.timetables.0.day_of_week'));
    }

    public function test_student_history_is_capped_and_newest_first(): void
    {
        ['student' => $student] = $this->school();

        // 20 marks on distinct days; only the newest 15 should come back.
        foreach (range(1, 20) as $day) {
            Attendance::factory()
                ->forStudent($student)
                ->forLesson(Lesson::factory()->create())
                ->on(sprintf('2026-08-%02d', $day))
                ->create();
        }

        $history = $this->getJson("/api/students/{$student->id}")->assertOk()->json('data.attendance_history');

        $this->assertCount(15, $history);
        $this->assertSame('2026-08-20', $history[0]['date'], 'newest first');
        $this->assertSame('2026-08-06', $history[14]['date']);

        // The summary still counts everything, not just the visible window.
        $this->assertSame(20, $this->getJson("/api/students/{$student->id}")->json('data.attendance.records'));
    }

    public function test_teacher_show_carries_classes_lesson_count_and_slots(): void
    {
        ['teacher' => $teacher, 'class' => $class] = $this->school();

        // A second lesson with the same class must not list the class twice.
        Lesson::factory()->create([
            'title' => 'Géométrie élémentaire',
            'teacher_id' => $teacher->id,
            'school_class_id' => $class->id,
        ]);

        $response = $this->getJson("/api/teachers/{$teacher->id}")->assertOk();

        $response->assertJsonPath('data.name', 'Salma Ben Ali')
            ->assertJsonPath('data.subject', 'Mathématiques')
            ->assertJsonPath('data.lessons_count', 2);

        $this->assertCount(1, $response->json('data.classes'), 'the class is served once, not once per lesson');
        $this->assertSame('3ème année B', $response->json('data.classes.0.name'));

        // Slots hang off the lessons, which is what the weekly view flattens.
        $this->assertSame('Mardi', $response->json('data.lessons.0.timetables.0.day_of_week'));
        $this->assertSame('Salle 106', $response->json('data.lessons.0.timetables.0.room'));
        $this->assertSame('3ème année B', $response->json('data.lessons.0.school_class.name'));
    }

    public function test_class_show_carries_roster_attendance_and_timetable(): void
    {
        ['class' => $class, 'student' => $student, 'lesson' => $lesson] = $this->school();

        $other = Student::factory()->create(['name' => 'Aymen Ayari', 'school_class_id' => $class->id]);

        // 1 absence out of 4 class-wide marks = 75%.
        Attendance::factory()->forStudent($student)->forLesson($lesson)->on('2026-09-01')->absent()->create();
        Attendance::factory()->forStudent($other)->forLesson($lesson)->on('2026-09-01')->present()->create();
        Attendance::factory()->forStudent($other)->forLesson(null)->on('2026-08-31')->present()->count(2)->create();

        $response = $this->getJson("/api/school-classes/{$class->id}")->assertOk();

        $response->assertJsonPath('data.name', '3ème année B')
            ->assertJsonPath('data.attendance.records', 4)
            ->assertJsonPath('data.attendance.absences', 1);
        $this->assertEquals(75.0, $response->json('data.attendance.rate'));

        // Roster, alphabetical, each pupil carrying their own rate.
        $roster = $response->json('data.students');
        $this->assertSame(['Anis Saidi', 'Aymen Ayari'], array_column($roster, 'name'));
        $this->assertEquals(0.0, $roster[0]['attendance']['rate'], 'Anis was absent for his only mark');
        $this->assertEquals(100.0, $roster[1]['attendance']['rate']);

        // Timetable and the staff serving the class.
        $this->assertSame('Mardi', $response->json('data.lessons.0.timetables.0.day_of_week'));
        $this->assertSame('Salma Ben Ali', $response->json('data.lessons.0.teacher.name'));
    }

    /**
     * The whole point of whenLoaded: none of the profile payload may leak
     * into a list response, where it would be paid for on every row.
     */
    public function test_index_responses_omit_the_profile_extras(): void
    {
        $this->school();

        $student = $this->getJson('/api/students')->assertOk()->json('data.0');
        $this->assertArrayNotHasKey('attendance_history', $student);
        // The cheap roll-up stays: it backs the list's attendance column.
        $this->assertArrayHasKey('attendance', $student);

        $teacher = $this->getJson('/api/teachers')->assertOk()->json('data.0');
        $this->assertArrayNotHasKey('classes', $teacher);
        $this->assertArrayNotHasKey('lessons_count', $teacher);
        $this->assertArrayNotHasKey('lessons', $teacher);

        $class = $this->getJson('/api/school-classes')->assertOk()->json('data.0');
        $this->assertArrayNotHasKey('attendance', $class);
        $this->assertArrayNotHasKey('students', $class);
        $this->assertArrayNotHasKey('lessons', $class);
    }

    public function test_a_class_with_no_attendance_reports_a_null_rate(): void
    {
        ['class' => $class] = $this->school();

        $response = $this->getJson("/api/school-classes/{$class->id}")->assertOk();

        $this->assertSame(0, $response->json('data.attendance.records'));
        $this->assertNull($response->json('data.attendance.rate'), 'no history is not 0% attendance');
    }

    public function test_a_missing_profile_answers_404(): void
    {
        $this->getJson('/api/students/999999')->assertNotFound();
        $this->getJson('/api/teachers/999999')->assertNotFound();
        $this->getJson('/api/school-classes/999999')->assertNotFound();
    }

    /**
     * The roster must not cost a query per pupil: StudentResource reports
     * every pupil's attendance, so the tallies have to be preloaded.
     */
    public function test_the_class_roster_does_not_query_per_pupil(): void
    {
        ['class' => $class] = $this->school();

        Student::factory()->count(25)->create(['school_class_id' => $class->id]);

        DB::enableQueryLog();
        $this->getJson("/api/school-classes/{$class->id}")->assertOk();
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThan(
            20,
            $queries,
            "the roster should be eager-loaded, but the request ran {$queries} queries",
        );
    }
}
