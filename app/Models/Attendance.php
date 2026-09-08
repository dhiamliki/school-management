<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Attendance extends Model
{
    use HasFactory;

    /**
     * The marks a pupil can be given for a lesson.
     */
    public const PRESENT = 'présent';

    public const ABSENT = 'absent';

    public const LATE = 'retard';

    /**
     * @var list<string>
     */
    public const STATUSES = [self::PRESENT, self::ABSENT, self::LATE];

    /**
     * How far back the at-risk query and the absence counters look.
     */
    public const RECENT_DAYS = 30;

    /**
     * The absence rate, as a percentage of marks in the window, above which a
     * pupil is reported as at risk.
     *
     * Calibrated against the real distribution rather than picked round: the
     * seeded school separates cleanly into a cohort at 15.9-29.7% and an
     * ordinary tail at 12.4% and below, with nothing in between. Every cutoff
     * from 12.5 to 15.5 selects exactly that cohort, so 15 sits in the middle
     * of the plateau and stays put as the data shifts either way.
     */
    public const AT_RISK_RATE = 15.0;

    /**
     * How many marks a pupil needs in the window before a rate is worth
     * quoting. Three lessons and one absence is 33%, and means nothing.
     */
    public const AT_RISK_MIN_RECORDS = 10;

    protected $fillable = [
        'student_id',
        'lesson_id',
        'date',
        'status',
        'note',
    ];

    protected $casts = [
        'date' => 'date',
    ];

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class);
    }
}
