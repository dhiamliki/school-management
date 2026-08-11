<?php

namespace Database\Factories;

use App\Models\SchoolClass;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SchoolClass>
 */
class SchoolClassFactory extends Factory
{
    /**
     * The model the factory builds.
     *
     * @var class-string<SchoolClass>
     */
    protected $model = SchoolClass::class;

    /**
     * The class names available at the school, mapped to their level.
     *
     * @var array<string, string>
     */
    public const CLASSES = [
        '1ère A' => 'Première',
        '2ème B' => 'Deuxième',
        '3ème A' => 'Troisième',
        'Terminale S' => 'Terminale',
        'Terminale L' => 'Terminale',
    ];

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->randomElement(array_keys(self::CLASSES));

        return [
            'name' => $name,
            'level' => self::CLASSES[$name],
            'capacity' => fake()->numberBetween(25, 35),
        ];
    }
}
