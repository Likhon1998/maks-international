@extends('layouts.app')

@section('title', 'Users')

@section('content')
    <x-page-header title="Users" subtitle="Accounts, roles and access scopes" action="{{ route('users.create') }}" action-label="Invite user">
        <x-slot:description>Create staff, shop owners and salesmen. Permissions are enforced on the server for every protected action.</x-slot:description>
    </x-page-header>

    @if (session('success'))
        <div class="card" style="padding:12px 16px;margin-bottom:14px;background:#e8f8ee;border-color:#bbf7d0;color:#15803d">{{ session('success') }}</div>
    @endif
    @if (session('error'))
        <div class="card" style="padding:12px 16px;margin-bottom:14px;background:#fee2e2;border-color:#fecaca;color:#b91c1c">{{ session('error') }}</div>
    @endif

    <div class="card" style="padding:14px;margin-bottom:14px">
        <form class="filters" method="get">
            <input class="input" name="search" value="{{ request('search') }}" placeholder="Search name, email or phone">
            <select class="select" name="portal">
                <option value="">All portals</option>
                @foreach (['admin' => 'Admin', 'shop' => 'Shop', 'salesman' => 'Salesman'] as $value => $label)
                    <option value="{{ $value }}" @selected(request('portal') === $value)>{{ $label }}</option>
                @endforeach
            </select>
            <select class="select" name="status">
                <option value="">All statuses</option>
                <option value="active" @selected(request('status') === 'active')>Active</option>
                <option value="inactive" @selected(request('status') === 'inactive')>Inactive</option>
            </select>
            <button class="btn btn-ghost" type="submit">Filter</button>
            <a class="btn btn-ghost" href="{{ route('roles.index') }}">Manage roles</a>
            <a class="btn btn-ghost" href="{{ route('audit.index') }}">Audit logs</a>
        </form>
    </div>

    <div class="card">
        <div class="table-wrap">
            <table class="data">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Email</th>
                        <th>Role</th>
                        <th>Portal</th>
                        <th>Access scope</th>
                        <th>Status</th>
                        <th>Last login</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($users as $user)
                        <tr>
                            <td style="font-weight:700">
                                <a class="link" href="{{ route('users.show', $user) }}">{{ $user->name }}</a>
                            </td>
                            <td>{{ $user->email }}</td>
                            <td>{{ $user->roles->pluck('name')->join(', ') ?: '—' }}</td>
                            <td>{{ ucfirst($user->portal) }}</td>
                            <td>{{ $user->scopeLabel() }}</td>
                            <td>
                                <x-badge :status="$user->is_active ? 'Active' : 'On Hold'" />
                            </td>
                            <td class="muted">{{ $user->last_login_at?->diffForHumans() ?? 'Never' }}</td>
                            <td class="actions">
                                <x-row-menu>
                                    <a class="row-menu-item" href="{{ route('users.show', $user) }}">View</a>
                                    @can('users.edit')
                                        <a class="row-menu-item" href="{{ route('users.edit', $user) }}">Edit</a>
                                    @endcan
                                    @can('users.activate')
                                        @unless ($user->isSuperAdmin())
                                            <form action="{{ route('users.toggle-active', $user) }}" method="post">
                                                @csrf
                                                <button class="row-menu-item {{ $user->is_active ? 'is-danger' : '' }}" type="submit">
                                                    {{ $user->is_active ? 'Deactivate' : 'Activate' }}
                                                </button>
                                            </form>
                                        @endunless
                                    @endcan
                                </x-row-menu>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="muted" style="padding:24px;text-align:center">No users found.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($users->hasPages())
            <div style="padding:14px">{{ $users->links() }}</div>
        @endif
    </div>
@endsection
