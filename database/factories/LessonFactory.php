<?php

namespace Database\Factories;

use App\Models\Lesson;
use App\Models\SchoolClass;
use App\Models\Teacher;
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
        'Mathématiques' => ['Algèbre linéaire', 'Fonctions et limites', 'Probabilités', 'Géométrie dans l\'espace'],
        'Physique' => ['Mécanique du point', 'Optique géométrique', 'Électricité', 'Ondes et vibrations'],
        'Français' => ['Analyse de texte', 'Le roman réaliste', 'Poésie et versification', 'Expression écrite'],
        'Anglais' => ['Grammar and Tenses', 'Reading Comprehension', 'Oral Expression', 'Business English'],
        'Histoire' => ['La Tunisie contemporaine', 'La Seconde Guerre mondiale', 'La décolonisation', 'Le monde bipolaire'],
        'SVT' => ['Génétique', 'Le système immunitaire', 'Géologie de la Tunisie', 'Reproduction humaine'],
    ];

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
                ?? fake()->randomElement(TeacherFactory::SUBJECTS),
            'title' => fn (array $attributes) => fake()->randomElement(self::TITLES[$attributes['subject']]),
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
