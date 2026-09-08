<?php

namespace App\Http\Resources;

use App\Models\Timetable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Timetable
 */
class TimetableResource extends JsonResource
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
            'lesson_id' => $this->lesson_id,
            'day_of_week' => $this->day_of_week,
            'start_time' => $this->start_time,
            'end_time' => $this->end_time,
            'room' => $this->room,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'lesson' => new LessonResource($this->whenLoaded('lesson')),
        ];
    }
}
