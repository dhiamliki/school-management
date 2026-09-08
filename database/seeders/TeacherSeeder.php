<?php

namespace Database\Seeders;

use App\Models\Teacher;
use App\Support\Curriculum;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class TeacherSeeder extends Seeder
{
    /**
     * The staff room. Nobody crosses a grade band.
     *
     * Lower and mid run a titulaire who owns a class plus an adjoint, who is the
     * titulaire of a neighbouring class. Upper is staffed by subject: each post
     * is one subject, taught across several classes.
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

        // 180 hours over six classes, split by subject against the 24h ceiling:
        // thirteen posts. Hours below are per holder once classes are dealt out.
        Curriculum::UPPER => [
            // 42h: two teachers, three classes each, 21h.
            'Langue Arabe' => ['Mohamed Nasri', 'Dorra Hamdi'],
            // 48h: three teachers, two classes each, 16h.
            'Langue Française' => ['Mouna Chkir', 'Ramzi Ouni', 'Amine Sassi'],
            // 30h: two teachers, three classes each, 15h.
            'Mathématiques' => ['Tarek Mejri', 'Sarra Ayari'],
            // 12h each: one teacher across all six classes.
            'Sciences et Technologie' => ['Nizar Saidi'],
            'Histoire-Géographie' => ['Hiba Khelifi'],
            Curriculum::CIVICS_POST => ['Rania Bouslama'],
            'Langue Anglaise' => ['Emna Bouden'],
            'Éducation Artistique' => ['Skander Laabidi'],
            'Éducation Physique' => ['Wafa Tounsi'],
        ],
    ];

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

    /** A titulaire has no single subject, so the column names their band instead. */
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

    protected function emailFor(string $name): string
    {
        return Str::slug($name, '.').'@ecole.tn';
    }
}
