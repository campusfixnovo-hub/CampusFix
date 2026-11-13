<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\TicketAuditEvent;
use App\Http\Controllers\Controller;
use App\Models\Attachment;
use App\Models\Ticket;
use App\Models\TicketHistory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class TicketFileController extends Controller
{
    public function index(Request $request, Ticket $ticket): JsonResponse
    {
        $this->authorize('view', $ticket);
        $items = $ticket->attachments()->with(['comment', 'uploader:id,name'])
            ->when(! $request->user()->can('viewInternal', $ticket), fn ($query) => $query->whereDoesntHave('comment', fn ($comment) => $comment->where('is_internal', true)))
            ->latest()->paginate(20);
        $items->getCollection()->transform(fn (Attachment $attachment): array => [
            'id' => $attachment->id, 'name' => $attachment->original_name, 'mime_type' => $attachment->mime_type,
            'size_bytes' => $attachment->size_bytes, 'uploaded_at' => $attachment->created_at?->toISOString(),
            'uploader' => $attachment->uploader?->only(['id', 'name']),
            'download_url' => route('api.v1.attachments.download', $attachment),
        ]);

        return response()->json(['data' => $items->items(), 'links' => ['next' => $items->nextPageUrl(), 'prev' => $items->previousPageUrl()], 'meta' => ['current_page' => $items->currentPage(), 'last_page' => $items->lastPage(), 'per_page' => $items->perPage(), 'total' => $items->total()]]);
    }

    public function store(Request $request, Ticket $ticket): JsonResponse
    {
        $this->authorize('view', $ticket);
        $data = $request->validate(['file' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:10240'], 'comment_id' => ['nullable', 'integer']]);
        $comment = null;
        if (! empty($data['comment_id'])) {
            $comment = $ticket->comments()->findOrFail($data['comment_id']);
            $this->authorize('view', $comment);
        }
        $file = $data['file'];
        $path = $file->store("tickets/{$ticket->id}", 'local');
        if (! $path) {
            throw ValidationException::withMessages(['file' => 'Não foi possível armazenar o arquivo.']);
        }

        try {
            $attachment = DB::transaction(function () use ($ticket, $request, $file, $path, $comment): Attachment {
                $attachment = $ticket->attachments()->create([
                    'comment_id' => $comment?->id, 'uploaded_by' => $request->user()->id, 'disk' => 'local', 'path' => $path,
                    'original_name' => $file->getClientOriginalName(), 'mime_type' => $file->getMimeType(),
                    'size_bytes' => $file->getSize(), 'sha256' => hash_file('sha256', $file->getRealPath()),
                ]);
                TicketHistory::create(['ticket_id' => $ticket->id, 'actor_id' => $request->user()->id, 'event_type' => TicketAuditEvent::AttachmentAdded, 'source' => 'api', 'metadata' => ['attachment_id' => $attachment->id], 'created_at' => now()]);

                return $attachment;
            });
        } catch (\Throwable $exception) {
            Storage::disk('local')->delete($path);
            throw $exception;
        }

        return response()->json(['data' => ['id' => $attachment->id, 'name' => $attachment->original_name, 'mime_type' => $attachment->mime_type, 'size_bytes' => $attachment->size_bytes, 'download_url' => route('api.v1.attachments.download', $attachment)]], 201);
    }

    public function download(Attachment $attachment)
    {
        $this->authorize('view', $attachment->ticket);
        if ($attachment->comment && $attachment->comment->is_internal) {
            $this->authorize('view', $attachment->comment);
        }
        abort_unless($attachment->disk === 'local' && Storage::disk('local')->exists($attachment->path), 404);

        return Storage::disk('local')->download($attachment->path, $attachment->original_name, ['Content-Type' => $attachment->mime_type]);
    }
}
