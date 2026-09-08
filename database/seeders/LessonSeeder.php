<?php

namespace Database\Seeders;

use App\Models\Lesson;
use App\Models\SchoolClass;
use App\Models\Teacher;
use App\Support\Curriculum;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;

class LessonSeeder extends Seeder
{
    /**
     * One lesson per subject per class. A lesson is the course, not one hour of
     * it: TimetableSeeder places it as many times a week as Curriculum says.
     */
    public function run(): void
    {
        $classes = SchoolClass::orderBy('level')->orderBy('name')->get();
        $teachers = Teacher::orderBy('id')->get();

        if ($classes->isEmpty() || $teachers->isEmpty()) {
            return;
        }

        foreach ($classes->groupBy(fn (SchoolClass $c) => Curriculum::bandForGrade((int) $c->level)) as $band => $bandClasses) {
            $this->seedBand($band, $bandClasses->values(), $teachers);
        }
    }

    /**
     * @param  Collection<int, SchoolClass>  $classes
     * @param  Collection<int, Teacher>  $teachers
     */
    private function seedBand(string $band, Collection $classes, Collection $teachers): void
    {
        $pools = TeacherSeeder::POOLS[$band] ?? [];
        $byName = $teachers->keyBy('name');

        // Empty in the upper band, which has no titulaires.
        $titulaires = collect($pools['titulaire'] ?? [])
            ->map(fn (string $name) => $byName->get($name))
            ->filter()
            ->values();

        foreach ($classes as $index => $class) {
            $grade = (int) $class->level ?: 1;
            $count = max($titulaires->count(), 1);

            $titulaire = $titulaires->get($index % $count);

            // The adjoint is the titulaire of the next class along.
            $adjoint = $titulaires->get(($index + 1) % $count) ?? $titulaire;

            foreach (array_keys(Curriculum::hoursForGrade($grade)) as $subject) {
                $post = Curriculum::roleFor($subject, $grade);

                $teacher = match ($post) {
                    'titulaire' => $titulaire,
                    'adjoint' => $adjoint,
                    default => $this->teacherFor($post, $index, $pools, $byName) ?? $titulaire,
                };

                if ($teacher === null) {
                    continue;
                }

                Lesson::factory()
                    ->taughtBy($teacher)
                    ->forClass($class)
                    ->create(['subject' => $subject]);
            }
        }
    }

    /**
     * Dealt round-robin across the band. Looked up by post, not subject, so the
     * two subjects sharing the civics post resolve to the same person.
     *
     * @param  array<string, list<string>>  $pools
     * @param  Collection<string, Teacher>  $byName
     */
    private function teacherFor(string $post, int $classIndex, array $pools, Collection $byName): ?Teacher
    {
        $names = $pools[$post] ?? null;

        if ($names === null || $names === []) {
            return null;
        }

        return $byName->get($names[$classIndex % count($names)]);
    }
}
