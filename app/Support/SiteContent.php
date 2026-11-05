<?php

namespace App\Support;

use App\Models\Category;
use App\Models\Product;
use App\Models\Shop;
use App\Models\SiteSetting;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Public website content, editable from Admin → Website.
 * Every field has a default, so the site renders normally before anything is saved.
 */
class SiteContent
{
    private const CACHE_KEY = 'site_content.v1';

    public const ICONS = [
        'tag' => 'Price tag', 'box' => 'Box', 'check' => 'Check', 'file' => 'Invoice', 'user' => 'Person',
        'truck' => 'Delivery truck', 'shield' => 'Shield', 'star' => 'Star', 'clock' => 'Clock',
        'phone' => 'Phone', 'globe' => 'Globe', 'wallet' => 'Wallet',
    ];

    /** @var array<string, array<string, mixed>>|null */
    private ?array $saved = null;

    /**
     * Field definitions per section. Types: text, textarea, email, url, image, toggle, list, items.
     *
     * @return array<string, array{label: string, description: string, groups: array<string, array<string, array<string, mixed>>>}>
     */
    public static function schema(): array
    {
        $hero = fn (string $eyebrow, string $title, string $lead) => [
            'hero_eyebrow' => ['type' => 'text', 'label' => 'Small heading', 'default' => $eyebrow, 'max' => 80],
            'hero_title' => ['type' => 'text', 'label' => 'Main heading', 'default' => $title, 'max' => 160, 'required' => true],
            'hero_lead' => ['type' => 'textarea', 'label' => 'Intro text', 'default' => $lead, 'max' => 500],
        ];
        $cards = fn (array $default, int $max, bool $icons = false) => [
            'type' => 'items', 'label' => 'Cards', 'max_items' => $max, 'icons' => $icons, 'default' => $default,
        ];
        $card = fn (string $title, string $text, ?string $icon = null) => array_filter(['icon' => $icon, 'title' => $title, 'text' => $text], fn ($v) => $v !== null);

        return [
            'general' => [
                'label' => 'General',
                'description' => 'Brand, contact details and footer — shown on every public page.',
                'groups' => [
                    'Brand' => [
                        'site_name' => ['type' => 'text', 'label' => 'Business name', 'default' => 'Maks International', 'max' => 80, 'required' => true],
                        'logo' => ['type' => 'image', 'label' => 'Logo', 'default' => null, 'help' => 'Square PNG works best. Also used in the admin, field and shop apps.'],
                        'meta_description' => ['type' => 'textarea', 'label' => 'Search engine description', 'default' => 'Maks International — B2B wholesale distribution from import to warehouse fulfilment and shop delivery in Bangladesh.', 'max' => 300],
                        'footer_about' => ['type' => 'textarea', 'label' => 'Footer description', 'default' => 'B2B wholesale distribution for retail shops across Bangladesh — from import and warehousing to delivery and payment.', 'max' => 300],
                    ],
                    'Contact details' => [
                        'email' => ['type' => 'email', 'label' => 'Email', 'default' => 'hello@bynnastrade.com', 'max' => 180],
                        'phone' => ['type' => 'text', 'label' => 'Phone', 'default' => '+880 1700-000000', 'max' => 40],
                        'whatsapp' => ['type' => 'text', 'label' => 'WhatsApp number', 'default' => '', 'max' => 40, 'help' => 'Optional. Shows a WhatsApp link on the contact page.'],
                        'hours' => ['type' => 'text', 'label' => 'Business hours', 'default' => 'Saturday – Thursday, 10:00 – 18:00', 'max' => 120],
                        'address' => ['type' => 'text', 'label' => 'Office address', 'default' => 'Tejgaon Industrial Area, Dhaka', 'max' => 200],
                        'map_url' => ['type' => 'url', 'label' => 'Google Maps link', 'default' => '', 'max' => 500],
                        'maks_gadget_url' => ['type' => 'url', 'label' => 'External order website (optional)', 'default' => '', 'max' => 300, 'help' => 'Leave blank so Order stays on this site (Become a partner). Only set this if you want Order to open an external shop.'],
                    ],
                    'Social links' => [
                        'facebook_url' => ['type' => 'url', 'label' => 'Facebook', 'default' => '', 'max' => 300],
                        'linkedin_url' => ['type' => 'url', 'label' => 'LinkedIn', 'default' => '', 'max' => 300],
                        'youtube_url' => ['type' => 'url', 'label' => 'YouTube', 'default' => '', 'max' => 300],
                    ],
                    'Menu & sign-in links' => [
                        'show_partner_button' => ['type' => 'toggle', 'label' => 'Show “Become a partner” button and page links', 'default' => true],
                        'show_shop_login' => ['type' => 'toggle', 'label' => 'Show “Shop login” in the menu', 'default' => true],
                        'show_field_login' => ['type' => 'toggle', 'label' => 'Show “Field team” sign-in in the footer', 'default' => true],
                        'show_admin_login' => ['type' => 'toggle', 'label' => 'Show “Admin” sign-in in the footer', 'default' => true],
                    ],
                ],
            ],
            'home' => [
                'label' => 'Home page',
                'description' => 'Hero, how-it-works steps, live catalogue section, benefits and call to action.',
                'groups' => [
                    'Hero' => $hero(
                        'B2B wholesale distribution · Bangladesh',
                        'Reliable wholesale supply for your retail shop.',
                        'We import, store and deliver products to approved retail partners — with partner pricing, clear invoices and a dedicated sales team.',
                    ) + [
                        'hero_image' => ['type' => 'image', 'label' => 'Hero image', 'default' => 'https://images.unsplash.com/photo-1586528116311-ad8dd3c8310d?auto=format&fit=crop&w=1200&q=80'],
                        'hero_points' => ['type' => 'list', 'label' => 'Highlights under the buttons', 'max_items' => 5, 'default' => ['Partner pricing', 'Reviewed orders', 'Online invoices']],
                        'primary_button' => ['type' => 'text', 'label' => 'Main button text', 'default' => 'Become a partner', 'max' => 40],
                        'secondary_button' => ['type' => 'text', 'label' => 'Second button text', 'default' => 'Learn more', 'max' => 40],
                        'show_status_card' => ['type' => 'toggle', 'label' => 'Show the order-status card on the image', 'default' => true],
                        'status_card_title' => ['type' => 'text', 'label' => 'Status card title', 'default' => 'Order status', 'max' => 60],
                        'status_card_rows' => ['type' => 'list', 'label' => 'Status card rows (last one shows as in progress)', 'max_items' => 4, 'default' => ['Order approved', 'Packed at warehouse', 'Out for delivery']],
                    ],
                    'Live numbers' => [
                        'show_stats' => ['type' => 'toggle', 'label' => 'Show live numbers (partner shops, products, categories, warehouses)', 'default' => true, 'help' => 'Counted from the admin data automatically.'],
                    ],
                    'How it works' => [
                        'steps_eyebrow' => ['type' => 'text', 'label' => 'Small heading', 'default' => 'How it works', 'max' => 80],
                        'steps_title' => ['type' => 'text', 'label' => 'Heading', 'default' => 'From import to your shop, in four steps.', 'max' => 160],
                        'steps_lead' => ['type' => 'textarea', 'label' => 'Intro text', 'default' => 'One team handles the full supply chain, so you can focus on selling.', 'max' => 400],
                        'steps' => ['label' => 'Steps'] + $cards([
                            $card('Import', 'Products are sourced and brought into Bangladesh by our team.'),
                            $card('Warehouse', 'Stock is checked, stored and prepared at our Dhaka warehouse.'),
                            $card('Order', 'Approved shops order online or through their sales representative.'),
                            $card('Deliver & invoice', 'Orders are delivered to your shop with a clear invoice and payment record.'),
                        ], 6),
                    ],
                    'Categories we supply' => [
                        'show_categories' => ['type' => 'toggle', 'label' => 'Show product categories from the catalogue', 'default' => true, 'help' => 'Lists active categories that have published products. Manage them under Categories.'],
                        'categories_eyebrow' => ['type' => 'text', 'label' => 'Small heading', 'default' => 'What we supply', 'max' => 80],
                        'categories_title' => ['type' => 'text', 'label' => 'Heading', 'default' => 'Product categories in our catalogue.', 'max' => 160],
                        'categories_lead' => ['type' => 'textarea', 'label' => 'Intro text', 'default' => 'Partners see live stock and their own prices in the shop portal.', 'max' => 400],
                    ],
                    'Why partner with us' => [
                        'features_eyebrow' => ['type' => 'text', 'label' => 'Small heading', 'default' => 'Why partner with us', 'max' => 80],
                        'features_title' => ['type' => 'text', 'label' => 'Heading', 'default' => 'Built for shops that buy regularly.', 'max' => 160],
                        'features' => ['label' => 'Benefits'] + $cards([
                            $card('Partner pricing', 'Each shop gets a price list based on its partnership level and volume.', 'tag'),
                            $card('Verified stock', 'See what is available before ordering — no surprises after you place an order.', 'box'),
                            $card('Reviewed orders', 'Every order is checked by our team before stock is reserved and dispatched.', 'check'),
                            $card('Clear invoices', 'Download invoices and track payments and balances from your shop portal.', 'file'),
                            $card('Dedicated sales rep', 'A field representative visits your shop and helps with orders and collections.', 'user'),
                            $card('Doorstep delivery', 'Orders are packed at our warehouse and delivered directly to your shop.', 'truck'),
                        ], 9, true),
                    ],
                    'Call to action' => [
                        'cta_title' => ['type' => 'text', 'label' => 'Heading', 'default' => 'Ready to buy wholesale from Bynnas Trade?', 'max' => 160],
                        'cta_text' => ['type' => 'textarea', 'label' => 'Text', 'default' => 'Apply as a partner. Once approved, you can log in to view products, place orders and manage invoices.', 'max' => 400],
                    ],
                ],
            ],
            'about' => [
                'label' => 'About page',
                'description' => 'Company story, ways of working and commitments.',
                'groups' => [
                    'Hero' => $hero(
                        'About us',
                        'Wholesale distribution, built for Bangladesh.',
                        'Bynnas Trade supplies retail shops with imported products — managing sourcing, warehousing, orders, delivery and payments in one place. We work with approved partners, not as a public marketplace.',
                    ),
                    'What we do' => [
                        'what_eyebrow' => ['type' => 'text', 'label' => 'Small heading', 'default' => 'What we do', 'max' => 80],
                        'what_title' => ['type' => 'text', 'label' => 'Heading', 'default' => 'One team, three ways to work with us.', 'max' => 160],
                        'what_lead' => ['type' => 'textarea', 'label' => 'Intro text', 'default' => 'Our head office manages stock, partners and quality control. Shop owners order at their own prices. Our field team supports shops directly.', 'max' => 500],
                        'info_items' => ['label' => 'Points'] + $cards([
                            $card('Head office', 'Warehouses, shipments, order review, finance and reporting.'),
                            $card('Shop portal', 'Partner prices, available stock, orders, invoices and returns.'),
                            $card('Field team', 'Shop visits, order collection and on-the-ground support.'),
                        ], 6),
                    ],
                    'Our commitments' => [
                        'commit_eyebrow' => ['type' => 'text', 'label' => 'Small heading', 'default' => 'Our commitments', 'max' => 80],
                        'commit_title' => ['type' => 'text', 'label' => 'Heading', 'default' => 'How we work with every partner.', 'max' => 160],
                        'commitments' => ['label' => 'Commitments'] + $cards([
                            $card('Every order is reviewed', 'Orders are checked by our team before stock is reserved, so what you order is what you receive.'),
                            $card('Transparent accounts', 'Invoices, payments and outstanding balances are always visible in your shop portal.'),
                            $card('Fair, consistent pricing', 'Your price list is agreed upfront and applied automatically to every order.'),
                        ], 6),
                    ],
                    'Call to action' => [
                        'cta_title' => ['type' => 'text', 'label' => 'Heading', 'default' => 'Interested in becoming a partner?', 'max' => 160],
                        'cta_text' => ['type' => 'textarea', 'label' => 'Text', 'default' => 'Tell us about your shop and our team will get back to you.', 'max' => 400],
                    ],
                ],
            ],
            'contact' => [
                'label' => 'Contact page',
                'description' => 'Contact form wording. Address, email, phone and hours come from General.',
                'groups' => [
                    'Hero' => $hero(
                        'Contact',
                        'Get in touch with our team.',
                        "Questions about partnerships, wholesale orders or anything else — send us a message and we'll respond within one business day.",
                    ),
                    'Form & sidebar' => [
                        'form_title' => ['type' => 'text', 'label' => 'Form heading', 'default' => 'Send a message', 'max' => 80],
                        'subject_placeholder' => ['type' => 'text', 'label' => 'Subject hint', 'default' => 'Wholesale partnership, order enquiry…', 'max' => 120],
                        'success_message' => ['type' => 'text', 'label' => 'Message shown after sending', 'default' => 'Thanks — we received your message and will reply soon.', 'max' => 200, 'required' => true],
                        'office_title' => ['type' => 'text', 'label' => 'Office card heading', 'default' => 'Head office', 'max' => 80],
                        'partner_card_title' => ['type' => 'text', 'label' => 'Partner card heading', 'default' => 'Want to become a partner?', 'max' => 120],
                        'partner_card_text' => ['type' => 'textarea', 'label' => 'Partner card text', 'default' => 'Apply online and our team will review your shop.', 'max' => 300],
                    ],
                ],
            ],
            'partner' => [
                'label' => 'Partner page',
                'description' => 'Become-a-partner application page. New applications appear under Partner Leads.',
                'groups' => [
                    'Hero' => $hero(
                        'Become a partner',
                        'Apply for wholesale access.',
                        "Tell us about your retail business. After review, you'll receive shop portal access, your partner price list and payment terms.",
                    ),
                    'Form' => [
                        'form_title' => ['type' => 'text', 'label' => 'Form heading', 'default' => 'Partner application', 'max' => 80],
                        'message_placeholder' => ['type' => 'text', 'label' => '“About your shop” hint', 'default' => 'Product categories you sell, estimated monthly purchase, shop locations', 'max' => 200],
                        'success_message' => ['type' => 'text', 'label' => 'Message shown after applying', 'default' => 'Application received. Our team will review and contact you with next steps.', 'max' => 200, 'required' => true],
                    ],
                    'What happens next' => [
                        'steps_title' => ['type' => 'text', 'label' => 'Heading', 'default' => 'What happens next', 'max' => 80],
                        'steps' => ['label' => 'Steps'] + $cards([
                            $card('We review your application', 'Our team checks your shop details, usually within 1–2 business days.'),
                            $card('We contact you', 'A representative calls to confirm details and discuss pricing and terms.'),
                            $card('You get portal access', 'Log in to see your price list, place orders and manage invoices.'),
                        ], 6),
                    ],
                ],
            ],
        ];
    }

