<?php

namespace App\Http\Controllers;

use App\Http\Resources\ServiceCategoryResource;
use App\Models\ServiceCategory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Arbre public des catégories de services actives (GET /categories).
 */
class ServiceCategoryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $categories = ServiceCategory::query()
            ->whereNull('parent_id')
            ->where('is_active', true)
            ->with(['children' => fn (HasMany $query) => $query->where('is_active', true)->ordered()])
            ->ordered()
            ->get();

        return ServiceCategoryResource::collection($categories)
            ->response($request)
            ->header('Vary', 'Accept-Language');
    }
}
