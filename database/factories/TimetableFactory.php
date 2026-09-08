<?php

namespace Database\Factories;

use App\Models\Timetable;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Timetable> */
class TimetableFactory extends Factory
{
    /**
     * The model the factory builds.
     *
     * @var class-string<Timetable>
     */
    protected $model = Timetable::class;

    /**
     * The calendar lives on the model, which is the single source of truth
     * shared with the Form Requests and the seeder.
     *
     * @var list<string>
     */
    public const DAYS = Timetable::DAYS;

    /** @var list<array{string, string}> */
    public const SLOTS = Timetable::SLOTS;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $day = fake()->randomElement(Timetable::DAYS);

        // A half day only ever runs its morning slots.
        [$startTime, $endTime] = fake()->randomElement(Timetable::slotsFor($day));

        return [
            'lesson_id' => LessonFactory::new(),
            'day_of_week' => $day,
            'start_time' => $startTime,
            'end_time' => $endTime,
            'room' => 'Salle '.fake()->numberBetween(101, 210),
        ];
    }

    public function at(string $day, string $startTime, string $endTime): static
    {
        return $this->state(fn (array $attributes) => [
            'day_of_week' => $day,
            'start_time' => $startTime,
            'end_time' => $endTime,
        ]);
    }
}
