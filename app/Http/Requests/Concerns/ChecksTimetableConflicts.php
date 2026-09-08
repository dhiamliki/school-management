<?php

namespace App\Http\Requests\Concerns;

use App\Models\Lesson;
use App\Support\TimetableConflictDetector;
use Illuminate\Contracts\Validation\Validator;

/** Rejects a timetable write that would double-book a teacher, room or class. */
trait ChecksTimetableConflicts
{
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            // Nothing to compare against if the field rules already failed.
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $lesson = Lesson::find($this->input('lesson_id'));

            $conflicts = app(TimetableConflictDetector::class)->conflicts(
                dayOfWeek: (string) $this->input('day_of_week'),
                startTime: (string) $this->input('start_time'),
                endTime: (string) $this->input('end_time'),
                teacherId: $lesson?->teacher_id,
                room: $this->input('room'),
                schoolClassId: $lesson?->school_class_id,
                excludeId: $this->conflictExclusionId(),
            );

            foreach ($conflicts as $conflict) {
                // Attached to the field the user would change to fix it.
                $validator->errors()->add(
                    match ($conflict['kind']) {
                        TimetableConflictDetector::ROOM => 'room',
                        TimetableConflictDetector::SCHOOL_CLASS => 'lesson_id',
                        default => 'lesson_id',
                    },
                    $conflict['message'],
                );
            }
        });
    }

    protected function conflictExclusionId(): ?int
    {
        $timetable = $this->route('timetable');

        if ($timetable === null) {
            return null;
        }

        return (int) (is_object($timetable) ? $timetable->getKey() : $timetable);
    }
}
