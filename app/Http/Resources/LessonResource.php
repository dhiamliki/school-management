<?php

namespace App\Http\Resources;

use App\Models\Lesson;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Lesson
 */
class LessonResource extends JsonResource
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
            'title' => $this->title,
            'subject' => $this->subject,
            'teacher_id' => $this->teacher_id,
            'school_class_id' => $this->school_class_id,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'teacher' => new TeacherResource($this->whenLoaded('teacher')),
            'school_class' => new SchoolClassResource($this->whenLoaded('schoolClass')),
            'timetables' => TimetableResource::collection($this->whenLoaded('timetables')),
        ];
    }
}
