<?php

use App\Http\Controllers\AIChatController;
use App\Http\Controllers\AttendanceController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\LessonController;
use App\Http\Controllers\SchoolClassController;
use App\Http\Controllers\SearchController;
use App\Http\Controllers\StudentController;
use App\Http\Controllers\TeacherController;
use App\Http\Controllers\TimetableController;
use Illuminate\Support\Facades\Route;

// The SPA calls GET /sanctum/csrf-cookie before posting here.
// Throttled per IP: five attempts a minute.
Route::post('login', [AuthController::class, 'login'])
    ->middleware('throttle:5,1')
    ->name('login');

Route::middleware('auth:sanctum')->group(function () {
    Route::get('user', [AuthController::class, 'user']);
    Route::post('logout', [AuthController::class, 'logout']);

    // Before the apiResource, or the {timetable} wildcard swallows it.
    Route::get('timetables/check-conflicts', [TimetableController::class, 'checkConflicts'])
        ->name('timetables.check-conflicts');

    Route::get('dashboard/stats', [DashboardController::class, 'stats'])->name('dashboard.stats');
    Route::get('dashboard/today-schedule', [DashboardController::class, 'todaySchedule'])->name('dashboard.today-schedule');
    Route::get('dashboard/recent-activity', [DashboardController::class, 'recentActivity'])->name('dashboard.recent-activity');
    Route::get('dashboard/attendance-week', [DashboardController::class, 'attendanceWeek'])->name('dashboard.attendance-week');
    Route::get('search', SearchController::class)->name('search');

    // Before the apiResource, or the {attendance} wildcard captures these.
    Route::get('attendances/at-risk', [AttendanceController::class, 'atRisk'])->name('attendances.at-risk');
    Route::get('attendances/by-student/{student}', [AttendanceController::class, 'byStudent'])->name('attendances.by-student');
    Route::post('attendances/bulk', [AttendanceController::class, 'bulk'])->name('attendances.bulk');

    Route::apiResource('teachers', TeacherController::class);
    Route::apiResource('school-classes', SchoolClassController::class);
    Route::apiResource('students', StudentController::class);
    Route::apiResource('lessons', LessonController::class);
    Route::apiResource('timetables', TimetableController::class);
    Route::apiResource('attendances', AttendanceController::class);

    // The Gemini free tier has a daily cap.
    Route::post('ai/chat', AIChatController::class)
        ->middleware('throttle:20,1')
        ->name('ai.chat');
});
