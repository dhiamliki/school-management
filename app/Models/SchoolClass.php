<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

class SchoolClass extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'level',
        'capacity',
    ];

    public function students(): HasMany
    {
        return $this->hasMany(Student::class);
    }

    public function lessons(): HasMany
    {
        return $this->hasMany(Lesson::class);
    }

    public function attendances(): HasManyThrough
    {
        return $this->hasManyThrough(Attendance::class, Student::class);
    }

    /** One query per status. Only the show endpoint applies it. */
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

    /** Guards the resource block, so nesting a class does not count per row. */
    public function hasAttendanceSummary(): bool
    {
        return $this->getAttribute('attendance_records_count') !== null;
    }

    /** Null, not 0, when nothing is on record. */
    public function attendanceRate(): ?float
    {
        $records = (int) $this->getAttribute('attendance_records_count');

        if ($records === 0) {
            return null;
        }

        return round((int) $this->getAttribute('attendance_present_count') / $records * 100, 1);
    }
}
