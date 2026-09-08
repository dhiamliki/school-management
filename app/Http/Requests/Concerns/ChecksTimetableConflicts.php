<?php

namespace App\Http\Requests\Concerns;

use App\Models\Lesson;
use App\Support\TimetableConflictDetector;
use Illuminate\Contracts\Validation\Validator;

/**
 * Rejects a timetable write that would put a teacher, a room or a class in
 * two places at once.
 *
 * Shared by the store and update requests so both enforce the same rule; the
 * only difference is that an update excludes the row being edited, which
 * otherwise always clashes with itself.
 */
trait ChecksTimetableConflicts
{
    /**
     * Run the conflict check once the field-level rules have passed.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            // Nothing to compare against if the basics are already wrong: the
            // day, the times or the lesson could all be missing or malformed.
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
                // Attached to the field the user would change to resolve it,
                // so the form shows each message next to the right input.
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

    /**
     * The row this write must not be compared against. Null when creating.
     */
    protected function conflictExclusionId(): ?int
    {
        $timetable = $this->route('timetable');

        if ($timetable === null) {
            return null;
        }

        return (int) (is_object($timetable) ? $timetable->getKey() : $timetable);
    }
}
