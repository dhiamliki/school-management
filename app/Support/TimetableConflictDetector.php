<?php

namespace App\Support;

use App\Models\Timetable;
use Illuminate\Support\Collection;

/**
 * Three things cannot be in two places at once: a teacher, a room and a
 * class. Each is checked separately, so one save can report several clashes.
 */
class TimetableConflictDetector
{
    public const TEACHER = 'teacher';

    public const ROOM = 'room';

    public const SCHOOL_CLASS = 'school_class';

    /** @return list<array{kind: string, message: string, timetable_id: int, day_of_week: string, start_time: string, end_time: string, room: string|null, lesson: string|null, teacher: string|null, school_class: string|null}> */
    public function conflicts(
        string $dayOfWeek,
        string $startTime,
        string $endTime,
        ?int $teacherId = null,
        ?string $room = null,
        ?int $schoolClassId = null,
        ?int $excludeId = null,
    ): array {
        $overlapping = $this->overlapping($dayOfWeek, $startTime, $endTime, $excludeId);

        if ($overlapping->isEmpty()) {
            return [];
        }

        $conflicts = [];

        foreach ($overlapping as $slot) {
            $lesson = $slot->lesson;

            if ($teacherId !== null && $lesson?->teacher_id === $teacherId) {
                $conflicts[] = $this->describe(self::TEACHER, $slot);
            }

            // A null room is "no room recorded", not a shared space.
            if (filled($room) && $this->sameRoom($slot->room, $room)) {
                $conflicts[] = $this->describe(self::ROOM, $slot);
            }

            if ($schoolClassId !== null && $lesson?->school_class_id === $schoolClassId) {
                $conflicts[] = $this->describe(self::SCHOOL_CLASS, $slot);
            }
        }

        return $conflicts;
    }

    /**
     * Half-open ranges, so 08:00-09:00 and 09:00-10:00 do not collide.
     *
     * @return Collection<int, Timetable>
     */
    private function overlapping(string $dayOfWeek, string $startTime, string $endTime, ?int $excludeId): Collection
    {
        // Both sides in the column's format; see Timetable::normaliseTime().
        $start = Timetable::normaliseTime($startTime);
        $end = Timetable::normaliseTime($endTime);

        return Timetable::with(['lesson.teacher', 'lesson.schoolClass'])
            ->where('day_of_week', $dayOfWeek)
            ->when($excludeId !== null, fn ($query) => $query->whereKeyNot($excludeId))
            ->where('start_time', '<', $end)
            ->where('end_time', '>', $start)
            ->orderBy('start_time')
            ->get();
    }

    /** Room names are free text, so compare them the way a human would. */
    private function sameRoom(?string $existing, string $proposed): bool
    {
        if (blank($existing)) {
            return false;
        }

        return mb_strtolower(trim($existing)) === mb_strtolower(trim($proposed));
    }

    /** @return array<string, mixed> */
    private function describe(string $kind, Timetable $slot): array
    {
        $lesson = $slot->lesson;
        $from = substr((string) $slot->start_time, 0, 5);
        $to = substr((string) $slot->end_time, 0, 5);
        $when = "de {$from} à {$to} le {$slot->day_of_week}";

        $teacher = $lesson?->teacher?->name;
        $schoolClass = $lesson?->schoolClass?->name;
        $title = $lesson?->title ?? 'cours supprimé';

        $message = match ($kind) {
            // Neutral phrasing: the roster records no gender for teachers.
            self::TEACHER => sprintf(
                'Conflit : %s a déjà un cours %s (%s, %s).',
                $teacher ?? 'cet enseignant',
                $when,
                $title,
                $schoolClass ?? 'sans classe',
            ),
            self::ROOM => sprintf(
                'Conflit : la %s est déjà occupée %s (%s, %s).',
                $slot->room,
                $when,
                $title,
                $schoolClass ?? 'sans classe',
            ),
            self::SCHOOL_CLASS => sprintf(
                'Conflit : la classe %s a déjà un cours %s (%s, %s).',
                $schoolClass ?? 'concernée',
                $when,
                $title,
                $teacher ?? 'sans enseignant',
            ),
        };

        return [
            'kind' => $kind,
            'message' => $message,
            'timetable_id' => $slot->id,
            'day_of_week' => $slot->day_of_week,
            'start_time' => $from,
            'end_time' => $to,
            'room' => $slot->room,
            'lesson' => $lesson?->title,
            'teacher' => $teacher,
            'school_class' => $schoolClass,
        ];
    }
}
