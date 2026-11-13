<?php

namespace Database\Factories;

use App\Models\Attachment;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Attachment> */
class AttachmentFactory extends Factory
{
    protected $model = Attachment::class;

    public function definition(): array
    {
        return ['ticket_id' => Ticket::factory(), 'comment_id' => null, 'uploaded_by' => User::factory(), 'disk' => 'local', 'path' => 'tickets/'.fake()->uuid().'.jpg', 'original_name' => fake()->word().'.jpg', 'mime_type' => 'image/jpeg', 'size_bytes' => fake()->numberBetween(1024, 1048576), 'sha256' => fake()->sha256()];
    }
}
