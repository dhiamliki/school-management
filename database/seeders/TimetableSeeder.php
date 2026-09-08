<?php

namespace Database\Seeders;

use App\Models\Lesson;
use App\Models\Timetable;
use App\Support\Curriculum;
use Illuminate\Database\Seeder;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class TimetableSeeder extends Seeder
{
    /**
     * Lay the school week on the grid. An upper-band class fills 30 of the 32
     * slots the week offers, so placement order decides whether it fits.
     */
    public function run(): void
    {
        $lessons = Lesson::with(['schoolClass', 'teacher'])->orderBy('id')->get();

        if ($lessons->isEmpty()) {
            return;
        }

        $pairs = $this->daySlotPairs();

        // Busiest teacher first: load is what decides how much slack is left.
        // Not class count, which reads backwards under subject specialisation,
        // where the Français teacher covers the fewest classes precisely
        // because she owes each of them eight hours.
        $load = [];
        $classes = [];

        foreach ($lessons as $lesson) {
            $load[$lesson->teacher_id] = ($load[$lesson->teacher_id] ?? 0) + $this->hoursFor($lesson);
            $classes[$lesson->teacher_id][$lesson->school_class_id] = true;
        }

        $ordered = $lessons->sortByDesc(fn (Lesson $l) => [
            $load[$l->teacher_id] ?? 0,
            count($classes[$l->teacher_id] ?? []),
            $this->hoursFor($l),
        ])->values();

        // Held in memory so the repair pass can move a booking cheaply.
        $busyTeachers = [];
        $busyClasses = [];
        $busyRooms = [];
        $rows = [];
        $pending = [];

        // School-wide slot occupancy, so placement can keep the grid level.
        $slotUse = [];

        foreach ($ordered as $lesson) {
            $hours = $this->hoursFor($lesson);
            $room = $this->roomFor($lesson);
            $perDay = [];

            for ($hour = 0; $hour < $hours; $hour++) {
                $chosen = $this->bestPair($lesson, $room, $pairs, $perDay, $slotUse, $busyTeachers, $busyClasses, $busyRooms);

                if ($chosen === null) {
                    // The repair pass gets another go once the week is laid out.
                    $pending[] = [$lesson, $room];

                    continue;
                }

                [$day, $start, $end] = $chosen;
                $perDay[$day] = ($perDay[$day] ?? 0) + 1;
                $slotUse["{$day}|{$start}"] = ($slotUse["{$day}|{$start}"] ?? 0) + 1;
                $this->book($rows, $busyTeachers, $busyClasses, $busyRooms, $lesson, $room, $day, $start, $end);
            }
        }

        // Move a blocking booking rather than dropping a stranded hour.
        $unplaced = 0;

        foreach ($pending as [$lesson, $room]) {
            if (! $this->repair($rows, $busyTeachers, $busyClasses, $busyRooms, $pairs, $lesson, $room)) {
                $unplaced++;
            }
        }

        $clean = array_map(
            fn (array $row) => Arr::except($row, ['_teacher', '_class']),
            $rows,
        );

        foreach (array_chunk($clean, 500) as $chunk) {
            DB::table('timetables')->insert($chunk);
        }

        if ($unplaced > 0) {
            $this->command?->warn("{$unplaced} lesson hour(s) could not be placed.");
        }
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, int>  $busyTeachers
     * @param  array<string, int>  $busyClasses
     * @param  array<string, int>  $busyRooms
     */
    private function book(
        array &$rows,
        array &$busyTeachers,
        array &$busyClasses,
        array &$busyRooms,
        Lesson $lesson,
        string $room,
        string $day,
        string $start,
        string $end,
    ): void {
        $index = count($rows);
        $when = "{$day}|{$start}";

        $rows[$index] = [
            'lesson_id' => $lesson->id,
            'day_of_week' => $day,
            'start_time' => $start.':00',
            'end_time' => $end.':00',
            'room' => $room,
            'created_at' => now(),
            'updated_at' => now(),
            '_teacher' => $lesson->teacher_id,
            '_class' => $lesson->school_class_id,
        ];

        $busyTeachers[$lesson->teacher_id.'@'.$when] = $index;
        $busyClasses[$lesson->school_class_id.'@'.$when] = $index;
        $busyRooms[$room.'@'.$when] = $index;
    }

    /**
     * Free a slot by relocating whatever blocks it. One level of displacement.
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, int>  $busyTeachers
     * @param  array<string, int>  $busyClasses
     * @param  array<string, int>  $busyRooms
     * @param  list<array{string, string, string}>  $pairs
     */
    private function repair(
        array &$rows,
        array &$busyTeachers,
        array &$busyClasses,
        array &$busyRooms,
        array $pairs,
        Lesson $lesson,
        string $room,
    ): bool {
        foreach ($pairs as [$day, $start, $end]) {
            $when = "{$day}|{$start}";

            if (isset($busyClasses[$lesson->school_class_id.'@'.$when])
                || isset($busyRooms[$room.'@'.$when])) {
                continue;
            }

            $blockerIndex = $busyTeachers[$lesson->teacher_id.'@'.$when] ?? null;

            if ($blockerIndex === null) {
                $this->book($rows, $busyTeachers, $busyClasses, $busyRooms, $lesson, $room, $day, $start, $end);

                return true;
            }

            $blocker = $rows[$blockerIndex];

            foreach ($pairs as [$day2, $start2, $end2]) {
                $when2 = "{$day2}|{$start2}";

                if ($when2 === $when
                    || isset($busyTeachers[$blocker['_teacher'].'@'.$when2])
                    || isset($busyClasses[$blocker['_class'].'@'.$when2])
                    || isset($busyRooms[$blocker['room'].'@'.$when2])) {
                    continue;
                }

                unset(
                    $busyTeachers[$blocker['_teacher'].'@'.$when],
                    $busyClasses[$blocker['_class'].'@'.$when],
                    $busyRooms[$blocker['room'].'@'.$when],
                );

                $rows[$blockerIndex]['day_of_week'] = $day2;
                $rows[$blockerIndex]['start_time'] = $start2.':00';
                $rows[$blockerIndex]['end_time'] = $end2.':00';

                $busyTeachers[$blocker['_teacher'].'@'.$when2] = $blockerIndex;
                $busyClasses[$blocker['_class'].'@'.$when2] = $blockerIndex;
                $busyRooms[$blocker['room'].'@'.$when2] = $blockerIndex;

                $this->book($rows, $busyTeachers, $busyClasses, $busyRooms, $lesson, $room, $day, $start, $end);

                return true;
            }
        }

        return false;
    }

    /**
     * Preference order: a day this lesson is not already on, then the hour the
     * school is using least, then the earliest. Ranking on the slot index alone
     * filled classes front to back and left every one of them free at the same
     * late-afternoon times, which the teachers placed last cannot all use.
     *
     * @param  list<array{string, string, string}>  $pairs
     * @param  array<string, int>  $perDay
     * @param  array<string, int>  $slotUse
     * @param  array<string, int>  $busyTeachers
     * @param  array<string, int>  $busyClasses
     * @param  array<string, int>  $busyRooms
     * @return array{string, string, string}|null
     */
    private function bestPair(
        Lesson $lesson,
        string $room,
        array $pairs,
        array $perDay,
        array $slotUse,
        array $busyTeachers,
        array $busyClasses,
        array $busyRooms,
    ): ?array {
        $best = null;
        $bestScore = null;

        foreach ($pairs as $index => [$day, $start, $end]) {
            $when = "{$day}|{$start}";

            if (isset($busyTeachers[$lesson->teacher_id.'@'.$when])
                || isset($busyClasses[$lesson->school_class_id.'@'.$when])
                || isset($busyRooms[$room.'@'.$when])) {
                continue;
            }

            // Weights keep the three rules strictly ordered.
            $score = ($perDay[$day] ?? 0) * 1000
                + ($slotUse[$when] ?? 0) * 10
                + $index;

            if ($bestScore === null || $score < $bestScore) {
                $bestScore = $score;
                $best = [$day, $start, $end];
            }
        }

        return $best;
    }

    private function hoursFor(Lesson $lesson): int
    {
        $grade = (int) ($lesson->schoolClass->level ?? 1) ?: 1;

        return Curriculum::hoursForGrade($grade)[$lesson->subject] ?? 1;
    }

    /** @return list<array{string, string, string}> */
    private function daySlotPairs(): array
    {
        $pairs = [];

        foreach (Timetable::DAYS as $day) {
            foreach (Timetable::slotsFor($day) as [$startTime, $endTime]) {
                $pairs[] = [$day, $startTime, $endTime];
            }
        }

        return $pairs;
    }

    /** Each band has its own outdoor space, or two bands share a pitch. */
    private function roomFor(Lesson $lesson): string
    {
        if ($lesson->subject === 'Éducation Physique') {
            return match (Curriculum::bandForGrade((int) ($lesson->schoolClass->level ?? 1))) {
                Curriculum::LOWER => 'Cour de récréation',
                Curriculum::MID => 'Terrain de sport',
                default => 'Salle polyvalente',
            };
        }

        return 'Salle '.(100 + (int) $lesson->school_class_id);
    }
}
