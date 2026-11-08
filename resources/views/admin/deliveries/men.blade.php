@extends('layouts.app')
@section('title', 'Delivery men')
@section('content')
    <x-page-header title="Delivery men" subtitle="Active field riders available for manual order assignment.">
        <a class="btn btn-ghost" href="{{ route('deliveries.index') }}">All deliveries</a>
    </x-page-header>

    <div class="card">
        <div class="table-wrap">
            <table class="data">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Phone</th>
                        <th>Assigned</th>
                        <th>Out for delivery</th>
                        <th>Failed</th>
                        <th>Open total</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($roster as $man)
                        <tr>
                            <td style="font-weight:700">{{ $man['name'] }}</td>
                            <td>{{ $man['phone'] ?: '—' }}</td>
                            <td>{{ $man['assigned'] }}</td>
                            <td>{{ $man['out'] }}</td>
                            <td>{{ $man['failed'] }}</td>
                            <td>{{ $man['open'] }}</td>
                            <td>
                                <a class="link" href="{{ route('deliveries.index', ['assigned_to' => $man['id']]) }}">View orders</a>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="muted" style="padding:24px;text-align:center">No active delivery men found. Add salesman-portal users to assign deliveries.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
@endsection
