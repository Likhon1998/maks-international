@extends('layouts.app')
@section('title', 'Notifications')
@section('content')
    <x-page-header title="Notifications" subtitle="Full history · {{ $totalCount }} total · {{ $unreadCount }} unread">
        @if ($unreadCount > 0)
            <form method="post" action="{{ route('notifications.read-all') }}">
                @csrf
                <button class="btn btn-ghost btn-sm" type="submit">Mark all read</button>
            </form>
        @endif
    </x-page-header>

    @if (session('success'))
        <div class="card" style="padding:12px 16px;margin-bottom:14px;background:#e8f8ee;color:#15803d">{{ session('success') }}</div>
    @endif

    <div class="card" style="padding:12px;margin-bottom:12px">
        <form class="filters" method="get">
            <input
                class="input input-sm"
                type="search"
                name="search"
                value="{{ request('search') }}"
                placeholder="Search title or message"
                style="min-width:180px;flex:1"
            >
            <select class="select select-sm" name="filter">
                <option value="all" @selected(($filter ?? 'all') === 'all')>All</option>
                <option value="unread" @selected(($filter ?? '') === 'unread')>Unread</option>
                <option value="read" @selected(($filter ?? '') === 'read')>Read</option>
            </select>
            <button class="btn btn-ghost btn-sm" type="submit">Filter</button>
        </form>
    </div>

    @php
        $unreadIds = $notifications->getCollection()->whereNull('read_at')->pluck('id')->values()->all();
    @endphp

    <div class="card notif-history" data-unread-ids='@json($unreadIds)'>
        @forelse ($notifications as $n)
            @php
                $data = $n->data;
                $when = $n->created_at?->timezone(config('app.timezone'));
            @endphp
            <div class="notif-history-row {{ $n->read_at ? 'is-read' : 'is-unread' }}" @unless($n->read_at) data-unread-id="{{ $n->id }}" @endunless>
                <div class="notif-history-stamp">
                    <span class="notif-history-day">{{ $when?->format('D') }}</span>
                    <span class="notif-history-date">{{ $when?->format('d M Y') }}</span>
                    <span class="notif-history-time">{{ $when?->format('h:i A') }}</span>
                </div>
                <div class="notif-history-body">
                    <div class="notif-history-title">
                        {{ $data['title'] ?? 'Notification' }}
                        @unless ($n->read_at)
                            <span class="badge badge-pending">Unread</span>
                        @endunless
                    </div>
                    <div class="muted" style="font-size:13px;margin-top:2px">{{ $data['body'] ?? '' }}</div>
                    <div class="muted" style="font-size:11px;margin-top:4px">{{ $data['category'] ?? 'system' }}</div>
                </div>
                <form method="post" action="{{ route('notifications.read', $n->id) }}">
                    @csrf
                    <button class="btn btn-ghost btn-sm" type="submit">{{ $n->read_at ? 'Open' : 'Open' }}</button>
                </form>
            </div>
        @empty
            <div class="muted" style="padding:28px;text-align:center">No notifications in this filter.</div>
        @endforelse

        @if ($notifications->hasPages())
            <div style="padding:12px 16px;border-top:1px solid var(--line)">{{ $notifications->links() }}</div>
        @endif
    </div>
@endsection

@push('scripts')
<script>
(() => {
    const root = document.querySelector('.notif-history[data-unread-ids]');
    if (!root) return;
    let ids = [];
    try { ids = JSON.parse(root.getAttribute('data-unread-ids') || '[]'); } catch (e) { ids = []; }
    if (!Array.isArray(ids) || !ids.length) return;

    const token = document.querySelector('meta[name="csrf-token"]')?.content || '';
    fetch(@js(route('notifications.seen')), {
        method: 'POST',
        headers: {
            'Accept': 'application/json',
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': token,
            'X-Requested-With': 'XMLHttpRequest',
        },
        credentials: 'same-origin',
        body: JSON.stringify({ ids }),
    }).then((res) => {
        if (!res.ok) return;
        root.querySelectorAll('.notif-history-row.is-unread').forEach((row) => {
            row.classList.remove('is-unread');
            row.classList.add('is-read');
            row.querySelector('.badge-pending')?.remove();
            delete row.dataset.unreadId;
        });
        // Keep the top-bar unread badge in sync if Alpine shell is present.
        try {
            const shell = Alpine?.$data?.(document.body);
            if (shell && typeof shell.unreadCount === 'number') {
                shell.unreadCount = Math.max(0, shell.unreadCount - ids.length);
                if (Array.isArray(shell.notifItems)) {
                    const seen = new Set(ids);
                    shell.notifItems = shell.notifItems.map((n) => seen.has(n.id) ? { ...n, read: true } : n);
                }
            }
        } catch (e) {}
    }).catch(() => {});
})();
</script>
@endpush
