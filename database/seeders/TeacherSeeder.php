<?php

namespace Database\Seeders;

use App\Models\Teacher;
use App\Support\Curriculum;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class TeacherSeeder extends Seeder
{
    /**
     * The staff room, organised the way a Tunisian primary school actually
     * staffs itself.
     *
     * Teachers belong to one grade band and never cross it: the person who
     * teaches Mathématiques to a 1ère année class is not the person who
     * teaches it to a 6ème.
     *
     * The lower and mid bands run the titulaire model. A titulaire owns one
     * class and teaches the bulk of its core programme - but not all of it.
     * Each also works as adjoint in a neighbouring class of the same band,
     * taking the core subjects its own titulaire does not, so every class is
     * shared between two core teachers and neither is booked for the whole
     * week. Specialists cover the rest.
     *
     * The upper band does not. Every post there names a subject, and the
     * teacher holding it teaches that subject across several classes, the way
     * collège works. The pool is therefore sized by subject rather than by
     * class: see the note on each entry for the arithmetic.
     *
     * Keys are posts, matching Curriculum::ROLES. Titulaires are assigned to a
     * class in order by LessonSeeder; every other post is dealt round-robin
     * across the band's classes.
     *
     * @var array<string, array<string, list<string>>>
     */
    public const POOLS = [
        Curriculum::LOWER => [
            // One per lower-band class (1ère A/B, 2ème A/B).
            'titulaire' => ['Hédi Jelassi', 'Amel Bouazizi', 'Salma Ben Ali', 'Leïla Gharbi'],
            'Éducation Artistique' => ['Rim Bouden'],
            'Éducation Physique' => ['Mehdi Zouari'],
        ],
        Curriculum::MID => [
            // One per mid-band class (3ème A/B/C, 4ème A/B/C).
            'titulaire' => [
                'Youssef Mansour', 'Sonia Khelifi', 'Karim Trabelsi',
                'Ines Ferchichi', 'Walid Baccouche', 'Faten Riahi',
            ],
            // Two share the six classes, three each.
            'Langue Française' => ['Nadia Chaabane', 'Olfa Zouari'],
            'Éducation Artistique' => ['Slim Kacem'],
            'Éducation Physique' => ['Anis Belhadj'],
        ],

        // Six classes at 30h each is 180 hours of teaching. Split by subject
        // and divided by the 24h ceiling, that is thirteen posts. The hours
        // below are what one holder of the post ends up with once the classes
        // are dealt out evenly.
        Curriculum::UPPER => [
            // 42h across six classes. Two teachers, three classes each, 21h.
            'Langue Arabe' => ['Mohamed Nasri', 'Dorra Hamdi'],
            // 48h. Three teachers, two classes each, 16h - the split this
            // band already ran, left alone.
            'Langue Française' => ['Mouna Chkir', 'Ramzi Ouni', 'Amine Sassi'],
            // 30h. Two teachers, three classes each, 15h.
            'Mathématiques' => ['Tarek Mejri', 'Sarra Ayari'],
            // 12h each: one teacher covers all six classes.
            'Sciences et Technologie' => ['Nizar Saidi'],
            'Histoire-Géographie' => ['Hiba Khelifi'],
            // The one post covering two subjects, an hour of each per class.
            Curriculum::CIVICS_POST => ['Rania Bouslama'],
            'Langue Anglaise' => ['Emna Bouden'],
            // 6h each.
            'Éducation Artistique' => ['Skander Laabidi'],
            'Éducation Physique' => ['Wafa Tounsi'],
        ],
    ];

    /**
     * Seed the teachers table.
     */
    public function run(): void
    {
        foreach (self::POOLS as $band => $roles) {
            foreach ($roles as $role => $names) {
                foreach ($names as $name) {
                    Teacher::factory()->create([
                        'name' => $name,
                        'email' => $this->emailFor($name),
                        'subject' => $this->subjectFor($role, $band),
                    ]);
                }
            }
        }
    }

    /**
     * What the staff list shows for this post.
     *
     * A titulaire has no single subject - that is the point of the role - so
     * the column names the band they teach instead. Every other post, upper
     * band included, is named by the subject it covers, so the column says
     * something a reader can act on.
     */
    protected function subjectFor(string $role, string $band): string
    {
        if ($role !== 'titulaire') {
            return $role;
        }

        return match ($band) {
            Curriculum::MID => 'Enseignement polyvalent (3ème-4ème)',
            default => 'Enseignement polyvalent (1ère-2ème)',
        };
    }

    /**
     * Build a school address from the teacher's name.
     */
    protected function emailFor(string $name): string
    {
        return Str::slug($name, '.').'@ecole.tn';
    }
}
