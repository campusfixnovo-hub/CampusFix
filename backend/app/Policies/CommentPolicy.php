<?php

namespace App\Policies;

use App\Models\Comment;
use App\Models\User;

class CommentPolicy
{
    public function view(User $user, Comment $comment): bool
    {
        if ($comment->is_internal) {
            return app(TicketPolicy::class)->viewInternal($user, $comment->ticket);
        }

        return app(TicketPolicy::class)->view($user, $comment->ticket);
    }
}
