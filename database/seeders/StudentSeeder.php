<?php

namespace Database\Seeders;

use App\Models\SchoolClass;
use App\Models\Student;
use Illuminate\Database\Seeder;

class StudentSeeder extends Seeder
{
    /**
     * The size band a class is filled to. The upper bound is clamped to the
     * class capacity so no class is ever seeded over its own limit.
     */
    protected int $minPerClass = 18;

    protected int $maxPerClass = 26;

    public function run(): void
    {
        $classes = SchoolClass::all();

        if ($classes->isEmpty()) {
            return;
        }

        foreach ($classes as $class) {
            $ceiling = min($this->maxPerClass, $class->capacity ?? $this->maxPerClass);
            $size = fake()->numberBetween(min($this->minPerClass, $ceiling), $ceiling);

            Student::factory()->count($size)->forClass($class)->create();
        }
    }
}
