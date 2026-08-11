<?php

namespace Database\Factories;

use App\Models\SchoolClass;
use App\Models\Student;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Student>
 */
class StudentFactory extends Factory
{
    /**
     * The model the factory builds.
     *
     * @var class-string<Student>
     */
    protected $model = Student::class;

    /**
     * @var list<string>
     */
    public const FIRST_NAMES = [
        'Mohamed', 'Ahmed', 'Youssef', 'Amine', 'Skander',
        'Aziz', 'Firas', 'Khalil', 'Sami', 'Malek',
        'Aymen', 'Wael', 'Rania', 'Ines', 'Salma',
        'Nour', 'Yasmine', 'Mariem', 'Farah', 'Emna',
    ];

    /**
     * @var list<string>
     */
    public const LAST_NAMES = [
        'Ben Ali', 'Trabelsi', 'Mliki', 'Chkir', 'Gharbi',
        'Jebali', 'Bouazizi', 'Hamdi', 'Khelifi', 'Mejri',
        'Nasri', 'Ayari', 'Saidi', 'Baccouche', 'Belhadj',
        'Ferchichi', 'Zouari', 'Kacem', 'Riahi', 'Ouni',
    ];

    /**
     * Addresses already handed out during this process.
     *
     * @var list<string>
     */
    protected static array $usedEmails = [];

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->randomElement(self::FIRST_NAMES).' '.fake()->randomElement(self::LAST_NAMES);

        return [
            'name' => $name,
            'email' => $this->emailFor($name),
            'birth_date' => fake()->dateTimeBetween('-18 years', '-15 years')->format('Y-m-d'),
            'school_class_id' => SchoolClassFactory::new(),
        ];
    }

    /**
     * Enrol the student in the given class.
     */
    public function forClass(SchoolClass $schoolClass): static
    {
        return $this->state(fn (array $attributes) => [
            'school_class_id' => $schoolClass->id,
        ]);
    }

    /**
     * Build a school address from the student's name, numbering namesakes.
     */
    protected function emailFor(string $name): string
    {
        $base = Str::slug($name, '.');
        $email = $base.'@ecole.tn';

        for ($suffix = 2; $this->emailTaken($email); $suffix++) {
            $email = $base.$suffix.'@ecole.tn';
        }

        static::$usedEmails[] = $email;

        return $email;
    }

    /**
     * Determine whether an address is already in use.
     */
    protected function emailTaken(string $email): bool
    {
        return in_array($email, static::$usedEmails, true)
            || Student::where('email', $email)->exists();
    }
}
