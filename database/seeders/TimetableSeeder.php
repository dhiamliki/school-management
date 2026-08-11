<?php

namespace Database\Seeders;

use App\Models\Lesson;
use App\Models\Timetable;
use Database\Factories\TimetableFactory;
use Illuminate\Database\Seeder;

class TimetableSeeder extends Seeder
{
    /**
     * Total number of slots to place on the timetable.
     */
    protected int $total = 20;

    /**
     * Seed the timetables table.
     *
     * Slots are laid out one per day and time pair, so no two lessons ever
     * land on the same room at the same moment.
     */
    public function run(): void
    {
        $lessons = Lesson::all();

        if ($lessons->isEmpty()) {
            return;
        }

        $placed = 0;

        foreach (TimetableFactory::SLOTS as $slot) {
            [$startTime, $endTime] = $slot;

            foreach (TimetableFactory::DAYS as $day) {
                if ($placed >= $this->total) {
                    return;
                }

                Timetable::factory()
                    ->at($day, $startTime, $endTime)
                    ->create([
                        'lesson_id' => $lessons[$placed % $lessons->count()]->id,
                        'room' => 'Salle '.(101 + $placed % 8),
                    ]);

                $placed++;
            }
        }
    }
}
