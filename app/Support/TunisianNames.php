<?php

namespace App\Support;

/**
 * Given names the student factory draws from, split by gender so the factory
 * can record which one it picked rather than guessing from the name later.
 */
class TunisianNames
{
    public const MALE = 'M';

    public const FEMALE = 'F';

    /** @var list<string> */
    public const MALE_FIRST_NAMES = [
        'Mohamed', 'Ahmed', 'Youssef', 'Amine', 'Skander',
        'Aziz', 'Firas', 'Khalil', 'Sami', 'Malek',
        'Aymen', 'Wael', 'Hamza', 'Bilel', 'Oussama',
        'Zied', 'Nizar', 'Seif', 'Anis', 'Marwen',
        'Chedi', 'Tarek',
    ];

    /** @var list<string> */
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

    /** Only the first word is considered; surnames such as "Ben Ali" have more. */
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
