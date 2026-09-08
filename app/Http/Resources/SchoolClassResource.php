<?php

namespace App\Http\Resources;

use App\Models\SchoolClass;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin SchoolClass */
class SchoolClassResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'level' => $this->level,
            'capacity' => $this->capacity,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'students' => StudentResource::collection($this->whenLoaded('students')),
            'lessons' => LessonResource::collection($this->whenLoaded('lessons')),
            // Only when preloaded; nesting this would otherwise count per row.
            'attendance' => $this->when($this->hasAttendanceSummary(), fn () => [
                'rate' => $this->attendanceRate(),
                'records' => (int) $this->attendance_records_count,
                'absences' => (int) $this->attendance_absent_count,
                'lates' => (int) $this->attendance_late_count,
            ]),
        ];
    }
}
