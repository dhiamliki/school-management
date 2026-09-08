<?php

namespace App\Support;

/**
 * The Tunisian primary curriculum, as weekly hours per subject per grade band.
 *
 * One timetable slot is one hour, so the figures below are also the number of
 * slots a class gets for that subject each week. Where the official programme
 * quotes a half hour, it is rounded to a whole slot and the band total is kept
 * on target - see the notes on each band.
 *
 * This is the single source of truth: the seeders build from it and the tests
 * assert against it, so the two cannot drift apart.
 */
class Curriculum
{
    public const LOWER = 'lower';

    public const MID = 'mid';

    public const UPPER = 'upper';

    /**
     * Which grades each band covers.
     *
     * @var array<string, list<int>>
     */
    public const BANDS = [
        self::LOWER => [1, 2],
        self::MID => [3, 4],
        self::UPPER => [5, 6],
    ];

    /**
     * Weekly hours per subject, per band.
     *
     * Rounding notes:
     *  - Lower: Arabe takes the bottom of its 11-11.5h range; the combined
     *    Éveil/Civique line takes the top of its 1-1.5h range rounded up to 2.
     *    Band lands on 21h.
     *  - Mid: Français takes the bottom of its 7-8h range so the band lands on
     *    28h rather than 29. Histoire-Géographie runs in both 3ème and 4ème.
     *  - Upper: Arabe bottom of range (7), Français top (8), Anglais 2 and
     *    Histoire-Géographie 2 (both 1.5h rounded up). Band lands on 30h.
     *
     * @var array<string, array<string, int>>
     */
    public const HOURS = [
        // 21h. Éveil scientifique and éducation civique are taught as one
        // combined awakening subject at this age, which is how the lower
        // primary programme actually presents them.
        self::LOWER => [
            'Langue Arabe' => 11,
            'Mathématiques' => 5,
            'Éveil Scientifique et Civique' => 2,
            'Éducation Islamique' => 1,
            'Éducation Artistique' => 1,
            'Éducation Physique' => 1,
        ],

        // 28h. Français is introduced here, never earlier.
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

        // 30h. Anglais is introduced here, never earlier.
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

    /**
     * The one upper-band post covering two subjects. Named so the seeder and
     * the staff list agree on how it is written.
     */
    public const CIVICS_POST = 'Éducation Islamique et Civique';

    /**
     * The bands staffed by a titulaire and an adjoint rather than by subject
     * specialists.
     *
     * Six and nine year olds are taught by a main teacher who knows them, and
     * that is how the lower and mid bands are modelled. The upper band is not:
     * see ROLES below.
     *
     * @var list<string>
     */
    public const TITULAIRE_BANDS = [self::LOWER, self::MID];

    /**
     * Who teaches what, by band.
     *
     * Two different staffing models, because a Tunisian primary school uses
     * two.
     *
     * In the lower and mid bands a class is owned by a titulaire who teaches
     * the bulk of its core programme, with a second core teacher - the
     * adjoint, who is titulaire of a neighbouring class in the same band -
     * taking the rest, and the remaining subjects going to the band's
     * specialists. The split is chosen so no single teacher holds more than
     * about 62% of any class's week.
     *
     * The upper band is staffed by subject, in preparation for collège. Every
     * post below names a subject or a pair of closely related ones, and a
     * teacher holding that post teaches only that, across several classes.
     * The titulaire model produced people like a 6ème teacher covering Arabic,
     * maths, science and Islamic studies at once: within the hour and
     * subject-count caps, but not a job anybody actually holds. Coherence is
     * now structural - a post cannot span unrelated subjects, because the post
     * is the subject.
     *
     * Only two subjects share a post: Éducation Islamique and Éducation
     * Civique, one hour each. They are the moral-and-citizenship pair, they
     * are taught together in practice, and neither is a full post on its own.
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

        // No titulaire. Nine posts, each its own subject, the largest holding
        // 8h of a class's 30 (27%).
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

    /**
     * The largest share of one class's week any single teacher may hold.
     */
    public const MAX_CLASS_SHARE = 0.65;

    /**
     * The most hours a teacher may be timetabled for in a week.
     */
    public const MAX_WEEKLY_LOAD = 24;

    /**
     * The role covering a subject for a grade.
     *
     * In a titulaire band this is 'titulaire', 'adjoint' or a specialist post;
     * in the upper band it is always the subject post itself.
     */
    public static function roleFor(string $subject, int $grade): string
    {
        $band = self::bandForGrade($grade);

        return self::ROLES[$band][$subject]
            ?? (self::usesTitulaireModel($band) ? 'titulaire' : $subject);
    }

    /**
     * Whether a band is staffed by a titulaire and an adjoint.
     */
    public static function usesTitulaireModel(string $band): bool
    {
        return in_array($band, self::TITULAIRE_BANDS, true);
    }

    /**
     * Whether a grade is taught by subject specialists rather than by a main
     * teacher.
     */
    public static function isSpecialisedGrade(int $grade): bool
    {
        return ! self::usesTitulaireModel(self::bandForGrade($grade));
    }

    /**
     * The distinct posts a band is staffed with, and the weekly hours each one
     * owes a single class.
     *
     * Used to size the staff pool and to check, without going near the
     * database, that no post spans unrelated subjects.
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

    /**
     * The subjects one post covers in a band.
     *
     * @return list<string>
     */
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

    /**
     * Hours a role holds for a grade, used to check the split adds up.
     */
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

    /**
     * The band a grade belongs to.
     */
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

    /**
     * Total weekly hours for a grade.
     */
    public static function weeklyHours(int $grade): int
    {
        return array_sum(self::hoursForGrade($grade));
    }

    /**
     * Whether a subject belongs to a grade's programme.
     */
    public static function subjectFitsGrade(string $subject, int $grade): bool
    {
        return array_key_exists($subject, self::hoursForGrade($grade));
    }

    /**
     * Every subject taught anywhere in the school.
     *
     * @return list<string>
     */
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

    /**
     * The bands a subject is taught in.
     *
     * @return list<string>
     */
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
