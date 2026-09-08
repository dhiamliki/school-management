<?php

namespace App\Http\Resources;

use App\Models\Attendance;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Attendance
 */
class AttendanceResource extends JsonResource
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
            'student_id' => $this->student_id,
            'lesson_id' => $this->lesson_id,
            // Plain Y-m-d: the marking grid keys its rows off this, and a full
            // ISO timestamp would only have to be trimmed back on the client.
            'date' => $this->date?->toDateString(),
            'status' => $this->status,
            'note' => $this->note,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'student' => new StudentResource($this->whenLoaded('student')),
            'lesson' => new LessonResource($this->whenLoaded('lesson')),
        ];
    }
}
