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
     * Lay the whole school week out on the grid.
     *
     * Each lesson runs as many hours a week as the curriculum says, so a class
     * with 30 weekly hours fills 30 of the 32 teaching slots the week offers
     * (six full days plus the Samedi morning). That is tight enough that the
     * order of placement matters: the busiest teachers are placed first,
     * because they have the least slack left once the grid fills up.
     *
     * Three things must never collide - a teacher, a class, and a room.
     */
    public function run(): void
    {
        $lessons = Lesson::with(['schoolClass', 'teacher'])->orderBy('id')->get();

        if ($lessons->isEmpty()) {
            return;
        }

        $pairs = $this->daySlotPairs();

        // Order by how little room a teacher has left in their own week.
        //
        // A teacher's hours have to fit inside the 32 slots the week offers,
        // so their total load is what decides how much freedom they have:
        // somebody owing 21 hours can be placed 11 ways, somebody owing 6 has
        // 26. Whoever has the least slack goes first, while the grid is still
        // empty enough to take them.
        //
        // Two earlier keys were tried and are deliberately gone. Ranking on
        // total load with the specialists forced to the front worked only
        // while "specialist" meant something: in the upper band every post is
        // now a subject post, so the flag was true for all of them and the
        // tiebreak fell through to the number of classes a teacher serves.
        // That reads backwards under specialisation - the Français teacher
        // covers the fewest classes (two) precisely because she owes each of
        // them eight hours - so she sorted last and five of those hours had
        // nowhere left to go. Load puts her third, behind Arabe and ahead of
        // everyone owing twelve hours or fewer.
        //
        // Class count survives as the tiebreak, where it still means what it
        // used to: between two teachers owing the same hours, the one whose
        // hours are scattered over more timetables is the harder to place.
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

        // Bookings are held in memory until the end so the repair pass below
        // can move one without touching the database.
        $busyTeachers = [];
        $busyClasses = [];
        $busyRooms = [];
        $rows = [];
        $pending = [];

        // How many bookings the whole school already holds in each slot of the
        // week, so placement can keep the grid level. See bestPair().
        $slotUse = [];

        foreach ($ordered as $lesson) {
            $hours = $this->hoursFor($lesson);
            $room = $this->roomFor($lesson);
            $perDay = [];

            for ($hour = 0; $hour < $hours; $hour++) {
                $chosen = $this->bestPair($lesson, $room, $pairs, $perDay, $slotUse, $busyTeachers, $busyClasses, $busyRooms);

                if ($chosen === null) {
                    // Nothing free right now; the repair pass gets another go
                    // once the whole week has been laid out.
                    $pending[] = [$lesson, $room];

                    continue;
                }

                [$day, $start, $end] = $chosen;
                $perDay[$day] = ($perDay[$day] ?? 0) + 1;
                $slotUse["{$day}|{$start}"] = ($slotUse["{$day}|{$start}"] ?? 0) + 1;
                $this->book($rows, $busyTeachers, $busyClasses, $busyRooms, $lesson, $room, $day, $start, $end);
            }
        }

        // Greedy placement leaves the last classes competing for whatever is
        // left, which can strand a few hours. Rather than dropping them, try
        // to move one existing booking out of the way - the teacher blocking
        // the slot usually has somewhere else they could be.
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
     * Record one booking in the row buffer and the three busy indexes.
     *
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
     * Place a stranded hour by relocating whatever blocks it.
     *
     * For each slot where the class is free, the obstacle is the teacher being
     * busy elsewhere. If that other booking can move somewhere its own class,
     * teacher and room are all free, moving it opens this slot. One level of
     * displacement is enough in practice.
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

                // Move the blocker, then take the slot it vacated.
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
     * The free slot that spreads this subject best.
     *
     * Preference order:
     *  1. A day the class does not already have this subject on. Without this
     *     a class would take all eleven hours of Arabe on Monday and Tuesday.
     *  2. The hour of the week the school is using least. Ranking on the slot
     *     index alone filled the timetable front to back - every class took
     *     08:00 first and 15:00 last - so the free hours every class had left
     *     over were the same two or three late-afternoon slots. The teachers
     *     placed last need one hour in each of six classes, and when all six
     *     classes are only free at the same moment those hours cannot all be
     *     placed. Levelling the grid scatters the leftovers across different
     *     times instead, which is what makes the last hours fit.
     *  3. The earliest such hour, so a level grid still reads front to back.
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

            // The weights keep the three rules strictly ordered: no number of
            // free slots can outrank spreading across days, and the index only
            // separates slots the school is using equally often.
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

    /**
     * Weekly hours this lesson runs, from the curriculum.
     */
    private function hoursFor(Lesson $lesson): int
    {
        $grade = (int) ($lesson->schoolClass->level ?? 1) ?: 1;

        return Curriculum::hoursForGrade($grade)[$lesson->subject] ?? 1;
    }

    /**
     * Every day paired with the slots it actually runs, so a half day
     * contributes only its morning.
     *
     * @return list<array{string, string, string}>
     */
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

    /**
     * The class's own room, except for sport.
     *
     * Each band has its own outdoor space: the three bands each have their own
     * Éducation Physique teacher, and without separate venues two bands could
     * be sent to the same pitch at the same hour.
     */
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