    /** @return array<string, array<string, mixed>> flat field definitions for one section */
    public static function fields(string $section): array
    {
        return array_merge(...array_values(self::schema()[$section]['groups'] ?? [[]]));
    }

    public static function sections(): array
    {
        return array_keys(self::schema());
    }

    /** @return array<string, mixed> */
    public function section(string $section): array
    {
        $saved = $this->saved()[$section] ?? [];
        $out = [];
        foreach (self::fields($section) as $key => $field) {
            $out[$key] = array_key_exists($key, $saved) ? $saved[$key] : $field['default'];
        }

        return $out;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        [$section, $field] = array_pad(explode('.', $key, 2), 2, null);

        return $field === null ? $this->section($section) : Arr::get($this->section($section), $field, $default);
    }

    public function enabled(string $key): bool
    {
        return (bool) $this->get($key);
    }

    public function siteName(): string
    {
        return (string) ($this->get('general.site_name') ?: 'Maks International');
    }

    public function logoUrl(): string
    {
        return $this->imageUrl($this->get('general.logo')) ?? asset('images/logo.png');
    }

    public function logoMarkUrl(int $size = 48): string
    {
        $allowed = Favicon::sizes();
        $mark = 48;
        foreach ($allowed as $candidate) {
            if ($candidate >= $size) {
                $mark = $candidate;
                break;
            }
            $mark = $candidate;
        }

        return route('favicon.png', ['size' => $mark, 'v' => Favicon::version()]);
    }

