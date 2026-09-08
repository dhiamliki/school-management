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

    /** Null is a legitimate third state, so these are not exhaustive. */
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
     * Pupils have no email address; a matricule is what a register identifies
     * them by. The row id is unique, so padding it needs no collision check.
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

    /** One query per status rather than one per pupil. */
    public function scopeWithAttendanceSummary(Builder $query): Builder
    {
        return $query->withCount(self::attendanceSummaryCounts());
    }

    public function loadAttendanceSummary(): static
    {
        return $this->loadCount(self::attendanceSummaryCounts());
    }

    /** @return array<string, mixed> */
    private static function attendanceSummaryCounts(): array
    {
        return [
            'attendances as attendance_records_count' => fn (Builder $q) => $q,
            'attendances as attendance_present_count' => fn (Builder $q) => $q->where('status', Attendance::PRESENT),
            'attendances as attendance_absent_count' => fn (Builder $q) => $q->where('status', Attendance::ABSENT),
            'attendances as attendance_late_count' => fn (Builder $q) => $q->where('status', Attendance::LATE),
        ];
    }

    /** Guards the resource block, so nesting a pupil does not count per row. */
    public function hasAttendanceSummary(): bool
    {
        return $this->getAttribute('attendance_records_count') !== null;
    }

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
     * Null, not 0, when nothing is on record: no history is not a perfect record
     * of absence. A retard counts against the rate.
     */
    public function attendanceRate(): ?float
    {
        $records = $this->attendanceRecordCount();

        if ($records === 0) {
            return null;
        }

        return round($this->presentCount() / $records * 100, 1);
    }

    /** Falls back to counting on demand when the summary was not preloaded. */
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
