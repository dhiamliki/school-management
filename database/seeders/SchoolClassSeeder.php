<?php

namespace Database\Seeders;

use App\Models\SchoolClass;
use Database\Factories\SchoolClassFactory;
use Illuminate\Database\Seeder;

class SchoolClassSeeder extends Seeder
{
    /**
     * Seed the school_classes table.
     */
    public function run(): void
    {
        foreach (SchoolClassFactory::CLASSES as $name => $level) {
            SchoolClass::factory()->create([
                'name' => $name,
                'level' => $level,
            ]);
        }
    }
}