    public function imageUrl(?string $path): ?string
    {
        if (! $path) {
            return null;
        }

        return str_starts_with($path, 'http://') || str_starts_with($path, 'https://') ? $path : asset('storage/'.$path);
    }

    public function telLink(?string $phone): ?string
    {
        $digits = preg_replace('/[^\d+]/', '', (string) $phone);

        return $digits ? 'tel:'.$digits : null;
    }

    public function whatsappLink(?string $phone): ?string
    {
        $digits = preg_replace('/\D/', '', (string) $phone);
        if (! $digits) {
            return null;
        }
        if (str_starts_with($digits, '01')) {
            $digits = '88'.$digits;
        }

        return 'https://wa.me/'.$digits;
    }

    public function maksGadgetUrl(): string
    {
        $configured = trim((string) ($this->get('general.maks_gadget_url') ?: ''));
        if ($configured !== '') {
            return $configured;
        }

        // Env override only when explicitly set (blank = keep Order on this site).
        $env = trim((string) config('services.maks_gadget.url', ''));

        return $env;
    }

    public function productOrderUrl(?Product $product = null): string
    {
        $external = $this->maksGadgetUrl();
        if ($external !== '') {
            return $external;
        }

        return route('site.partner', array_filter([
            'product' => $product?->id,
        ]));
    }

