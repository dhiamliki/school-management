<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Attendance extends Model
{
    use HasFactory;

    /** The marks a pupil can be given for a lesson. */
    public const PRESENT = 'présent';

    public const ABSENT = 'absent';

    public const LATE = 'retard';

    /** @var list<string> */
    public const STATUSES = [self::PRESENT, self::ABSENT, self::LATE];

    public const RECENT_DAYS = 30;

    /**
     * Absence rate above which a pupil is reported at risk. Calibrated on the
     * seeded school: 15% flags about 2% of pupils across every band.
     */
    public const AT_RISK_RATE = 15.0;

    /** Below this a rate is noise: one absence in three lessons is 33%. */
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
