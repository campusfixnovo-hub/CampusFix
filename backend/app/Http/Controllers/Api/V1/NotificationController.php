<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $items = $request->user()->notifications()->latest()->paginate(20);
        $items->getCollection()->transform(fn ($notification): array => [
            'id' => $notification->id, 'type' => class_basename($notification->type), 'data' => $notification->data,
            'read_at' => $notification->read_at?->toISOString(), 'created_at' => $notification->created_at?->toISOString(),
        ]);

        return response()->json(['data' => $items->items(), 'meta' => ['current_page' => $items->currentPage(), 'last_page' => $items->lastPage(), 'per_page' => $items->perPage(), 'total' => $items->total()]]);
    }

    public function markRead(Request $request, string $notification): JsonResponse
    {
        $record = $request->user()->notifications()->whereKey($notification)->firstOrFail();
        $record->markAsRead();

        return response()->json([], 204);
    }
}
