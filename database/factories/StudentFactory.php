<?php

namespace Database\Factories;

use App\Models\SchoolClass;
use App\Models\Student;
use App\Support\TunisianNames;
use Illuminate\Database\Eloquent\Factories\Factory;

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
     * The given names on offer, boys' and girls' together.
     *
     * The split itself lives in TunisianNames so the factory can record which
     * one it drew from - see definition().
     *
     * @var list<string>
     */
    public const FIRST_NAMES = [
        ...TunisianNames::MALE_FIRST_NAMES,
        ...TunisianNames::FEMALE_FIRST_NAMES,
    ];

    /**
     * @var list<string>
     */
    public const LAST_NAMES = [
        'Ben Ali', 'Trabelsi', 'Mliki', 'Chkir', 'Gharbi',
        'Jebali', 'Bouazizi', 'Hamdi', 'Khelifi', 'Mejri',
        'Nasri', 'Ayari', 'Saidi', 'Baccouche', 'Belhadj',
        'Ferchichi', 'Zouari', 'Kacem', 'Riahi', 'Ouni',
        'Ben Salah', 'Jelassi', 'Bouden', 'Chaabane', 'Mansour',
        'Sassi', 'Dridi', 'Hachicha', 'Karoui', 'Laabidi',
        'Marzouki', 'Neffati', 'Rekik', 'Slimani', 'Tounsi',
        'Werghi', 'Yahyaoui', 'Zaidi', 'Amri', 'Bacha',
    ];

    /**
     * Matricules already handed out during this process.
     *
     * @var list<string>
     */
    protected static array $usedMatricules = [];

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $firstName = fake()->randomElement(self::FIRST_NAMES);
        $name = $firstName.' '.fake()->randomElement(self::LAST_NAMES);

        return [
            'name' => $name,
            'matricule' => $this->nextMatricule(),
            // Read back off the name rather than rolled separately, so the
            // two can never contradict each other.
            'gender' => TunisianNames::genderFor($firstName),
            'birth_date' => self::birthDateForGrade(fake()->numberBetween(1, 6)),
            'school_class_id' => SchoolClassFactory::new(),
        ];
    }

    /**
     * Enrol the student in the given class. Their age follows from the grade:
     * a pupil starts 1ère année at six and leaves 6ème année at twelve.
     */
    public function forClass(SchoolClass $schoolClass): static
    {
        return $this->state(fn (array $attributes) => [
            'school_class_id' => $schoolClass->id,
            'birth_date' => self::birthDateForGrade((int) $schoolClass->level ?: 1),
        ]);
    }

    /**
     * A birth date putting the pupil in the usual age band for their grade,
     * with the year either side that repeats and early starts produce.
     */
    protected static function birthDateForGrade(int $grade): string
    {
        $age = 5 + max(1, min(6, $grade));

        return fake()->dateTimeBetween('-'.($age + 1).' years', '-'.$age.' years')->format('Y-m-d');
    }

    /**
     * The next free matricule.
     *
     * The model derives one from a row id, which does not exist yet at this
     * point, so the factory counts up from the numbers already issued and
     * skips any that are taken.
     */
    protected function nextMatricule(): string
    {
        $number = count(static::$usedMatricules) + 1;

        while ($this->matriculeTaken($matricule = Student::matriculeFor($number))) {
            $number++;
        }

        static::$usedMatricules[] = $matricule;

        return $matricule;
    }

    /**
     * Determine whether a matricule is already in use.
     */
    protected function matriculeTaken(string $matricule): bool
    {
        return in_array($matricule, static::$usedMatricules, true)
            || Student::where('matricule', $matricule)->exists();
    }
}
