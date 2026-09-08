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
     * Days that finish at lunchtime. Samedi is a half day: the afternoon
     * slots below are never taught on it.
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

    /**
     * How many of the slots above a half day actually runs.
     */
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
     * Store times as H:i:s, whatever format they arrive in.
     *
     * MySQL normalises a TIME column on its own, but SQLite keeps the exact
     * string it was given. Without this the same row is '08:00' on one driver
     * and '08:00:00' on the other, and the overlap check compares times as
     * text: '09:00' sorts before '09:00:00' because it is a prefix, so a
     * 08:00-09:00 slot would be reported as clashing with 09:00-10:00. Pinning
     * the format on write keeps that comparison honest everywhere.
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
     * Pad 'H:i' (or 'H') out to the 'H:i:s' the column holds.
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
