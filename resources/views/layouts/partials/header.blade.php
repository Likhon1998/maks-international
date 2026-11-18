@php
    $authUser = auth()->user();
    $roleName = $authUser?->roles?->first()?->name;
    $displayName = $authUser?->name ?? 'Admin';
    $showRole = $roleName && strcasecmp($roleName, $displayName) !== 0;
@endphp
<header class="topbar">
    <div class="search-wrap">
        <button class="icon-btn" type="button" @click="toggleSidebar()" aria-label="Toggle navigation" title="Toggle sidebar">
            <i data-lucide="menu"></i>
        </button>
        <label class="search" title="Search (Ctrl+K)">
            <i data-lucide="search"></i>
            <input type="search" placeholder="Search…" @focus="commandOpen = true" aria-label="Search">
            <span class="kbd">⌘K</span>
        </label>
    </div>

    <div class="topbar-actions">
        <button class="icon-btn" type="button" @click="toggleNotifs()" aria-label="Notifications" title="Notifications" :aria-expanded="notifsOpen.toString()">
            <i data-lucide="bell"></i>
            <span class="dot-badge" x-show="unreadCount > 0" x-cloak x-text="unreadLabel"></span>
        </button>
        <div class="topbar-user" title="{{ $displayName }}{{ $showRole ? ' · '.$roleName : '' }}">
            <div class="avatar">{{ strtoupper(substr($displayName, 0, 2)) }}</div>
            <div class="topbar-user-meta">
                <span>{{ $displayName }}</span>
                @if ($showRole)
                    <em>{{ $roleName }}</em>
                @endif
            </div>
        </div>

        <div x-show="notifsOpen" x-cloak @click.outside="notifsOpen = false" class="card notif-panel">
            <div class="notif-panel-head">
                <strong>
                    Recent
                    <span class="muted" x-show="unreadCount > 0" x-cloak>(<span x-text="unreadCount"></span> unread)</span>
                </strong>
                <div class="notif-panel-links">
                    <button type="button" class="muted" x-show="unreadCount > 0" x-cloak @click="markAllNotifsRead()">Mark all</button>
                </div>
            </div>
            <template x-if="notifLoading && !notifItems.length">
                <div class="muted notif-empty">Loading…</div>
            </template>
            <template x-for="item in notifItems" :key="item.id">
                <button type="button" class="notif-item" :class="item.read ? '' : 'is-unread'" @click="openNotif(item)">
                    <div class="notif-item-title" x-text="item.title"></div>
                    <div class="muted" x-text="item.body"></div>
                    <div class="muted notif-item-meta">
                        <span x-text="item.when || ''"></span>
                        <span x-text="item.category ? (' · ' + item.category) : ''"></span>
                    </div>
                </button>
            </template>
            <div class="muted notif-empty" x-show="!notifLoading && !notifItems.length" x-cloak>No notifications yet.</div>
            <div class="notif-panel-foot">
                <a href="{{ route('notifications.index') }}" class="btn btn-ghost btn-sm" style="width:100%;justify-content:center">
                    View all notification history
                </a>
            </div>
        </div>
    </div>
</header>
