<?php

namespace App\Http\Controllers\Api;

use App\Enums\CategoryKind;
use App\Enums\Scope;
use App\Http\Controllers\Controller;
use App\Http\Requests\Categories\StoreCategoryRequest;
use App\Http\Requests\Categories\UpdateCategoryRequest;
use App\Http\Resources\CategoryResource;
use App\Models\TransactionCategory;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CategoryController extends Controller
{
    public function __construct(private readonly AuditLogger $audit)
    {
    }

    /** Top-level categories with their subcategories. */
    public function index(Request $request)
    {
        $scope = Scope::tryFrom((string) $request->query('scope'));
        $kind = CategoryKind::tryFrom((string) $request->query('kind'));
        $filterActive = $request->has('active');
        $active = $request->boolean('active');

        $categories = TransactionCategory::query()
            ->whereNull('parent_id')
            ->when($scope, fn ($q) => $q->where('scope', $scope->value))
            ->when($kind, fn ($q) => $q->where('kind', $kind->value))
            ->when($filterActive, fn ($q) => $q->where('is_active', $active))
            ->with(['children' => fn ($q) => $q->when($filterActive, fn ($c) => $c->where('is_active', $active))])
            ->orderBy('scope')
            ->orderBy('kind')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return CategoryResource::collection($categories);
    }

    public function store(StoreCategoryRequest $request): JsonResponse
    {
        $category = DB::transaction(function () use ($request) {
            $category = TransactionCategory::create(array_merge(
                ['sort_order' => 0, 'is_system' => false, 'is_active' => true],
                $request->validated()
            ));
            $this->audit->record($category, 'created', null, $category->refresh()->auditSnapshot());

            return $category;
        });

        return (new CategoryResource($category))->response()->setStatusCode(201);
    }

    public function update(UpdateCategoryRequest $request, TransactionCategory $category): CategoryResource
    {
        $data = $request->validated();

        DB::transaction(function () use ($category, $data) {
            $old = $category->auditSnapshot();
            $category->update($data);

            // Deactivating a parent also deactivates its subcategories.
            if (array_key_exists('is_active', $data) && ! $data['is_active']) {
                $category->children()->update(['is_active' => false]);
            }

            $this->audit->record($category, 'updated', $old, $category->refresh()->auditSnapshot());
        });

        return new CategoryResource($category->load('children'));
    }

    /** Only categories that were never used can be deleted; otherwise deactivate them. */
    public function destroy(TransactionCategory $category): Response
    {
        $inUse = $category->children()->exists()
            || $category->transactions()->exists()
            || DB::table('budgets')->where('category_id', $category->id)->exists();

        if ($inUse) {
            throw ValidationException::withMessages([
                'category' => ['This category is in use (subcategories, transactions or budgets). Deactivate it instead of deleting it.'],
            ]);
        }

        DB::transaction(function () use ($category) {
            $this->audit->record($category, 'deleted', $category->auditSnapshot(), null);
            $category->delete();
        });

        return response()->noContent();
    }
}
