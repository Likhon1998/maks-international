@if ($errors->any())
    <div class="product-desk-alert">
        <ul>@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
    </div>
@endif

<div class="pd-layout">
    <section
        class="card pd-studio"
        x-data="productGallery({
            max: 12,
            existing: @js(($product?->images ?? collect())->map(fn ($image) => [
                'id' => $image->id,
                'url' => $image->url(),
                'primary' => (bool) $image->is_primary,
            ])->values()),
        })"
    >
        <div class="pd-card-head">
            <div>
                <h2>Gallery</h2>
                <p>Cover photo first, then extra angles</p>
            </div>
            <span class="pd-count" x-text="count + ' of ' + max"></span>
        </div>

        <input type="hidden" name="primary_image_id" :value="coverId">
        <template x-for="id in removedIds" :key="'rm-'+id">
            <input type="hidden" name="remove_images[]" :value="id">
        </template>

        <div class="pd-stage" :class="{ 'is-empty': !stageUrl }">
            <img x-show="stageUrl" x-cloak :src="stageUrl" alt="">
            <button type="button" class="pd-stage-empty" x-show="!stageUrl" @click="$refs.fileInput.click()">
                <i data-lucide="image-plus"></i>
                <strong>Add pictures</strong>
                <span>JPEG, PNG or WebP · up to 12 · 4 MB each</span>
            </button>
            <span class="pd-stage-badge" x-show="stageUrl && stageIsCover" x-cloak>Cover</span>
        </div>

        <div class="pd-rail">
            <template x-for="img in existing" :key="'ex-'+img.id">
                <article class="pd-thumb" :class="{ 'is-cover': img.primary && !img.remove, 'is-removed': img.remove, 'is-on': stageUrl === img.url && !img.remove }">
                    <button type="button" class="pd-thumb-photo" @click="preview(img.url)" :disabled="img.remove">
                        <img :src="img.url" alt="">
                    </button>
                    <div class="pd-thumb-actions">
                        <button type="button" class="pd-thumb-btn" @click="setCover(img)" x-show="!img.remove" x-cloak>Cover</button>
                        <button type="button" class="pd-thumb-btn is-danger" @click="removeExisting(img)" x-text="img.remove ? 'Undo' : 'Delete'"></button>
                    </div>
                </article>
            </template>

            <template x-for="(file, index) in pending" :key="file.key">
                <article class="pd-thumb is-new" :class="{ 'is-on': stageUrl === file.url }">
                    <button type="button" class="pd-thumb-photo" @click="preview(file.url)">
                        <img :src="file.url" :alt="file.name">
                    </button>
                    <div class="pd-thumb-actions">
                        <span class="pd-thumb-new">New</span>
                        <button type="button" class="pd-thumb-btn is-danger" @click="removePending(index)">Delete</button>
                    </div>
                </article>
            </template>

            <button type="button" class="pd-add" @click="$refs.fileInput.click()" :disabled="count >= max" x-show="count > 0 && count < max" x-cloak>
                <i data-lucide="plus"></i>
                <span>Add pictures</span>
            </button>
        </div>

        <input
            x-ref="fileInput"
            class="pg-file"
            type="file"
            name="images[]"
            accept="image/jpeg,image/png,image/webp"
            multiple
            tabindex="-1"
            aria-hidden="true"
            @change="add($event)"
        >
        <p class="product-gallery-error" x-show="error" x-text="error" x-cloak></p>
    </section>

    <div class="pd-stack">
        <section class="card pd-card">
            <div class="pd-card-head">
                <div>
                    <h2>Identity</h2>
                    <p>Name, codes and how shops find it</p>
                </div>
            </div>
            <div class="pd-fields">
                <div class="pd-field is-wide">
                    <label class="label" for="product-name">Product name</label>
                    <input class="input pd-name" id="product-name" name="name" value="{{ old('name', $product?->name) }}" required placeholder="e.g. Samsung Galaxy A16 8/256">
                </div>
                <div class="pd-field">
                    <label class="label" for="product-sku">SKU</label>
                    <input class="input" id="product-sku" name="sku" value="{{ old('sku', $product?->sku) }}" required>
                </div>
                <div class="pd-field">
                    <label class="label" for="product-barcode">Barcode</label>
                    <input class="input" id="product-barcode" name="barcode" value="{{ old('barcode', $product?->barcode) }}">
                </div>
                <div class="pd-field">
                    <label class="label" for="product-model">Model / variant</label>
                    <input class="input" id="product-model" name="model" value="{{ old('model', $product?->model) }}" placeholder="Optional">
                </div>
                <div class="pd-field">
                    <x-catalog-quick-select
                        name="category_id"
                        label="Category"
                        noun="category"
                        :options="$categories"
                        :selected="old('category_id', $product?->category_id)"
                        :store-url="route('categories.store')"
                        :can-create="auth()->user()?->can('categories.create') || auth()->user()?->can('products.create')"
                    />
                </div>
                <div class="pd-field">
                    <x-catalog-quick-select
                        name="brand_id"
                        label="Brand"
                        noun="brand"
                        :options="$brands"
                        :selected="old('brand_id', $product?->brand_id)"
                        :store-url="route('brands.store')"
                        :can-create="auth()->user()?->can('brands.create') || auth()->user()?->can('products.create')"
                    />
                </div>
                <div class="pd-field">
                    <label class="label" for="product-status">Status</label>
                    <select class="select" id="product-status" name="status">
                        @foreach (['active' => 'Active', 'inactive' => 'Inactive', 'discontinued' => 'Discontinued'] as $value => $label)
                            <option value="{{ $value }}" @selected(old('status', $product?->status ?? 'active') === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="pd-field is-wide">
                    <label class="label" for="product-description">Description</label>
                    <textarea class="textarea" id="product-description" name="description" rows="4" placeholder="What the shopkeeper needs to know">{{ old('description', $product?->description) }}</textarea>
                </div>
                <label class="pd-switch is-wide">
                    <input type="hidden" name="is_published" value="0">
                    <input type="checkbox" name="is_published" value="1" @checked(old('is_published', $product?->is_published ?? true))>
                    <span class="pd-switch-ui" aria-hidden="true"></span>
                    <span>
                        <b>Visible in B2B shop portal</b>
                        <small>Shopkeepers can see and order this SKU</small>
                    </span>
                </label>
            </div>
        </section>

        <section class="card pd-card">
            <div class="pd-card-head">
                <div>
                    <h2>Pricing</h2>
                    <p>Selling rates and cost. Salesmen cannot go below the minimum.</p>
                </div>
            </div>
            <div class="pd-money-grid">
                <label class="pd-money">
                    <span>Wholesale <em>Required</em></span>
                    <span class="pd-money-box">
                        <em>৳</em>
                        <input class="input" type="number" step="0.01" name="wholesale_price" value="{{ old('wholesale_price', $product?->wholesale_price ?? 0) }}" required>
                    </span>
                </label>
                <label class="pd-money">
                    <span>Dealer</span>
                    <span class="pd-money-box">
                        <em>৳</em>
                        <input class="input" type="number" step="0.01" name="dealer_price" value="{{ old('dealer_price', $product?->dealer_price ?? 0) }}">
                    </span>
                </label>
                <label class="pd-money">
                    <span>Distributor</span>
                    <span class="pd-money-box">
                        <em>৳</em>
                        <input class="input" type="number" step="0.01" name="distributor_price" value="{{ old('distributor_price', $product?->distributor_price ?? 0) }}">
                    </span>
                </label>
                <label class="pd-money">
                    <span>Retail</span>
                    <span class="pd-money-box">
                        <em>৳</em>
                        <input class="input" type="number" step="0.01" name="retail_price" value="{{ old('retail_price', $product?->retail_price ?? 0) }}">
                    </span>
                </label>
                <label class="pd-money">
                    <span>Minimum selling</span>
                    <span class="pd-money-box">
                        <em>৳</em>
                        <input class="input" type="number" step="0.01" name="minimum_selling_price" value="{{ old('minimum_selling_price', $product?->minimum_selling_price ?? 0) }}">
                    </span>
                </label>
            </div>

            @php
                $chinaCost = (float) old('china_purchase_price', $product?->china_purchase_price ?? 0);
                $shippingCost = (float) old('shipping_cost', $product?->shipping_cost ?? 0);
                $totalCost = (float) old('cost_price', $product?->cost_price ?? 0);
                $legacyCost = $product && $chinaCost == 0.0 && $shippingCost == 0.0 && $totalCost > 0;
                $autoTotal = old('auto_total_cost', $legacyCost ? '0' : '1') == '1';
            @endphp
            <div
                class="pd-groups"
                x-data="{
                    china: {{ $chinaCost }},
                    shipping: {{ $shippingCost }},
                    auto: {{ $autoTotal ? 'true' : 'false' }},
                    total: {{ $autoTotal ? round($chinaCost + $shippingCost, 2) : $totalCost }},
                    sync() {
                        if (this.auto) {
                            this.total = Math.round((Number(this.china || 0) + Number(this.shipping || 0)) * 100) / 100;
                        }
                    }
                }"
            >
                <h3>Import cost</h3>
                <p class="muted" style="margin:0 0 10px;font-size:12px">Total Cost = Shipping Cost + China Purchase Price</p>
                <div class="pd-money-grid">
                    <label class="pd-money">
                        <span>China purchase price</span>
                        <span class="pd-money-box">
                            <em>৳</em>
                            <input class="input" type="number" step="0.01" min="0" name="china_purchase_price" x-model.number="china" @input="sync()">
                        </span>
                    </label>
                    <label class="pd-money">
                        <span>Shipping cost</span>
                        <span class="pd-money-box">
                            <em>৳</em>
                            <input class="input" type="number" step="0.01" min="0" name="shipping_cost" x-model.number="shipping" @input="sync()">
                        </span>
                    </label>
                    <label class="pd-switch is-wide" style="margin:0">
                        <input type="hidden" name="auto_total_cost" value="0">
                        <input type="checkbox" name="auto_total_cost" value="1" x-model="auto" @change="sync()">
                        <span class="pd-switch-ui" aria-hidden="true"></span>
                        <span>
                            <b>Calculate total cost automatically</b>
                            <small>Adds shipping cost and China purchase price</small>
                        </span>
                    </label>
                    <label class="pd-money">
                        <span>Total cost</span>
                        <span class="pd-money-box">
                            <em>৳</em>
                            <input class="input" type="number" step="0.01" min="0" name="cost_price" x-model.number="total" :readonly="auto">
                        </span>
                    </label>
                    <label class="pd-money">
                        <span>Landed cost</span>
                        <span class="pd-money-box">
                            <em>৳</em>
                            <input class="input" type="number" step="0.01" name="landed_cost" value="{{ old('landed_cost', $product?->landed_cost ?? 0) }}">
                        </span>
                    </label>
                </div>
            </div>

            @can('products.manage_price')
                @if ($priceGroups->isNotEmpty())
                    <div class="pd-groups">
                        <h3>Price groups</h3>
                        <div class="pd-money-grid is-compact">
                            @foreach ($priceGroups as $group)
                                @php
                                    $existing = $product?->prices?->firstWhere('price_group_id', $group->id)?->wholesale_price;
                                @endphp
                                <label class="pd-money">
                                    <span>{{ $group->name }}</span>
                                    <span class="pd-money-box">
                                        <em>৳</em>
                                        <input class="input" type="number" step="0.01" name="group_prices[{{ $group->id }}]"
                                               value="{{ old('group_prices.'.$group->id, $existing) }}" placeholder="Standard">
                                    </span>
                                </label>
                            @endforeach
                        </div>
                    </div>
                @endif
            @endcan
        </section>

        <section class="card pd-card">
            <div class="pd-card-head">
                <div>
                    <h2>Stock &amp; terms</h2>
                    <p>On-hand quantity and the shop-facing warranty</p>
                </div>
            </div>
            <div class="pd-stock-grid">
                <div class="pd-field">
                    <label class="label" for="product-stock">Stock on hand</label>
                    <input class="input" id="product-stock" type="number" name="stock_on_hand" value="{{ old('stock_on_hand', $product?->stock_on_hand ?? 0) }}">
                </div>
                <div class="pd-field">
                    <label class="label" for="product-min-stock">Minimum stock</label>
                    <input class="input" id="product-min-stock" type="number" name="minimum_stock" value="{{ old('minimum_stock', $product?->minimum_stock ?? 10) }}">
                </div>
                <div class="pd-field">
                    <label class="label" for="product-warranty">Warranty</label>
                    <input class="input" id="product-warranty" name="warranty" value="{{ old('warranty', $product?->warranty) }}" placeholder="e.g. 12 months">
                </div>
            </div>
        </section>
    </div>
</div>
