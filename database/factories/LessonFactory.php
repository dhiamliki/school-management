<?php

namespace Database\Factories;

use App\Models\Lesson;
use App\Models\SchoolClass;
use App\Models\Teacher;
use App\Support\Curriculum;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Lesson>
 */
class LessonFactory extends Factory
{
    /**
     * The model the factory builds.
     *
     * @var class-string<Lesson>
     */
    protected $model = Lesson::class;

    /**
     * Lesson titles available for each subject.
     *
     * @var array<string, list<string>>
     */
    public const TITLES = [
        'Langue Arabe' => ['Lecture et compréhension', 'Écriture et calligraphie', 'Grammaire arabe', 'Expression orale'],
        'Langue Française' => ['Lecture courante', 'Vocabulaire et orthographe', 'Conjugaison', 'Expression écrite'],
        'Langue Anglaise' => ['My Family', 'Colours and Numbers', 'At School', 'Daily Routine'],
        'Mathématiques' => ['Numération et calcul', 'Les quatre opérations', 'Géométrie élémentaire', 'Mesures et grandeurs'],
        'Éveil Scientifique et Civique' => ['Le corps humain', 'La vie en classe', 'Les saisons', 'Hygiène et santé'],
        'Éveil Scientifique' => ['Les plantes et les animaux', "L'eau et l'air", 'La matière', 'Le corps humain'],
        'Sciences et Technologie' => ['Le système digestif', 'Les circuits électriques', 'Les leviers et engrenages', 'La reproduction des plantes'],
        'Histoire-Géographie' => ['La Tunisie et ses régions', "L'histoire de la Tunisie", 'Le Maghreb', 'Lecture de cartes'],
        'Éducation Islamique' => ["Les piliers de l'Islam", 'Sourates et récitation', 'Les bonnes manières', 'Le Prophète et sa vie'],
        'Éducation Civique' => ["Les droits de l'enfant", 'La citoyenneté', 'Le respect des autres', 'Vivre ensemble'],
        'Éducation Artistique' => ['Dessin libre', 'Les couleurs primaires', 'Chants scolaires', 'Rythme et percussion'],
        'Éducation Physique' => ['Jeux collectifs', 'Course et endurance', 'Gymnastique au sol', 'Coordination et équilibre'],
    ];

    /**
     * Whether a subject belongs to a grade's programme.
     *
     * Delegates to the curriculum so the rule lives in one place: Français
     * starts in 3ème, Anglais in 5ème, and the lower band teaches a combined
     * awakening subject rather than separate science and civics.
     */
    public static function subjectFitsGrade(string $subject, int $grade): bool
    {
        return Curriculum::subjectFitsGrade($subject, $grade);
    }

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'teacher_id' => TeacherFactory::new(),
            'school_class_id' => SchoolClassFactory::new(),
            'subject' => fn (array $attributes) => Teacher::find($attributes['teacher_id'])?->subject
                ?? fake()->randomElement(Curriculum::allSubjects()),
            'title' => fn (array $attributes) => fake()->randomElement(
                self::TITLES[$attributes['subject']] ?? self::TITLES['Mathématiques'],
            ),
        ];
    }

    /**
     * Assign the lesson to a teacher; the subject and title follow from them.
     */
    public function taughtBy(Teacher $teacher): static
    {
        return $this->state(fn (array $attributes) => [
            'teacher_id' => $teacher->id,
        ]);
    }

    /**
     * Assign the lesson to a class.
     */
    public function forClass(SchoolClass $schoolClass): static
    {
        return $this->state(fn (array $attributes) => [
            'school_class_id' => $schoolClass->id,
        ]);
    }
}
