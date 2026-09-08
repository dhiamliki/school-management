<?php

namespace App\Http\Resources;

use App\Models\SchoolClass;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin SchoolClass
 */
class SchoolClassResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * Every field is listed explicitly so that new columns are not exposed
     * by accident.
     *
     * @return array<string, mixed>
     */
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
            // Present only when the tallies were loaded, which the index
            // deliberately does not do.
            'attendance' => $this->when($this->hasAttendanceSummary(), fn () => [
                'rate' => $this->attendanceRate(),
                'records' => (int) $this->attendance_records_count,
                'absences' => (int) $this->attendance_absent_count,
                'lates' => (int) $this->attendance_late_count,
            ]),
        ];
    }
}
