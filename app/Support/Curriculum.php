<?php

namespace App\Support;

/**
 * The Tunisian primary curriculum: weekly hours per subject per grade band.
 * One slot is one hour. Seeders build from this and tests assert against it.
 */
class Curriculum
{
    public const LOWER = 'lower';

    public const MID = 'mid';

    public const UPPER = 'upper';

    /** @var array<string, list<int>> */
    public const BANDS = [
        self::LOWER => [1, 2],
        self::MID => [3, 4],
        self::UPPER => [5, 6],
    ];

    /**
     * Half hours in the official programme are rounded to whole slots, chosen
     * so each band totals 21 / 28 / 30.
     *
     * @var array<string, array<string, int>>
     */
    public const HOURS = [
        // Science and civics are one combined subject at this age.
        self::LOWER => [
            'Langue Arabe' => 11,
            'Mathématiques' => 5,
            'Éveil Scientifique et Civique' => 2,
            'Éducation Islamique' => 1,
            'Éducation Artistique' => 1,
            'Éducation Physique' => 1,
        ],

        // Français starts here, never earlier.
        self::MID => [
            'Langue Arabe' => 9,
            'Langue Française' => 7,
            'Mathématiques' => 5,
            'Éveil Scientifique' => 2,
            'Histoire-Géographie' => 1,
            'Éducation Islamique' => 1,
            'Éducation Civique' => 1,
            'Éducation Artistique' => 1,
            'Éducation Physique' => 1,
        ],

        // Anglais starts here, never earlier.
        self::UPPER => [
            'Langue Arabe' => 7,
            'Langue Française' => 8,
            'Langue Anglaise' => 2,
            'Mathématiques' => 5,
            'Sciences et Technologie' => 2,
            'Histoire-Géographie' => 2,
            'Éducation Islamique' => 1,
            'Éducation Civique' => 1,
            'Éducation Artistique' => 1,
            'Éducation Physique' => 1,
        ],
    ];

    /** The one upper-band post covering two subjects. */
    public const CIVICS_POST = 'Éducation Islamique et Civique';

    /** @var list<string> */
    public const TITULAIRE_BANDS = [self::LOWER, self::MID];

    /**
     * Which post teaches each subject. Lower and mid run a titulaire plus an
     * adjoint; the upper band is staffed by subject, so a post cannot span
     * unrelated subjects because the post is the subject.
     *
     * @var array<string, array<string, string>>
     */
    public const ROLES = [
        // Titulaire 13h of 21 (62%), adjoint 6h.
        self::LOWER => [
            'Langue Arabe' => 'titulaire',
            'Éveil Scientifique et Civique' => 'titulaire',
            'Mathématiques' => 'adjoint',
            'Éducation Islamique' => 'adjoint',
            'Éducation Artistique' => 'Éducation Artistique',
            'Éducation Physique' => 'Éducation Physique',
        ],

        // Titulaire 16h of 28 (57%), adjoint 3h.
        self::MID => [
            'Langue Arabe' => 'titulaire',
            'Mathématiques' => 'titulaire',
            'Éveil Scientifique' => 'titulaire',
            'Éducation Islamique' => 'adjoint',
            'Éducation Civique' => 'adjoint',
            'Histoire-Géographie' => 'adjoint',
            'Langue Française' => 'Langue Française',
            'Éducation Artistique' => 'Éducation Artistique',
            'Éducation Physique' => 'Éducation Physique',
        ],

        // No titulaire. Nine subject posts, the largest holding 8h of 30.
        self::UPPER => [
            'Langue Arabe' => 'Langue Arabe',
            'Mathématiques' => 'Mathématiques',
            'Sciences et Technologie' => 'Sciences et Technologie',
            'Histoire-Géographie' => 'Histoire-Géographie',
            'Éducation Islamique' => self::CIVICS_POST,
            'Éducation Civique' => self::CIVICS_POST,
            'Langue Française' => 'Langue Française',
            'Langue Anglaise' => 'Langue Anglaise',
            'Éducation Artistique' => 'Éducation Artistique',
            'Éducation Physique' => 'Éducation Physique',
        ],
    ];

    public const MAX_CLASS_SHARE = 0.65;

    public const MAX_WEEKLY_LOAD = 24;

    /**
     * In a titulaire band: titulaire, adjoint or a specialist post. In the upper
     * band: always the subject post itself.
     */
    public static function roleFor(string $subject, int $grade): string
    {
        $band = self::bandForGrade($grade);

        return self::ROLES[$band][$subject]
            ?? (self::usesTitulaireModel($band) ? 'titulaire' : $subject);
    }

    public static function usesTitulaireModel(string $band): bool
    {
        return in_array($band, self::TITULAIRE_BANDS, true);
    }

    public static function isSpecialisedGrade(int $grade): bool
    {
        return ! self::usesTitulaireModel(self::bandForGrade($grade));
    }

    /**
     * Posts in a band and the weekly hours each owes one class. Sizes the pool.
     *
     * @return array<string, int>
     */
    public static function postsForBand(string $band): array
    {
        $posts = [];

        foreach (self::HOURS[$band] as $subject => $hours) {
            $post = self::ROLES[$band][$subject] ?? $subject;
            $posts[$post] = ($posts[$post] ?? 0) + $hours;
        }

        return $posts;
    }

    /** @return list<string> */
    public static function subjectsForPost(string $band, string $post): array
    {
        $subjects = [];

        foreach (array_keys(self::HOURS[$band]) as $subject) {
            if ((self::ROLES[$band][$subject] ?? $subject) === $post) {
                $subjects[] = $subject;
            }
        }

        return $subjects;
    }

    public static function hoursForRole(string $role, int $grade): int
    {
        $hours = 0;

        foreach (self::hoursForGrade($grade) as $subject => $count) {
            if (self::roleFor($subject, $grade) === $role) {
                $hours += $count;
            }
        }

        return $hours;
    }

    public static function bandForGrade(int $grade): string
    {
        foreach (self::BANDS as $band => $grades) {
            if (in_array($grade, $grades, true)) {
                return $band;
            }
        }

        return self::LOWER;
    }

    /**
     * The subject/hours map a grade studies.
     *
     * @return array<string, int>
     */
    public static function hoursForGrade(int $grade): array
    {
        return self::HOURS[self::bandForGrade($grade)];
    }

    public static function weeklyHours(int $grade): int
    {
        return array_sum(self::hoursForGrade($grade));
    }

    /** Whether a subject belongs to a grade's programme. */
    public static function subjectFitsGrade(string $subject, int $grade): bool
    {
        return array_key_exists($subject, self::hoursForGrade($grade));
    }

    /** @return list<string> */
    public static function allSubjects(): array
    {
        $subjects = [];

        foreach (self::HOURS as $hours) {
            foreach (array_keys($hours) as $subject) {
                $subjects[$subject] = true;
            }
        }

        return array_keys($subjects);
    }

    /** @return list<string> */
    public static function bandsForSubject(string $subject): array
    {
        $bands = [];

        foreach (self::HOURS as $band => $hours) {
            if (array_key_exists($subject, $hours)) {
                $bands[] = $band;
            }
        }

        return $bands;
    }
}
