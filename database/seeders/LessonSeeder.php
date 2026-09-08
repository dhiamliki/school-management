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
     * One lesson per subject per class, following the band programme.
     *
     * A lesson is the course itself, not one hour of it: "Langue Arabe for
     * 1ère année A" is a single row, which TimetableSeeder then places on the
     * grid as many times a week as Curriculum says it runs.
     *
     * Teachers come from their own band's pool, so no teacher ever ends up
     * with a lesson in both a 1ère and a 6ème class.
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

        // The titulaire owns one class, in the same order the pool lists them.
        // Empty in the upper band, which has no titulaires at all.
        $titulaires = collect($pools['titulaire'] ?? [])
            ->map(fn (string $name) => $byName->get($name))
            ->filter()
            ->values();

        foreach ($classes as $index => $class) {
            $grade = (int) $class->level ?: 1;
            $count = max($titulaires->count(), 1);

            $titulaire = $titulaires->get($index % $count);

            // The adjoint is the titulaire of the next class along, so the
            // core load is shared between two people without the school
            // hiring a second adult per class who would only work six hours.
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
     * The teacher holding a post for this class, or null when the post has no
     * pool of its own and falls back to the class's titulaire.
     *
     * Posts are dealt round-robin across the band's classes, so two Arabe
     * teachers take three classes each rather than one taking six. Looked up
     * by post rather than by subject: in the upper band the two are the same
     * for eight of the nine posts, but Éducation Islamique and Éducation
     * Civique share one, and both must resolve to the same person.
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
