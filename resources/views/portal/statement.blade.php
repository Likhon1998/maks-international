@extends('portal.layouts.app')
@section('title', 'Account statement')
@section('content')
    <x-page-header title="Account statement" subtitle="{{ $shop->name }} · purchases, payments and baki" />

    @if ((float) $summary['outstanding'] > 0.009)
        <div class="stmt-alert">
            Reminder: your current baki is <strong>{{ \App\Support\DemoData::taka($summary['outstanding']) }}</strong>. This is unpaid from delivered orders.
        </div>
    @endif

    @include('partials.shop-statement-ledger', [
        'summary' => $summary,
        'entries' => $entries,
        'showShop' => false,
    ])

    @if (($receipts ?? collect())->isNotEmpty())
        <div class="card stmt-card" style="margin-top:14px">
            <div class="stmt-card-head">
                <div>
                    <h2 class="stmt-card-title">Money you paid</h2>
                    <p class="stmt-card-sub">Receipts collected from this shop</p>
                </div>
            </div>
            <div class="table-wrap stmt-wrap">
                <table class="stmt-table">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th class="num">Amount</th>
                            <th>Method</th>
                            <th>Collected by</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($receipts as $payment)
                            <tr>
                                <td class="stmt-date">{{ $payment->paid_at?->format('d M Y') ?: $payment->created_at?->format('d M Y') }}</td>
                                <td class="num stmt-money is-credit">{{ \App\Support\DemoData::taka($payment->amount) }}</td>
                                <td>{{ $payment->methodLabel() }}</td>
                                <td>{{ $payment->creator?->name ?: 'Office' }}</td>
                                <td class="stmt-status"><x-badge :status="$payment->statusLabel()" /></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif
@endsection
