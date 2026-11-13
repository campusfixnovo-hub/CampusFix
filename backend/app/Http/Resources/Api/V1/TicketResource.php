<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TicketResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'title' => $this->title,
            'description' => $this->description,
            'status' => ['value' => $this->status?->value, 'label' => $this->status?->label()],
            'priority' => ['value' => $this->priority?->value, 'label' => $this->priority?->label()],
            'category' => $this->whenLoaded('category', fn (): ?array => $this->category ? ['id' => $this->category->id, 'name' => $this->category->name] : null),
            'campus' => $this->whenLoaded('campus', fn (): ?array => $this->campus ? ['id' => $this->campus->id, 'name' => $this->campus->name] : null),
            'building' => $this->whenLoaded('building', fn (): ?array => $this->building ? ['id' => $this->building->id, 'name' => $this->building->name] : null),
            'location' => $this->whenLoaded('location', fn (): ?array => $this->location ? ['id' => $this->location->id, 'name' => $this->location->name] : null),
            'location_details' => $this->location_details,
            'team' => $this->whenLoaded('team', fn (): ?array => $this->team ? ['id' => $this->team->id, 'name' => $this->team->name] : null),
            'technician' => $this->whenLoaded('assignedTechnician', fn (): ?array => $this->assignedTechnician ? ['id' => $this->assignedTechnician->id, 'name' => $this->assignedTechnician->name] : null),
            'requester' => $this->whenLoaded('requester', fn (): ?array => $this->requester ? ['id' => $this->requester->id, 'name' => $this->requester->name] : null),
            'sla' => [
                'target_minutes' => $this->sla_target_minutes,
                'due_at' => $this->due_at?->toISOString(),
                'breached_at' => $this->sla_breached_at?->toISOString(),
                'paused' => (bool) ($this->active_sla_pause_count ?? $this->slaPauses()->whereNull('ended_at')->exists()),
            ],
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
