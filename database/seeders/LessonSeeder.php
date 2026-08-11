<?php

namespace Database\Seeders;

use App\Models\Lesson;
use App\Models\SchoolClass;
use App\Models\Teacher;
use Illuminate\Database\Seeder;

class LessonSeeder extends Seeder
{
    /**
     * Total number of lessons to schedule.
     */
    protected int $total = 12;

    /**
     * Seed the lessons table, cycling through the teachers and the classes.
     */
    public function run(): void
    {
        $teachers = Teacher::all();
        $classes = SchoolClass::all();

        if ($teachers->isEmpty() || $classes->isEmpty()) {
            return;
        }

        for ($i = 0; $i < $this->total; $i++) {
            Lesson::factory()
                ->taughtBy($teachers[$i % $teachers->count()])
                ->forClass($classes[$i % $classes->count()])
                ->create();
        }
    }
}
