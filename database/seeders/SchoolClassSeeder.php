<?php

namespace Database\Seeders;

use App\Models\SchoolClass;
use Database\Factories\SchoolClassFactory;
use Illuminate\Database\Seeder;

class SchoolClassSeeder extends Seeder
{
    /**
     * Seed the school_classes table with the six primary grades, each split
     * into its lettered sections.
     */
    public function run(): void
    {
        foreach (SchoolClassFactory::roster() as $class) {
            SchoolClass::factory()->create([
                'name' => $class['name'],
                'level' => (string) $class['grade'],
            ]);
        }
    }
}
