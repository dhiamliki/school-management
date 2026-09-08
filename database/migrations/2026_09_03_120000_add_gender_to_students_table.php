<?php

use App\Support\TunisianNames;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Backfills the pupils the factory generated; a real pupil stays null. */
    public function up(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->char('gender', 1)->nullable()->after('birth_date');
        });

        $this->backfillSeededPupils();
    }

    /**
     * Not name-guessing: TunisianNames is the list the factory picked from, so
     * this recovers the gender the generator already had in mind.
     */
    private function backfillSeededPupils(): void
    {
        foreach ([
            TunisianNames::MALE => TunisianNames::MALE_FIRST_NAMES,
            TunisianNames::FEMALE => TunisianNames::FEMALE_FIRST_NAMES,
        ] as $gender => $firstNames) {
            DB::table('students')
                ->whereNull('gender')
                ->where(function ($query) use ($firstNames) {
                    foreach ($firstNames as $first) {
                        // The given name is the first word; surnames such as
                        // "Ben Ali" are more than one.
                        $query->orWhere('name', 'like', $first.' %');
                    }
                })
                ->update(['gender' => $gender]);
        }
    }

    public function down(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->dropColumn('gender');
        });
    }
};