    public function orderOpensExternally(): bool
    {
        return $this->maksGadgetUrl() !== '';
    }

    /** Live numbers for the home page, straight from admin data. */
    public function stats(): array
    {
        try {
            return [
                ['value' => Shop::query()->where('status', Shop::STATUS_ACTIVE)->count(), 'label' => 'Partner shops'],
                ['value' => Product::query()->count(), 'label' => 'Products'],
                ['value' => Category::query()->where('is_active', true)->count(), 'label' => 'Categories'],
                ['value' => Warehouse::query()->where('is_active', true)->count(), 'label' => 'Warehouses'],
            ];
        } catch (Throwable) {
            return [];
        }
    }

    /** Active categories that currently have products. */
    public function categories(int $limit = 8): Collection
    {
        try {
            return Category::query()
                ->where('is_active', true)
                ->whereHas('products')
                ->withCount('products')
                ->orderBy('sort_order')
                ->orderBy('name')
                ->limit($limit)
                ->get();
        } catch (Throwable) {
            return collect();
        }
    }

    /**
     * Every product in the catalogue, for public visitors.
     *
     * @return Collection<int, Product>
     */
    public function catalogProducts(?int $categoryId = null, ?string $search = null, ?int $limit = null): Collection
    {
        try {
            $query = Product::query()
                ->with(['category', 'brand', 'images'])
                ->when($categoryId, fn ($q) => $q->where('category_id', $categoryId))
                ->when($search, function ($q) use ($search) {
                    $q->where(function ($inner) use ($search) {
                        $inner->where('name', 'like', '%'.$search.'%')
                            ->orWhere('sku', 'like', '%'.$search.'%');
                    });
                })
                ->orderBy('name');

            if ($limit) {
                $query->limit($limit);
            }

            return $query->get();
        } catch (Throwable) {
            return collect();
        }
    }

