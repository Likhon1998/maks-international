@extends('layouts.app')
@section('title', 'Account statement · '.$shop->name)
@section('content')
    <x-page-header title="Account statement" subtitle="{{ $shop->name }} · {{ $shop->code }}">
        <a class="btn btn-ghost" href="{{ route('shops.show', $shop) }}">Back to shop</a>
        <a class="btn btn-ghost" href="{{ route('dues.index', ['shop' => $shop->name]) }}">Due Management</a>
    </x-page-header>

    @include('partials.shop-statement-ledger', [
        'summary' => $summary,
        'entries' => $entries,
        'showShop' => true,
    ])
@endsection
