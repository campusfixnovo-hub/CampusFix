<?php

use App\Enums\UserType;
use App\Models\{Campus,Category,Ticket,User};
use App\Services\TicketWorkflow;

it('shows only tickets within a requester scope and renders the dashboard', function () {
    $requester=User::factory()->type(UserType::Student)->create(); $requester->assignRole('student');
    $other=User::factory()->type(UserType::Staff)->create(); $other->assignRole('staff');
    $own=Ticket::factory()->create(['requester_id'=>$requester->id]);
    $hidden=Ticket::factory()->create(['requester_id'=>$other->id]);

    $this->actingAs($requester)->get(route('tickets.index'))->assertOk()->assertSee($own->code)->assertDontSee($hidden->code);
    $this->actingAs($requester)->get(route('tickets.show',$own))->assertOk()->assertSee($own->title);
    $this->actingAs($requester)->get(route('dashboard'))->assertOk()->assertSee('Solicitações recentes');
});

it('creates a ticket through the shared workflow and writes the initial audit event', function () {
    $requester=User::factory()->type(UserType::Student)->create(); $requester->assignRole('student');
    $category=Category::query()->where('is_active',true)->firstOrFail();
    $campus=Campus::query()->where('is_active',true)->firstOrFail();
    $this->actingAs($requester)->post(route('tickets.store'),[
        'title'=>'Luminária sem funcionamento','description'=>'A luminária da sala está apagada.',
        'category_id'=>$category->id,'priority'=>'medium','campus_id'=>$campus->id,
    ])->assertRedirect();
    $ticket=Ticket::query()->where('requester_id',$requester->id)->latest('id')->firstOrFail();
    expect($ticket->code)->toStartWith('CF-')
        ->and($ticket->histories()->where('event_type','created')->exists())->toBeTrue();
});

it('blocks requesters from adding internal notes', function () {
    $requester=User::factory()->type(UserType::Student)->create(); $requester->assignRole('student');
    $ticket=Ticket::factory()->create(['requester_id'=>$requester->id]);
    $this->actingAs($requester)->post(route('tickets.comments.store',$ticket),['body'=>'Anotação confidencial','is_internal'=>1])->assertForbidden();
    expect($ticket->comments()->count())->toBe(0);
});
