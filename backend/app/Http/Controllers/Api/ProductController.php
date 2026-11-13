<?php

namespace App\Http\Controllers\Api;

use App\Enums\Tracking;
use App\Http\Controllers\Controller;
use App\Http\Requests\Inventory\ProductRequest;
use App\Http\Resources\ProductResource;
use App\Models\Product;
use App\Services\AuditLogger;
use App\Services\InventoryReportService;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    public function __construct(
        private readonly InventoryReportService $report,
        private readonly AuditLogger $audit,
    ) {
    }

    public function index(Request $request)
    {
        $search = trim((string) $request->query('search'));
        $like = '%'.addcslashes($search, '%_\\').'%';
        $tracking = Tracking::tryFrom((string) $request->query('tracking'));

        $page = Product::query()
            ->when($search !== '', fn ($q) => $q->where(fn ($i) => $i
                ->where('name', 'like', $like)->orWhere('brand', 'like', $like)
                ->orWhere('model', 'like', $like)->orWhere('sku', 'like', $like)))
            ->when($tracking, fn ($q) => $q->where('tracking', $tracking->value))
            ->when($request->has('active'), fn ($q) => $q->where('is_active', $request->boolean('active')))
            ->orderBy('name')
            ->paginate(50);

        $this->attachStock($page->getCollection());

        return ProductResource::collection($page);
    }

    public function store(ProductRequest $request)
    {
        $product = Product::create($request->validated());
        $this->audit->record($product, 'created', null, $request->validated());

        return (new ProductResource($this->attachStock(Product::query()->findOrFail($product->id))))
            ->response()->setStatusCode(201);
    }

    public function show(Product $product): ProductResource
    {
        return new ProductResource($this->attachStock($product));
    }

    /** Products are deactivated, never deleted: sales and stock history keep pointing at them. */
    public function update(ProductRequest $request, Product $product): ProductResource
    {
        $old = $product->only(['name', 'sku', 'default_selling_price', 'reorder_level', 'is_active']);
        $product->update($request->validated());
        $this->audit->record($product, 'updated', $old, $product->only(array_keys($old)));

        return new ProductResource($this->attachStock($product->refresh()));
    }

    /** @param Product|\Illuminate\Support\Collection<int, Product> $products */
    private function attachStock($products)
    {
        $list = $products instanceof Product ? collect([$products]) : $products;
        $stock = $this->report->stockFor($list);

        foreach ($list as $product) {
            $product->setAttribute('stock_info', $stock[$product->id]);
        }

        return $products;
    }
}
