<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Student extends Model
{
    use HasFactory;

    /**
     * The recorded genders. Null is a third, legitimate state - see the
     * add_gender_to_students_table migration - so these are not exhaustive.
     */
    public const MALE = 'M';

    public const FEMALE = 'F';

    protected $fillable = [
        'name',
        'matricule',
        'birth_date',
        'gender',
        'school_class_id',
    ];

    protected $casts = [
        'birth_date' => 'date',
    ];

    /**
     * The school number a pupil is enrolled under.
     *
     * Pupils are six to twelve and have no email address, so this is what a
     * register, a report card or a transfer form identifies them by. The row
     * id is already unique, so padding it is enough and nothing has to be
     * checked for collisions.
     */
    public static function matriculeFor(int $id): string
    {
        return 'E-'.str_pad((string) $id, 5, '0', STR_PAD_LEFT);
    }

    public function schoolClass(): BelongsTo
    {
        return $this->belongsTo(SchoolClass::class);
    }

    public function attendances(): HasMany
    {
        return $this->hasMany(Attendance::class);
    }

    /**
     * Load the attendance tallies the accessors below read, in one query per
     * status rather than one per pupil. Every list of pupils that reports on
     * attendance should start from this scope.
     */
    public function scopeWithAttendanceSummary(Builder $query): Builder
    {
        return $query->withCount(self::attendanceSummaryCounts());
    }

    /**
     * The same tallies for a model already in hand.
     */
    public function loadAttendanceSummary(): static
    {
        return $this->loadCount(self::attendanceSummaryCounts());
    }

    /**
     * The withCount definitions behind both of the above.
     *
     * @return array<string, mixed>
     */
    private static function attendanceSummaryCounts(): array
    {
        return [
            'attendances as attendance_records_count' => fn (Builder $q) => $q,
            'attendances as attendance_present_count' => fn (Builder $q) => $q->where('status', Attendance::PRESENT),
            'attendances as attendance_absent_count' => fn (Builder $q) => $q->where('status', Attendance::ABSENT),
            'attendances as attendance_late_count' => fn (Builder $q) => $q->where('status', Attendance::LATE),
        ];
    }

    /**
     * Whether the tallies above have been loaded. The resource uses this to
     * keep the block out of responses that did not ask for it, which is what
     * stops a pupil nested inside another resource from counting on demand.
     */
    public function hasAttendanceSummary(): bool
    {
        return $this->getAttribute('attendance_records_count') !== null;
    }

    /**
     * Total marks on record for this pupil.
     */
    public function attendanceRecordCount(): int
    {
        return $this->tally('attendance_records_count');
    }

    public function presentCount(): int
    {
        return $this->tally('attendance_present_count', Attendance::PRESENT);
    }

    public function absenceCount(): int
    {
        return $this->tally('attendance_absent_count', Attendance::ABSENT);
    }

    public function lateCount(): int
    {
        return $this->tally('attendance_late_count', Attendance::LATE);
    }

    /**
     * The share of recorded marks where the pupil was present, as a percentage
     * rounded to one decimal.
     *
     * A pupil with nothing on record gets null rather than 0: no history is
     * not the same as a perfect record of absence, and reporting 0% would put
     * every new arrival at the top of the at-risk list.
     *
     * A retard counts against the rate. It is a real mark, and the alternative
     * - treating it as present - would hide lateness entirely.
     */
    public function attendanceRate(): ?float
    {
        $records = $this->attendanceRecordCount();

        if ($records === 0) {
            return null;
        }

        return round($this->presentCount() / $records * 100, 1);
    }

    /**
     * Read a tally from withAttendanceSummary() if it was loaded, and fall
     * back to counting on demand for a model fetched without it.
     */
    private function tally(string $attribute, ?string $status = null): int
    {
        $loaded = $this->getAttribute($attribute);

        if ($loaded !== null) {
            return (int) $loaded;
        }

        return $this->attendances()
            ->when($status !== null, fn (Builder $q) => $q->where('status', $status))
            ->count();
    }
}
