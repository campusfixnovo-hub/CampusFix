<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CommentResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'body' => $this->body,
            'is_internal' => (bool) $this->is_internal,
            'created_at' => $this->created_at?->toISOString(),
            'author' => new UserResource($this->whenLoaded('user')),
        ];
    }
}
