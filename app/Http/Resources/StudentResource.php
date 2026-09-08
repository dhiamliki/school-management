<?php

namespace App\Http\Resources;

use App\Models\Student;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Student
 */
class StudentResource extends JsonResource
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
            'matricule' => $this->matricule,
            'birth_date' => $this->birth_date,
            'gender' => $this->gender,
            'school_class_id' => $this->school_class_id,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'school_class' => new SchoolClassResource($this->whenLoaded('schoolClass')),
            // Only the profile endpoint loads this; the list view omits it.
            'attendance_history' => AttendanceResource::collection($this->whenLoaded('attendances')),
            // A small roll-up so a list of pupils can show attendance without
            // a second request per row. Null rate means nothing on record yet.
            //
            // Present only when the tallies were preloaded, the same guard
            // SchoolClassResource uses. Without it a pupil nested inside
            // another resource - every row of the attendance index carries
            // one - fell back to counting on demand, four queries deep, per
            // row. Every endpoint whose response the frontend reads this from
            // preloads the tallies, so the block is there when it is wanted.
            'attendance' => $this->when($this->hasAttendanceSummary(), fn () => [
                'rate' => $this->attendanceRate(),
                'records' => $this->attendanceRecordCount(),
                'absences' => $this->absenceCount(),
                'lates' => $this->lateCount(),
            ]),
        ];
    }
}
