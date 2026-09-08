<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    /** The local development administrator account. */
    public const EMAIL = 'admin@ecole.tn';

    /** The plain password given to the local administrator account. */
    public const PASSWORD = 'password';

    /**
     * Seed the users table with a single administrator.
     *
     * The account is created with updateOrCreate so re-running the seeder
     * resets the password instead of failing on the unique email.
     */
    public function run(): void
    {
        User::updateOrCreate(
            ['email' => self::EMAIL],
            [
                'name' => 'Administrateur',
                'password' => Hash::make(self::PASSWORD),
                'role' => 'admin',
                'email_verified_at' => now(),
            ],
        );

        $this->command?->info('Admin account ready:');
        $this->command?->line('  email:    '.self::EMAIL);
        $this->command?->line('  password: '.self::PASSWORD);
    }
}
