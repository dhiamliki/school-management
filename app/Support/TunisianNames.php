<?php

namespace App\Support;

/**
 * The Tunisian given names the student factory draws from, split by the
 * gender each was chosen to represent.
 *
 * This is an authoring list, not a name-to-gender oracle. It exists so the
 * factory can record the gender it *meant* when it picked a name, rather than
 * leaving the two to disagree - a pupil called Salma showing up as a boy reads
 * as a bug even in demo data.
 *
 * Guessing the gender of a name the school actually typed in is a different
 * thing entirely, and this class deliberately does not do it: genderFor()
 * answers null for anything outside the list. Compare the note in
 * TimetableConflictDetector::describe(), which is why the teacher roster
 * records no gender at all.
 */
class TunisianNames
{
    public const MALE = 'M';

    public const FEMALE = 'F';

    /**
     * @var list<string>
     */
    public const MALE_FIRST_NAMES = [
        'Mohamed', 'Ahmed', 'Youssef', 'Amine', 'Skander',
        'Aziz', 'Firas', 'Khalil', 'Sami', 'Malek',
        'Aymen', 'Wael', 'Hamza', 'Bilel', 'Oussama',
        'Zied', 'Nizar', 'Seif', 'Anis', 'Marwen',
        'Chedi', 'Tarek',
    ];

    /**
     * @var list<string>
     */
    public const FEMALE_FIRST_NAMES = [
        'Rania', 'Ines', 'Salma', 'Nour', 'Yasmine',
        'Mariem', 'Farah', 'Emna', 'Asma', 'Sirine',
        'Amira', 'Dorra', 'Hiba', 'Maha', 'Ranim',
        'Sarra', 'Wafa', 'Zeineb',
    ];

    /**
     * Every first name the factory may hand out.
     *
     * @return list<string>
     */
    public static function firstNames(): array
    {
        return [...self::MALE_FIRST_NAMES, ...self::FEMALE_FIRST_NAMES];
    }

    /**
     * The gender this list assigned to a full name, or null if the given name
     * is not one of ours. Only the first word is considered; surnames such as
     * "Ben Ali" carry more than one.
     */
    public static function genderFor(string $fullName): ?string
    {
        $first = explode(' ', trim($fullName))[0] ?? '';

        if (in_array($first, self::MALE_FIRST_NAMES, true)) {
            return self::MALE;
        }

        if (in_array($first, self::FEMALE_FIRST_NAMES, true)) {
            return self::FEMALE;
        }

        return null;
    }
}
