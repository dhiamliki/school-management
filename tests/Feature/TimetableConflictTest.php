<?php

namespace Tests\Feature;

use App\Models\Lesson;
use App\Models\SchoolClass;
use App\Models\Teacher;
use App\Models\Timetable;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The interesting cases are the near misses: back-to-back slots, the same
 * hour on another day, a slot against itself on update.
 */
class TimetableConflictTest extends TestCase
{
    use RefreshDatabase;

    private Teacher $teacher;

    private SchoolClass $class;

    private Lesson $lesson;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());

        $this->teacher = Teacher::factory()->create(['name' => 'Salma Ben Ali', 'subject' => 'Mathématiques']);
        $this->class = SchoolClass::factory()->create(['name' => '3ème année A', 'level' => '3']);
        $this->lesson = Lesson::factory()->create([
            'title' => 'Numération et calcul',
            'teacher_id' => $this->teacher->id,
            'school_class_id' => $this->class->id,
        ]);
    }

    private function existing(string $start = '08:00', string $end = '09:00', ?Lesson $lesson = null, string $room = 'Salle 106'): Timetable
    {
        return Timetable::factory()->at('Lundi', $start, $end)->create([
            'lesson_id' => ($lesson ?? $this->lesson)->id,
            'room' => $room,
        ]);
    }

    private function unrelatedLesson(): Lesson
    {
        return Lesson::factory()->create([
            'title' => 'Lecture courante',
            'teacher_id' => Teacher::factory()->create(['name' => 'Amel Bouazizi'])->id,
            'school_class_id' => SchoolClass::factory()->create(['name' => '4ème année B'])->id,
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'lesson_id' => $this->lesson->id,
            'day_of_week' => 'Lundi',
            'start_time' => '08:00',
            'end_time' => '09:00',
            'room' => 'Salle 106',
        ], $overrides);
    }

    public function test_a_teacher_cannot_be_booked_over_an_existing_slot(): void
    {
        $this->existing();

        // Same teacher, different class and room, overlapping 08:30-09:30.
        $other = Lesson::factory()->create([
            'title' => 'Géométrie élémentaire',
            'teacher_id' => $this->teacher->id,
            'school_class_id' => SchoolClass::factory()->create(['name' => '5ème année C'])->id,
        ]);

        $response = $this->postJson('/api/timetables', $this->payload([
            'lesson_id' => $other->id,
            'start_time' => '08:30',
            'end_time' => '09:30',
            'room' => 'Salle 200',
        ]))->assertUnprocessable();

        $message = $response->json('errors.lesson_id.0');
        $this->assertStringContainsString('Salma Ben Ali', $message);
        $this->assertStringContainsString('de 08:00 à 09:00 le Lundi', $message);
        $this->assertStringContainsString('Numération et calcul', $message);

        $this->assertSame(1, Timetable::count(), 'nothing should have been written');
    }

    public function test_a_room_cannot_be_double_booked(): void
    {
        $this->existing();

        $response = $this->postJson('/api/timetables', $this->payload([
            'lesson_id' => $this->unrelatedLesson()->id,
            'start_time' => '08:30',
            'end_time' => '09:30',
            'room' => 'Salle 106',
        ]))->assertUnprocessable();

        $this->assertStringContainsString('Salle 106', $response->json('errors.room.0'));
        $this->assertStringContainsString('déjà occupée', $response->json('errors.room.0'));
    }

    public function test_a_class_cannot_be_in_two_places_at_once(): void
    {
        $this->existing();

        // Same class, different teacher and room.
        $other = Lesson::factory()->create([
            'title' => 'Lecture courante',
            'teacher_id' => Teacher::factory()->create(['name' => 'Amel Bouazizi'])->id,
            'school_class_id' => $this->class->id,
        ]);

        $response = $this->postJson('/api/timetables', $this->payload([
            'lesson_id' => $other->id,
            'start_time' => '08:30',
            'end_time' => '09:30',
            'room' => 'Salle 200',
        ]))->assertUnprocessable();

        $this->assertStringContainsString('3ème année A', $response->json('errors.lesson_id.0'));
    }

    /**
     * The case that makes naive implementations wrong: 08:00-09:00 and
     * 09:00-10:00 touch but do not overlap.
     */
    public function test_back_to_back_slots_are_not_a_conflict(): void
    {
        $this->existing('08:00', '09:00');

        $this->postJson('/api/timetables', $this->payload([
            'start_time' => '09:00',
            'end_time' => '10:00',
        ]))->assertCreated();

        $this->assertSame(2, Timetable::count());
    }

    /** And the mirror image: a slot ending exactly when another begins. */
    public function test_a_slot_ending_where_another_starts_is_allowed(): void
    {
        $this->existing('09:00', '10:00');

        $this->postJson('/api/timetables', $this->payload([
            'start_time' => '08:00',
            'end_time' => '09:00',
        ]))->assertCreated();
    }

    public function test_the_same_time_on_a_different_day_is_allowed(): void
    {
        $this->existing();

        $this->postJson('/api/timetables', $this->payload([
            'day_of_week' => 'Mardi',
        ]))->assertCreated();
    }

    public function test_the_same_time_with_a_different_teacher_room_and_class_is_allowed(): void
    {
        $this->existing();

        $this->postJson('/api/timetables', $this->payload([
            'lesson_id' => $this->unrelatedLesson()->id,
            'room' => 'Salle 200',
        ]))->assertCreated();
    }

    /** Two slots that record no room must not collide on the empty value. */
    public function test_slots_without_a_room_do_not_collide(): void
    {
        $this->existing(room: '');

        $this->postJson('/api/timetables', $this->payload([
            'lesson_id' => $this->unrelatedLesson()->id,
            'room' => null,
        ]))->assertCreated();
    }

    public function test_a_slot_does_not_conflict_with_itself_on_update(): void
    {
        $slot = $this->existing();

        // Saving the row unchanged must pass.
        $this->putJson("/api/timetables/{$slot->id}", $this->payload())->assertOk();

        // And so must an edit that only touches the room.
        $this->putJson("/api/timetables/{$slot->id}", $this->payload(['room' => 'Salle 999']))
            ->assertOk();

        $this->assertSame('Salle 999', $slot->fresh()->room);
    }

    public function test_an_update_still_conflicts_with_other_slots(): void
    {
        $slot = $this->existing('14:00', '15:00');
        $this->existing('08:00', '09:00');

        // Moving the afternoon slot onto the morning one is refused.
        $this->putJson("/api/timetables/{$slot->id}", $this->payload([
            'start_time' => '08:30',
            'end_time' => '09:30',
        ]))->assertUnprocessable();

        $this->assertSame('14:00:00', $slot->fresh()->start_time, 'the slot should not have moved');
    }

    public function test_a_slot_can_report_several_kinds_of_conflict(): void
    {
        $this->existing();

        $response = $this->postJson('/api/timetables', $this->payload([
            'start_time' => '08:30',
            'end_time' => '09:30',
        ]))->assertUnprocessable();

        // Same lesson, so teacher and class both clash, and so does the room.
        $errors = $response->json('errors');
        $this->assertArrayHasKey('room', $errors);
        $this->assertArrayHasKey('lesson_id', $errors);
        $this->assertGreaterThanOrEqual(2, count($errors['lesson_id']), 'teacher and class both clash');
    }

    public function test_check_conflicts_reports_without_writing_anything(): void
    {
        $this->existing();

        $response = $this->getJson('/api/timetables/check-conflicts?'.http_build_query([
            'day_of_week' => 'Lundi',
            'start_time' => '08:30',
            'end_time' => '09:30',
            'teacher_id' => $this->teacher->id,
            'school_class_id' => $this->class->id,
            'room' => 'Salle 106',
        ]))->assertOk();

        $response->assertJsonPath('has_conflicts', true);

        $kinds = array_column($response->json('conflicts'), 'kind');
        $this->assertEqualsCanonicalizing(['teacher', 'room', 'school_class'], $kinds);

        $this->assertStringContainsString('Salma Ben Ali', $response->json('conflicts.0.message'));

        // The whole point: nothing was persisted.
        $this->assertSame(1, Timetable::count());
    }

    public function test_check_conflicts_is_clean_for_a_free_slot(): void
    {
        $this->existing();

        $this->getJson('/api/timetables/check-conflicts?'.http_build_query([
            'day_of_week' => 'Lundi',
            'start_time' => '09:00',
            'end_time' => '10:00',
            'teacher_id' => $this->teacher->id,
            'school_class_id' => $this->class->id,
            'room' => 'Salle 106',
        ]))->assertOk()
            ->assertJsonPath('has_conflicts', false)
            ->assertJsonCount(0, 'conflicts');
    }

    public function test_check_conflicts_can_exclude_the_row_being_edited(): void
    {
        $slot = $this->existing();

        $query = [
            'day_of_week' => 'Lundi',
            'start_time' => '08:00',
            'end_time' => '09:00',
            'teacher_id' => $this->teacher->id,
            'school_class_id' => $this->class->id,
            'room' => 'Salle 106',
        ];

        $this->getJson('/api/timetables/check-conflicts?'.http_build_query($query))
            ->assertOk()
            ->assertJsonPath('has_conflicts', true);

        $this->getJson('/api/timetables/check-conflicts?'.http_build_query($query + ['exclude_id' => $slot->id]))
            ->assertOk()
            ->assertJsonPath('has_conflicts', false);
    }

    public function test_check_conflicts_validates_its_input(): void
    {
        $this->getJson('/api/timetables/check-conflicts?day_of_week=Dimanche&start_time=08:00&end_time=09:00')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('day_of_week');

        $this->getJson('/api/timetables/check-conflicts?day_of_week=Lundi&start_time=10:00&end_time=09:00')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('end_time');
    }
}