    /**
     * Save one section. Images arrive as UploadedFile (new), null + $remove (cleared) or are left untouched.
     *
     * @param  array<string, mixed>  $data
     * @param  list<string>  $remove  image fields to clear
     */
    public function save(string $section, array $data, ?User $actor = null, array $remove = []): SiteSetting
    {
        $current = $this->section($section);
        $content = [];

        foreach (self::fields($section) as $key => $field) {
            $value = $data[$key] ?? null;
            $content[$key] = match ($field['type']) {
                'toggle' => (bool) $value,
                'image' => $this->storeImage($value, $current[$key], in_array($key, $remove, true), $field['default']),
                'list' => array_values(array_filter(array_map(fn ($v) => trim((string) $v), (array) $value), fn ($v) => $v !== '')),
                'items' => $this->cleanItems((array) $value, ! empty($field['icons'])),
                default => trim((string) $value),
            };
        }

        $setting = SiteSetting::query()->updateOrCreate(['section' => $section], ['content' => $content, 'updated_by' => $actor?->id]);
        $this->forget();

        return $setting;
    }

    public function reset(string $section): void
    {
        $saved = $this->saved()[$section] ?? [];
        foreach (self::fields($section) as $key => $field) {
            if ($field['type'] === 'image' && ! empty($saved[$key])) {
                $this->deleteLocalImage($saved[$key]);
            }
        }

        SiteSetting::query()->where('section', $section)->delete();
        $this->forget();
    }

