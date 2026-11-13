<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\CommentResource;
use App\Models\Comment;
use App\Models\Ticket;
use App\Services\TicketWorkflow;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CommentController extends Controller
{
    public function index(Request $request, Ticket $ticket): JsonResponse
    {
        $this->authorize('view', $ticket);
        $comments = $ticket->comments()->with('user')
            ->when(! $request->user()->can('viewInternal', $ticket), fn ($query) => $query->where('is_internal', false))
            ->latest()->paginate(30);

        return CommentResource::collection($comments)->response();
    }

    public function store(Request $request, Ticket $ticket, TicketWorkflow $workflow): JsonResponse
    {
        $this->authorize('view', $ticket);
        $data = $request->validate(['body' => ['required', 'string', 'max:10000'], 'is_internal' => ['sometimes', 'boolean']]);
        $internal = (bool) ($data['is_internal'] ?? false);
        if ($internal) {
            $this->authorize('viewInternal', $ticket);
        }
        $comment = $workflow->comment($ticket, $request->user(), $data['body'], $internal, 'api');

        return (new CommentResource($comment->load('user')))->response()->setStatusCode(201);
    }
}
