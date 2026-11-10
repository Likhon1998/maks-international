<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Models\Category;
use App\Models\PriceGroup;
use App\Models\Product;
use App\Services\ProductService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ProductController extends Controller
{
    private const PRICE_FIELDS = [
        'cost_price', 'china_purchase_price', 'shipping_cost', 'landed_cost', 'wholesale_price', 'dealer_price',
        'distributor_price', 'retail_price', 'minimum_selling_price',
    ];

    public function __construct(private ProductService $products) {}

    public function index(Request $request)
    {
        $this->authorize('viewAny', Product::class);

        $products = Product::query()
            ->with(['category', 'brand', 'images'])
            ->when($request->search, function ($q, $search) {
                $q->where(function ($inner) use ($search) {
                    $inner->where('name', 'like', "%{$search}%")
                        ->orWhere('sku', 'like', "%{$search}%")
                        ->orWhere('barcode', 'like', "%{$search}%");
                });
            })
            ->when($request->category_id, fn ($q, $id) => $q->where('category_id', $id))
            ->when($request->status, fn ($q, $status) => $q->where('status', $status))
            ->latest()
            ->paginate(15)
            ->withQueryString();

        return view('admin.products.index', [
            'products' => $products,
            'categories' => Category::query()->orderBy('name')->get(),
        ]);
    }

    public function create()
    {
        $this->authorize('create', Product::class);

        return view('admin.products.create', $this->formData());
    }

    public function store(Request $request)
    {
        $this->authorize('create', Product::class);

        $data = $this->validated($request);
        $groupPrices = $request->input('group_prices', []);
        $openingStock = (int) ($data['stock_on_hand'] ?? 0);
        if ($openingStock > 0 && ! $request->user()->can('products.manage_stock')) {
            throw ValidationException::withMessages(['stock_on_hand' => 'You are not allowed to set stock. Leave it at 0 and receive stock through Inventory.']);
        }
        $data['stock_on_hand'] = 0;

        $product = $this->products->create(
            $data,
            $request->user(),
            $groupPrices,
            $this->uploadedImages($request),
        );

        if ($openingStock > 0) {
            $this->products->setTotalStock($product, $openingStock, $request->user());
        }

        return redirect()->route('products.index')->with('success', "Product {$product->sku} created.");
    }

    public function edit(Product $product)
    {
        $this->authorize('update', $product);

        $product->load(['prices', 'images']);

        return view('admin.products.edit', array_merge($this->formData(), compact('product')));
    }

    public function update(Request $request, Product $product)
    {
        $this->authorize('update', $product);

        $data = $this->validated($request, $product);
        $groupPrices = $request->input('group_prices', []);
        $user = $request->user();

        $product->load('prices');
        $priceChanged = collect(self::PRICE_FIELDS)
            ->contains(fn ($f) => array_key_exists($f, $data) && round((float) $data[$f], 2) !== round((float) $product->{$f}, 2));
        $currentGroup = $product->prices->pluck('wholesale_price', 'price_group_id')->map(fn ($v) => round((float) $v, 2));
        foreach ($groupPrices as $groupId => $value) {
            $new = ($value === null || $value === '') ? null : round((float) $value, 2);
            if ($new !== ($currentGroup[$groupId] ?? null)) {
                $priceChanged = true;
            }
        }
        if ($priceChanged && ! $user->can('products.manage_price')) {
            throw ValidationException::withMessages(['wholesale_price' => 'You are not allowed to change product prices.']);
        }

        $stockChanged = array_key_exists('stock_on_hand', $data) && $data['stock_on_hand'] !== null
            && (int) $data['stock_on_hand'] !== (int) $product->stock_on_hand;
        if ($stockChanged && ! $user->can('products.manage_stock')) {
            throw ValidationException::withMessages(['stock_on_hand' => 'You are not allowed to change stock. Use Inventory.']);
        }

        $this->products->update(
            $product,
            $data,
            $user,
            $priceChanged ? $groupPrices : null,
            $this->uploadedImages($request),
            $request->input('remove_images', []),
            $request->filled('primary_image_id') ? (int) $request->input('primary_image_id') : null,
        );

        if ($stockChanged) {
            $this->products->setTotalStock($product->fresh(), (int) $data['stock_on_hand'], $user);
        }

        return redirect()->route('products.index')->with('success', 'Product updated.');
    }

    private function validated(Request $request, ?Product $product = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:190'],
            'sku' => ['required', 'string', 'max:80', Rule::unique('products', 'sku')->ignore($product?->id)],
            'barcode' => ['nullable', 'string', 'max:80', Rule::unique('products', 'barcode')->ignore($product?->id)],
            'category_id' => ['nullable', 'exists:categories,id'],
            'brand_id' => ['nullable', 'exists:brands,id'],
            'model' => ['nullable', 'string', 'max:120'],
            'variant' => ['nullable', 'string', 'max:120'],
            'description' => ['nullable', 'string'],
            'image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
            'images' => ['nullable', 'array', 'max:'.ProductService::MAX_IMAGES],
            'images.*' => ['image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
            'remove_images' => ['nullable', 'array'],
            'remove_images.*' => ['integer'],
            'primary_image_id' => ['nullable', 'integer'],
            'cost_price' => ['nullable', 'numeric', 'min:0'],
            'china_purchase_price' => ['nullable', 'numeric', 'min:0'],
            'shipping_cost' => ['nullable', 'numeric', 'min:0'],
            'landed_cost' => ['nullable', 'numeric', 'min:0'],
            'wholesale_price' => ['required', 'numeric', 'min:0'],
            'dealer_price' => ['nullable', 'numeric', 'min:0'],
            'distributor_price' => ['nullable', 'numeric', 'min:0'],
            'retail_price' => ['nullable', 'numeric', 'min:0'],
            'minimum_selling_price' => ['nullable', 'numeric', 'min:0'],
            'warranty' => ['nullable', 'string', 'max:120'],
            'minimum_stock' => ['nullable', 'integer', 'min:0'],
            'stock_on_hand' => ['nullable', 'integer', 'min:0'],
            'status' => ['required', Rule::in(['active', 'inactive', 'discontinued'])],
            'group_prices' => ['nullable', 'array'],
            'group_prices.*' => ['nullable', 'numeric', 'min:0'],
        ]);

        $data['is_published'] = $request->boolean('is_published', true);
        $data['china_purchase_price'] = round((float) ($data['china_purchase_price'] ?? 0), 2);
        $data['shipping_cost'] = round((float) ($data['shipping_cost'] ?? 0), 2);
        if ($request->boolean('auto_total_cost', true)) {
            $data['cost_price'] = round($data['china_purchase_price'] + $data['shipping_cost'], 2);
        } else {
            $data['cost_price'] = round((float) ($data['cost_price'] ?? 0), 2);
        }

        return $data;
    }

    /**
     * @return list<\Illuminate\Http\UploadedFile>
     */
    private function uploadedImages(Request $request): array
    {
        $files = $request->file('images', []);
        if (! is_array($files)) {
            $files = $files ? [$files] : [];
        }
        if ($single = $request->file('image')) {
            $files[] = $single;
        }

        return array_values(array_filter($files));
    }

    private function formData(): array
    {
        return [
            'categories' => Category::query()->where('is_active', true)->orderBy('name')->get(),
            'brands' => Brand::query()->where('is_active', true)->orderBy('name')->get(),
            'priceGroups' => PriceGroup::query()->where('is_active', true)->orderBy('name')->get(),
        ];
    }
}
