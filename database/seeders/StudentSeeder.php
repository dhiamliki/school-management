<?php

namespace Database\Seeders;

use App\Models\SchoolClass;
use App\Models\Student;
use Illuminate\Database\Seeder;

class StudentSeeder extends Seeder
{
    /**
     * Total number of students to enrol.
     */
    protected int $total = 30;

    /**
     * Seed the students table, spreading students evenly across the classes.
     */
    public function run(): void
    {
        $classes = SchoolClass::all();

        if ($classes->isEmpty()) {
            return;
        }

        for ($i = 0; $i < $this->total; $i++) {
            Student::factory()
                ->forClass($classes[$i % $classes->count()])
                ->create();
        }
    }
}
