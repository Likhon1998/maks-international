@extends('layouts.app')
@section('title', 'Brands')
@section('content')
    <x-page-header title="Brands" subtitle="Product brands">
        <x-slot:description>Create, edit or delete brands used on products. Each brand can have a logo.</x-slot:description>
        <a class="btn btn-ghost" href="{{ route('products.create') }}">Add product</a>
        <a class="btn btn-ghost" href="{{ route('categories.index') }}">Categories</a>
    </x-page-header>

    @if (session('success'))
        <div class="card" style="padding:12px 16px;margin-bottom:14px;background:#e8f8ee;color:#15803d">{{ session('success') }}</div>
    @endif
    @if ($errors->any())
        <div class="card" style="padding:12px 16px;margin-bottom:14px;background:#fef2f2;color:#b91c1c">{{ $errors->first() }}</div>
    @endif

    <div class="grid-2">
        @can('brands.create')
            <div class="card" style="padding:18px">
                <div class="section-title" style="margin-bottom:12px">Add brand</div>
                <form action="{{ route('brands.store') }}" method="post" enctype="multipart/form-data">
                    @csrf
                    <label class="label">Name</label>
                    <input class="input" style="width:100%;margin-bottom:10px" name="name" value="{{ old('name') }}" required>
                    <x-catalog-logo-field />
                    <button class="btn btn-primary" style="margin-top:12px" type="submit">Create</button>
                </form>
            </div>
        @endcan

        <div class="card">
            <div class="table-wrap">
                <table class="data">
                    <thead><tr><th>Brand</th><th class="num">Products</th><th>Status</th><th class="actions"></th></tr></thead>
                    <tbody>
                        @forelse ($brands as $brand)
                            <tr x-data="{ editing: false }">
                                <td style="min-width:240px">
                                    <div x-show="!editing" class="cat-logo-name">
                                        <span class="cat-logo-thumb">
                                            @if ($brand->logoUrl())
                                                <img src="{{ $brand->logoUrl() }}" alt="">
                                            @else
                                                <em>{{ mb_strtoupper(mb_substr($brand->name, 0, 1)) }}</em>
                                            @endif
                                        </span>
                                        <div style="font-weight:600">{{ $brand->name }}</div>
                                    </div>
                                    @can('brands.edit')
                                        <form x-show="editing" x-cloak method="post" action="{{ route('brands.update', $brand) }}" enctype="multipart/form-data" style="display:grid;gap:8px">
                                            @csrf @method('PUT')
                                            <input class="input" name="name" value="{{ $brand->name }}" required>
                                            <x-catalog-logo-field :url="$brand->logoUrl()" />
                                            <label style="font-size:12px;display:flex;gap:6px;align-items:center">
                                                <input type="hidden" name="is_active" value="0">
                                                <input type="checkbox" name="is_active" value="1" @checked($brand->is_active)> Active
                                            </label>
                                            <div style="display:flex;gap:6px">
                                                <button class="btn btn-primary btn-sm" type="submit">Save</button>
                                                <button class="btn btn-ghost btn-sm" type="button" @click="editing=false">Cancel</button>
                                            </div>
                                        </form>
                                    @endcan
                                </td>
                                <td class="num">{{ $brand->products_count }}</td>
                                <td><x-badge :status="$brand->is_active ? 'Active' : 'On Hold'" /></td>
                                <td class="actions">
                                    <div class="row-menu" x-show="!editing" x-data="{ open: false }" @keydown.escape.window="open = false">
                                        <button type="button" class="row-menu-btn" @click="open = !open" :aria-expanded="open.toString()" title="Actions">
                                            <span aria-hidden="true">⋯</span>
                                        </button>
                                        <div class="row-menu-panel" x-cloak x-show="open" @click.outside="open = false">
                                            @can('brands.edit')
                                                <button class="row-menu-item" type="button" @click="editing = true; open = false">Edit</button>
                                            @endcan
                                            @can('brands.delete')
                                                <form method="post" action="{{ route('brands.destroy', $brand) }}"
                                                    onsubmit="return confirm('Delete this brand?')">
                                                    @csrf @method('DELETE')
                                                    <button class="row-menu-item is-danger" type="submit" @disabled($brand->products_count > 0)
                                                        title="{{ $brand->products_count > 0 ? 'Reassign products first' : 'Delete' }}">Delete</button>
                                                </form>
                                            @endcan
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="muted" style="padding:16px">No brands yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
@endsection
