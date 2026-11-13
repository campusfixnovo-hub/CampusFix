<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Building;
use App\Models\Campus;
use App\Models\Category;
use App\Models\Location;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CatalogController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'campus_id' => ['nullable', 'integer', Rule::exists('campuses', 'id')->where('is_active', true)],
            'building_id' => ['nullable', 'integer', Rule::exists('buildings', 'id')->where('is_active', true)],
        ]);
        $buildings = Building::query()->where('is_active', true)->when($filters['campus_id'] ?? null, fn ($query, $id) => $query->where('campus_id', $id))->orderBy('name')->get(['id', 'campus_id', 'name']);
        $locations = Location::query()->where('is_active', true)->when($filters['building_id'] ?? null, fn ($query, $id) => $query->where('building_id', $id))
            ->when(! isset($filters['building_id']) && isset($filters['campus_id']), fn ($query) => $query->whereHas('building', fn ($building) => $building->where('campus_id', $filters['campus_id'])))
            ->with('building:id,campus_id')->orderBy('name')->get(['id', 'building_id', 'name']);

        return response()->json(['data' => [
            'campuses' => Campus::query()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'code']),
            'categories' => Category::query()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'slug']),
            'buildings' => $buildings,
            'locations' => $locations->map(fn (Location $location): array => ['id' => $location->id, 'building_id' => $location->building_id, 'campus_id' => $location->building?->campus_id, 'name' => $location->name]),
        ]]);
    }
}
