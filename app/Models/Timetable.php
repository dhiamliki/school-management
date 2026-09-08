<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Timetable extends Model
{
    use HasFactory;

    /**
     * The teaching days of the week. Tunisian schools teach Saturday morning,
     * so the week runs Lundi to Samedi.
     *
     * @var list<string>
     */
    public const DAYS = ['Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi', 'Samedi'];

    /**
     * Samedi finishes at lunchtime.
     *
     * @var list<string>
     */
    public const HALF_DAYS = ['Samedi'];

    /**
     * The school hours of a full day, as start and end pairs.
     *
     * @var list<array{string, string}>
     */
    public const SLOTS = [
        ['08:00', '09:00'],
        ['09:00', '10:00'],
        ['10:15', '11:15'],
        ['11:15', '12:15'],
        ['14:00', '15:00'],
        ['15:00', '16:00'],
    ];

    public const HALF_DAY_SLOT_COUNT = 2;

    /**
     * The slots taught on a given day: the whole day Lundi-Vendredi, only the
     * morning on a half day.
     *
     * @return list<array{string, string}>
     */
    public static function slotsFor(string $day): array
    {
        return in_array($day, self::HALF_DAYS, true)
            ? array_slice(self::SLOTS, 0, self::HALF_DAY_SLOT_COUNT)
            : self::SLOTS;
    }

    protected $fillable = [
        'lesson_id',
        'day_of_week',
        'start_time',
        'end_time',
        'room',
    ];

    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class);
    }

    /**
     * MySQL normalises a TIME column on write; SQLite keeps whatever string it
     * was given. Normalising here keeps the two comparable, which the conflict
     * detector relies on.
     */
    protected function startTime(): Attribute
    {
        return Attribute::set(fn ($value) => self::normaliseTime($value));
    }

    protected function endTime(): Attribute
    {
        return Attribute::set(fn ($value) => self::normaliseTime($value));
    }

    /**
     * MySQL normalises TIME on write, SQLite keeps the literal string, so both
     * sides of a text comparison have to be padded to H:i:s here.
     */
    public static function normaliseTime(mixed $time): ?string
    {
        if (blank($time)) {
            return null;
        }

        $parts = explode(':', trim((string) $time));
        $parts = array_pad(array_slice($parts, 0, 3), 3, '00');

        return implode(':', array_map(fn ($part) => str_pad($part, 2, '0', STR_PAD_LEFT), $parts));
    }
}