    public function lastUpdated(string $section): ?SiteSetting
    {
        try {
            return SiteSetting::query()->with('editor')->where('section', $section)->first();
        } catch (Throwable) {
            return null;
        }
    }

    public function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
        $this->saved = null;
        Favicon::forget();
    }

    /** @return array<string, array<string, mixed>> */
    private function saved(): array
    {
        if ($this->saved !== null) {
            return $this->saved;
        }

        try {
            return $this->saved = Cache::rememberForever(self::CACHE_KEY, fn () => SiteSetting::query()->get()
                ->mapWithKeys(fn (SiteSetting $s) => [$s->section => (array) $s->content])
                ->all());
        } catch (Throwable) {
            // Table not migrated yet — fall back to defaults.
            return $this->saved = [];
        }
    }

    private function storeImage(mixed $upload, ?string $current, bool $remove, ?string $default): ?string
    {
        if ($upload instanceof UploadedFile) {
            $this->deleteLocalImage($current);

            return $upload->store('site', 'public');
        }

        if ($remove) {
            $this->deleteLocalImage($current);

            return null;
        }

        return $current ?? $default;
    }

    private function deleteLocalImage(?string $path): void
    {
        if ($path && ! str_starts_with($path, 'http') && str_starts_with($path, 'site/')) {
            Storage::disk('public')->delete($path);
        }
    }

    /** @return list<array<string, string>> */
    private function cleanItems(array $items, bool $icons): array
    {
        return collect($items)
            ->map(fn ($item) => [
                'icon' => $icons ? (array_key_exists($item['icon'] ?? '', self::ICONS) ? $item['icon'] : 'check') : null,
                'title' => trim((string) ($item['title'] ?? '')),
                'text' => trim((string) ($item['text'] ?? '')),
            ])
            ->filter(fn ($item) => $item['title'] !== '' || $item['text'] !== '')
            ->map(fn ($item) => array_filter($item, fn ($v) => $v !== null))
            ->values()
            ->all();
    }
}
