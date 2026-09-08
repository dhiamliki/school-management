<?php

namespace App\Http\Resources;

use App\Models\Student;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Student */
class StudentResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'matricule' => $this->matricule,
            'birth_date' => $this->birth_date,
            'gender' => $this->gender,
            'school_class_id' => $this->school_class_id,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'school_class' => new SchoolClassResource($this->whenLoaded('schoolClass')),
            // Only the profile endpoint loads this; the list view omits it.
            'attendance_history' => AttendanceResource::collection($this->whenLoaded('attendances')),
            // Only when preloaded. Without this guard a pupil nested in
            // another resource counts on demand, four queries per row.
            'attendance' => $this->when($this->hasAttendanceSummary(), fn () => [
                'rate' => $this->attendanceRate(),
                'records' => $this->attendanceRecordCount(),
                'absences' => $this->absenceCount(),
                'lates' => $this->lateCount(),
            ]),
        ];
    }
}
