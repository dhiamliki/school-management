<?php

namespace Database\Factories;

use App\Models\SchoolClass;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SchoolClass> */
class SchoolClassFactory extends Factory
{
    /**
     * The model the factory builds.
     *
     * @var class-string<SchoolClass>
     */
    protected $model = SchoolClass::class;

    /**
     * The six grades of the Tunisian école primaire, mapped to the sections
     * each one is split into. Real schools size their grades to the intake of
     * the year, so the section count deliberately varies rather than being a
     * fixed number per grade.
     *
     * @var array<int, list<string>>
     */
    public const SECTIONS = [
        1 => ['A', 'B'],
        2 => ['A', 'B'],
        3 => ['A', 'B', 'C'],
        4 => ['A', 'B', 'C'],
        5 => ['A', 'B', 'C'],
        6 => ['A', 'B', 'C'],
    ];

    public static function gradeLabel(int $grade): string
    {
        return $grade === 1 ? '1ère année' : $grade.'ème année';
    }

    public static function className(int $grade, string $section): string
    {
        return self::gradeLabel($grade).' '.$section;
    }

    /**
     * Every class the school runs, as [grade, section, name] triples.
     *
     * @return list<array{grade: int, section: string, name: string}>
     */
    public static function roster(): array
    {
        $roster = [];

        foreach (self::SECTIONS as $grade => $sections) {
            foreach ($sections as $section) {
                $roster[] = [
                    'grade' => $grade,
                    'section' => $section,
                    'name' => self::className($grade, $section),
                ];
            }
        }

        return $roster;
    }

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $class = fake()->randomElement(self::roster());

        return [
            'name' => $class['name'],
            'level' => (string) $class['grade'],
            'capacity' => fake()->numberBetween(25, 35),
        ];
    }

    public function grade(int $grade, string $section): static
    {
        return $this->state(fn (array $attributes) => [
            'name' => self::className($grade, $section),
            'level' => (string) $grade,
        ]);
    }
}
