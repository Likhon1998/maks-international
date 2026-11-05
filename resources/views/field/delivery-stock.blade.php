@extends('field.layouts.app')
@section('title', 'My stock')
@section('heading', 'My stock')
@section('subheading', 'Received · delivered · returned · remaining')
@section('back', route('field.deliveries'))
@section('content')
    <div class="f-kpis">
        <div class="f-kpi tone-violet"><div class="f-kpi-label">Received</div><div class="f-kpi-value">{{ $receivedTotal }}</div></div>
        <div class="f-kpi tone-green"><div class="f-kpi-label">Delivered</div><div class="f-kpi-value">{{ $deliveredTotal }}</div></div>
        <div class="f-kpi tone-sky"><div class="f-kpi-label">Returned</div><div class="f-kpi-value">{{ $returnedTotal }}</div></div>
        <div class="f-kpi tone-amber"><div class="f-kpi-label">Remaining</div><div class="f-kpi-value">{{ $remainingTotal }}</div></div>
    </div>

    <section class="f-panel">
        <div class="f-table-wrap">
            <table class="f-table">
                <thead>
                    <tr>
                        <th>Product</th>
                        <th class="num">Received</th>
                        <th class="num">Delivered</th>
                        <th class="num">Returned</th>
                        <th class="num">Remaining</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($balances as $row)
                        <tr>
                            <td>
                                <span class="strong">{{ $row['name'] }}</span>
                                <span class="sub">{{ $row['sku'] }}</span>
                            </td>
                            <td class="num">{{ $row['received'] }}</td>
                            <td class="num">{{ $row['delivered'] }}</td>
                            <td class="num">{{ $row['returned'] }}</td>
                            <td class="num" style="font-weight:800">{{ $row['remaining'] }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="f-muted" style="text-align:center;padding:20px">No delivery stock yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
@endsection
