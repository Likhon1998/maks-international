<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Dashboard') · {{ $site->siteName() }}</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="{{ asset('css/admin.css') }}?v={{ @filemtime(public_path('css/admin.css')) }}">
    @include('partials.favicon')
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.7/dist/chart.umd.min.js"></script>
    <script src="https://unpkg.com/lucide@0.469.0"></script>
    <style>[x-cloak]{display:none !important}</style>
    @stack('head')
    {{-- Register before Alpine loads so adminShell exists on boot --}}
    <script>
        document.addEventListener('alpine:init', () => {
            Alpine.data('adminShell', () => {
                const stored = localStorage.getItem('bt_sidebar_v2');
                return {
                    isDesktop: window.innerWidth >= 1025,
                    sidebarOpen: window.innerWidth >= 1025,
                    sidebarExpanded: stored !== '0',
                    commandOpen: false,
                    notifsOpen: false,
                    toggleSidebar() {
                        if (!this.isDesktop) {
                            this.sidebarOpen = !this.sidebarOpen;
                            return;
                        }
                        this.sidebarExpanded = !this.sidebarExpanded;
                        localStorage.setItem('bt_sidebar_v2', this.sidebarExpanded ? '1' : '0');
                        this.$nextTick(() => { if (window.lucide) lucide.createIcons(); });
                    },
                    expandSidebar() {
                        this.sidebarExpanded = true;
                        localStorage.setItem('bt_sidebar_v2', '1');
                        this.$nextTick(() => { if (window.lucide) lucide.createIcons(); });
                    },
                    onResize() {
                        this.isDesktop = window.innerWidth >= 1025;
                        if (this.isDesktop) this.sidebarOpen = true;
                    },
                };
            });

            Alpine.data('productGallery', (config = {}) => ({
                max: config.max || 12,
                existing: Array.isArray(config.existing) ? config.existing.map((img) => ({
                    id: img.id,
                    url: img.url,
                    primary: !!img.primary,
                    remove: false,
                })) : [],
                pending: [],
                error: '',
                focusUrl: '',
                get kept() {
                    return this.existing.filter((img) => !img.remove);
                },
                get count() {
                    return this.kept.length + this.pending.length;
                },
                get cover() {
                    return this.kept.find((img) => img.primary) || this.kept[0] || null;
                },
                get coverId() {
                    return this.cover ? this.cover.id : '';
                },
                get stageUrl() {
                    if (this.focusUrl) {
                        const stillThere = this.kept.some((img) => img.url === this.focusUrl)
                            || this.pending.some((item) => item.url === this.focusUrl);
                        if (stillThere) return this.focusUrl;
                    }
                    if (this.cover) return this.cover.url;
                    return this.pending[0]?.url || '';
                },
                get stageIsCover() {
                    return !!(this.cover && this.stageUrl === this.cover.url);
                },
                get removedIds() {
                    return this.existing.filter((img) => img.remove).map((img) => img.id);
                },
                preview(url) {
                    this.focusUrl = url || '';
                },
                add(event) {
                    const incoming = [...(event.target.files || [])];
                    event.target.value = '';
                    this.error = '';
                    incoming.forEach((file) => {
                        if (this.count >= this.max) {
                            this.error = 'You can add up to ' + this.max + ' pictures.';
                            return;
                        }
                        if (!String(file.type || '').startsWith('image/')) {
                            this.error = file.name + ' is not an image.';
                            return;
                        }
                        if (file.size > 4 * 1024 * 1024) {
                            this.error = file.name + ' is larger than 4 MB.';
                            return;
                        }
                        this.pending.push({
                            key: [file.name, file.size, file.lastModified, Math.random()].join('-'),
                            file,
                            url: URL.createObjectURL(file),
                            name: file.name,
                        });
                    });
                    this.syncInput();
                },
                removeExisting(img) {
                    img.remove = !img.remove;
                    if (img.remove && img.primary) {
                        img.primary = false;
                        if (this.kept[0]) this.kept[0].primary = true;
                    }
                },
                removePending(index) {
                    const item = this.pending[index];
                    if (item?.url) URL.revokeObjectURL(item.url);
                    this.pending.splice(index, 1);
                    this.error = '';
                    this.syncInput();
                },
                setCover(img) {
                    if (img.remove) return;
                    this.existing.forEach((item) => { item.primary = item.id === img.id; });
                    this.preview(img.url);
                },
                syncInput() {
                    const input = this.$refs.fileInput;
                    if (!input || !window.DataTransfer) return;
                    const transfer = new DataTransfer();
                    this.pending.forEach((item) => transfer.items.add(item.file));
                    input.files = transfer.files;
                },
            }));

            Alpine.data('catalogLogo', (config = {}) => ({
                existing: config.url || '',
                preview: config.url || '',
                removed: false,
                pick(event) {
                    const file = event.target.files?.[0];
                    if (!file) return;
                    if (this.preview && this.preview !== this.existing) {
                        URL.revokeObjectURL(this.preview);
                    }
                    this.removed = false;
                    this.preview = URL.createObjectURL(file);
                    this.$nextTick(() => window.lucide && lucide.createIcons());
                },
                clear() {
                    if (this.preview && this.preview !== this.existing) {
                        URL.revokeObjectURL(this.preview);
                    }
                    this.removed = true;
                    this.preview = '';
                    if (this.$refs.file) this.$refs.file.value = '';
                    this.$nextTick(() => window.lucide && lucide.createIcons());
                },
            }));

            Alpine.data('quickCatalogSelect', (config = {}) => ({
                selected: config.selected ?? '',
                options: Array.isArray(config.options) ? [...config.options] : [],
                storeUrl: config.storeUrl || '',
                canCreate: !!config.canCreate,
                label: config.label || 'item',
                previous: config.selected ?? '',
                adding: false,
                newName: '',
                logoFile: null,
                logoPreview: '',
                saving: false,
                error: '',
                startAdd() {
                    this.error = '';
                    this.adding = true;
                    this.$nextTick(() => this.$el.querySelector('.catalog-quick-form input')?.focus());
                },
                pickLogo(event) {
                    const file = event.target.files?.[0];
                    if (this.logoPreview) URL.revokeObjectURL(this.logoPreview);
                    this.logoFile = file || null;
                    this.logoPreview = file ? URL.createObjectURL(file) : '';
                },
                clearLogo() {
                    if (this.logoPreview) URL.revokeObjectURL(this.logoPreview);
                    this.logoFile = null;
                    this.logoPreview = '';
                    if (this.$refs.logo) this.$refs.logo.value = '';
                },
                onChange() {
                    this.error = '';
                    if (this.selected === '__new__') {
                        this.selected = this.previous || '';
                        this.startAdd();
                        return;
                    }
                    this.previous = this.selected;
                    this.adding = false;
                },
                cancel() {
                    this.adding = false;
                    this.newName = '';
                    this.clearLogo();
                    this.error = '';
                    this.selected = this.previous || '';
                },
                async create() {
                    const name = (this.newName || '').trim();
                    if (!name) {
                        this.error = 'Enter a ' + this.label + ' name.';
                        return;
                    }
                    this.saving = true;
                    this.error = '';
                    try {
                        const body = new FormData();
                        body.append('name', name);
                        if (this.logoFile) body.append('logo', this.logoFile);
                        const res = await fetch(this.storeUrl, {
                            method: 'POST',
                            headers: {
                                'Accept': 'application/json',
                                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                                'X-Requested-With': 'XMLHttpRequest',
                            },
                            body,
                        });
                        const data = await res.json().catch(() => ({}));
                        if (!res.ok) {
                            const first = data?.errors ? Object.values(data.errors).flat()[0] : null;
                            throw new Error(first || data?.message || ('Could not create ' + this.label));
                        }
                        const id = String(data.id);
                        if (!this.options.some((opt) => String(opt.id) === id)) {
                            this.options.push({ id: data.id, name: data.name });
                            this.options.sort((a, b) => String(a.name).localeCompare(String(b.name)));
                        }
                        this.adding = false;
                        this.newName = '';
                        this.clearLogo();
                        this.$nextTick(() => {
                            this.selected = id;
                            this.previous = id;
                        });
                    } catch (e) {
                        this.error = e.message || ('Could not create ' + this.label);
                    } finally {
                        this.saving = false;
                    }
                },
            }));
        });
    </script>
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.14.3/dist/cdn.min.js"></script>
</head>
<body x-data="adminShell"
      @keydown.ctrl.k.prevent="commandOpen = true"
      @keydown.meta.k.prevent="commandOpen = true"
      @keydown.escape="commandOpen = false; notifsOpen = false"
      @resize.window="onResize()">
    <div class="overlay" :class="sidebarOpen && !isDesktop && 'show'" @click="sidebarOpen = false"></div>
    <div class="app-shell">
        @include('layouts.partials.sidebar')
        <div class="main">
            @include('layouts.partials.header')
            <main class="page">
                @yield('content')
            </main>
        </div>
    </div>

    <div x-show="commandOpen" x-cloak class="overlay show" @click="commandOpen = false" style="display:block">
        <div class="card" style="max-width:560px;margin:12vh auto;padding:8px" @click.stop>
            <input class="input" style="width:100%;height:48px;border:0;font-size:15px" placeholder="Search shops, orders, shipments..." autofocus>
            <div style="padding:8px 12px" class="muted">Quick links</div>
            @foreach (['Dashboard' => 'dashboard', 'Orders' => 'orders.index', 'Shipments' => 'shipments.index', 'Inventory' => 'inventory.index', 'Users & Roles' => 'users.index'] as $label => $route)
                <a href="{{ route($route) }}" style="display:block;padding:10px 12px;border-radius:8px" onmouseover="this.style.background='#f8f9fd'">{{ $label }}</a>
            @endforeach
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', () => {
            if (window.lucide) lucide.createIcons();
        });
    </script>
    @stack('scripts')
</body>
</html>
