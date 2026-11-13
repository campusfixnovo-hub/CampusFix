<?php

namespace App\Enums;

enum TicketAuditEvent: string
{
    case Created = 'created';
    case DetailsUpdated = 'details_updated';
    case StatusChanged = 'status_changed';
    case PriorityChanged = 'priority_changed';
    case TeamAssigned = 'team_assigned';
    case TechnicianAssigned = 'technician_assigned';
    case LocationChanged = 'location_changed';
    case CommentAdded = 'comment_added';
    case Resolved = 'resolved';
    case Closed = 'closed';
    case Reopened = 'reopened';
    case SlaPaused = 'sla_paused';
    case SlaResumed = 'sla_resumed';
    case SlaBreached = 'sla_breached';
    case Rated = 'rated';
    case AttachmentAdded = 'attachment_added';
    case Cancelled = 'cancelled';
}
