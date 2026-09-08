<?php

namespace App\Http\Resources;

use App\Models\Teacher;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Teacher
 */
class TeacherResource extends JsonResource
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
            'email' => $this->email,
            'phone' => $this->phone,
            'subject' => $this->subject,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'lessons' => LessonResource::collection($this->whenLoaded('lessons')),
            // Profile-only extras. The classes served are derived from the
            // teacher's lessons, so they need no column of their own.
            'lessons_count' => $this->whenCounted('lessons'),
            'classes' => SchoolClassResource::collection($this->whenLoaded('schoolClasses')),
        ];
    }
}
