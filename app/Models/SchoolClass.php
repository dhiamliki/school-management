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

    /**
     * Every attendance mark belonging to this class's pupils.
     */
    public function attendances(): HasManyThrough
    {
        return $this->hasManyThrough(Attendance::class, Student::class);
    }

    /**
     * The class-wide attendance tallies, in one query per status.
     *
     * Only the show endpoint applies this: the index would otherwise pay for
     * four correlated subqueries per row to fill a column nothing displays.
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
     * keep the block out of index responses.
     */
    public function hasAttendanceSummary(): bool
    {
        return $this->getAttribute('attendance_records_count') !== null;
    }

    /**
     * The share of this class's marks where a pupil was present, to one
     * decimal. Null when the class has nothing on record - see
     * Student::attendanceRate() for why that is not reported as 0%.
     */
    public function attendanceRate(): ?float
    {
        $records = (int) $this->getAttribute('attendance_records_count');

        if ($records === 0) {
            return null;
        }

        return round((int) $this->getAttribute('attendance_present_count') / $records * 100, 1);
    }
}
