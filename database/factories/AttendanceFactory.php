<?php

namespace Database\Factories;

use App\Models\Attendance;
use App\Models\Lesson;
use App\Models\Student;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Attendance>
 */
class AttendanceFactory extends Factory
{
    /**
     * The model the factory builds.
     *
     * @var class-string<Attendance>
     */
    protected $model = Attendance::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'student_id' => StudentFactory::new(),
            'lesson_id' => LessonFactory::new(),
            'date' => fake()->dateTimeBetween('-30 days', 'now')->format('Y-m-d'),
            'status' => fake()->randomElement(Attendance::STATUSES),
            'note' => null,
        ];
    }

    /**
     * Mark a specific status.
     */
    public function status(string $status): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => $status,
        ]);
    }

    public function present(): static
    {
        return $this->status(Attendance::PRESENT);
    }

    public function absent(): static
    {
        return $this->status(Attendance::ABSENT);
    }

    public function late(): static
    {
        return $this->status(Attendance::LATE);
    }

    /**
     * Attach the mark to a pupil.
     */
    public function forStudent(Student $student): static
    {
        return $this->state(fn (array $attributes) => [
            'student_id' => $student->id,
        ]);
    }

    /**
     * Attach the mark to a lesson, or to no lesson at all for a full-day mark.
     */
    public function forLesson(?Lesson $lesson): static
    {
        return $this->state(fn (array $attributes) => [
            'lesson_id' => $lesson?->id,
        ]);
    }

    /**
     * Record the mark on a given day.
     */
    public function on(string $date): static
    {
        return $this->state(fn (array $attributes) => [
            'date' => $date,
        ]);
    }
}
