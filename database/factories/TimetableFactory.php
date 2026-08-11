<?php

namespace Database\Factories;

use App\Models\Timetable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Timetable>
 */
class TimetableFactory extends Factory
{
    /**
     * The model the factory builds.
     *
     * @var class-string<Timetable>
     */
    protected $model = Timetable::class;

    /**
     * The teaching days of the week.
     *
     * @var list<string>
     */
    public const DAYS = ['Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi'];

    /**
     * The school hours, as start and end pairs.
     *
     * @var list<array{string, string}>
     */
    public const SLOTS = [
        ['08:00', '09:00'],
        ['09:00', '10:00'],
        ['10:15', '11:15'],
        ['11:15', '12:15'],
        ['14:00', '15:00'],
        ['15:00', '16:00'],
    ];

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        [$startTime, $endTime] = fake()->randomElement(self::SLOTS);

        return [
            'lesson_id' => LessonFactory::new(),
            'day_of_week' => fake()->randomElement(self::DAYS),
            'start_time' => $startTime,
            'end_time' => $endTime,
            'room' => 'Salle '.fake()->numberBetween(101, 210),
        ];
    }

    /**
     * Place the slot on a specific day and time.
     */
    public function at(string $day, string $startTime, string $endTime): static
    {
        return $this->state(fn (array $attributes) => [
            'day_of_week' => $day,
            'start_time' => $startTime,
            'end_time' => $endTime,
        ]);
    }
}
