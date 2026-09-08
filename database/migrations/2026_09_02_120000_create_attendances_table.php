<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('attendances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();

            // Null marks an absence recorded for the whole day rather than
            // against one lesson, so losing a lesson must not lose the record.
            $table->foreignId('lesson_id')->nullable()->constrained()->nullOnDelete();

            $table->date('date');
            $table->string('status', 20);
            $table->text('note')->nullable();
            $table->timestamps();

            // One mark per pupil per lesson per day. Both MySQL and SQLite
            // treat NULLs as distinct in a unique index, so this constrains
            // the per-lesson marks without blocking full-day rows, which all
            // carry a null lesson_id. Those are kept unique by the controller
            // instead - see AttendanceController::bulk().
            $table->unique(['student_id', 'lesson_id', 'date']);

            // The at-risk query filters on a date window and a status.
            $table->index(['date', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('attendances');
    }
};
