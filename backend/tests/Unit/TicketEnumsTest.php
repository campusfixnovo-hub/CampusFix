<?php

use App\Enums\TicketPriority;
use App\Enums\TicketStatus;

it('pauses the SLA only while awaiting the requester', function () {
    expect(TicketStatus::AwaitingRequester->pausesSla())->toBeTrue()
        ->and(TicketStatus::AwaitingMaterial->pausesSla())->toBeFalse();
});

it('provides all four ordered ticket priorities', function () {
    expect(array_column(TicketPriority::cases(), 'value'))
        ->toBe(['low', 'medium', 'high', 'critical']);
});
