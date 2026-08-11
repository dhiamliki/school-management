<?php

namespace Database\Factories;

use App\Models\Teacher;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Teacher>
 */
class TeacherFactory extends Factory
{
    /**
     * The model the factory builds.
     *
     * @var class-string<Teacher>
     */
    protected $model = Teacher::class;

    /**
     * The subjects taught at the school.
     *
     * @var list<string>
     */
    public const SUBJECTS = [
        'Mathématiques',
        'Physique',
        'Français',
        'Anglais',
        'Histoire',
        'SVT',
    ];

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake('fr_FR')->name(),
            'email' => fake('fr_FR')->unique()->safeEmail(),
            'phone' => $this->tunisianPhone(),
            'subject' => fake()->randomElement(self::SUBJECTS),
        ];
    }

    /**
     * Indicate the subject the teacher is responsible for.
     */
    public function subject(string $subject): static
    {
        return $this->state(fn (array $attributes) => [
            'subject' => $subject,
        ]);
    }

    /**
     * Build a Tunisian mobile number.
     */
    protected function tunisianPhone(): string
    {
        $prefix = fake()->randomElement([
            '20', '21', '22', '23', '24', '25', '26', '27', '28', '29',
            '50', '51', '52', '53', '54', '55', '56', '58',
            '90', '91', '92', '93', '94', '95', '96', '97', '98', '99',
        ]);

        return '+216 '.$prefix.' '.fake()->numerify('### ###');
    }
}
