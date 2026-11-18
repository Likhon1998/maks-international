@extends('field.layouts.app')
@php
    $s = $order->simpleStatus();
    $phone = preg_replace('/[^0-9+]/', '', (string) $order->shop?->phone);
    $steps = [1 => 'Sent', 2 => 'Approved', 3 => 'Delivered'];
    $settle = $order->settlement();
    // Collectible now = remaining_due − pending (office not yet confirmed).
    $collectNow = (float) ($collectibleBalance ?? $settle['baki']);
    // Still open on the invoice / ledger (includes money waiting confirm).
    $openOnBill = (float) $settle['remaining_due'];
    $pendingAmt = (float) ($settle['pending_sales'] ?? 0);
    $paidSoFar = (float) ($settle['paid_to_date'] ?? 0);
    $prevBaki = $order->previousShopBaki();
    $totalCollect = round($collectNow + $prevBaki, 2);
@endphp
@section('title', 'Order '.$order->number)
@section('heading', 'Order '.$order->number)
@section('subheading', $order->shop?->name)
@section('back', route('field.orders'))
@section('content')
    <div class="f-od">
        <div class="f-od-main">
            <div class="f-od-hero">
                <div class="f-od-hero-top">
                    <div>
                        <div class="f-muted f-small">This order</div>
                        <div class="f-od-amt">{{ \App\Support\DemoData::taka($order->total) }}</div>
                        <div class="f-muted f-small">{{ $order->item_count }} pcs · {{ $order->submitted_at?->format('d M, g:i A') }}</div>
                    </div>
                    <span class="f-status {{ $s['tone'] }}">{{ $s['label'] }}</span>
                </div>
                @if ($s['step'] > 0)
                    <div class="f-od-steps" style="--done: {{ $s['step'] >= 3 ? 100 : ($s['step'] - 1) / 2 * 100 }}%">
                        <div class="bar"></div>
                        @foreach ($steps as $n => $label)
                            <div class="f-step {{ $n < $s['step'] || ($s['step'] === 3 && $n === 3) ? 'done' : ($n === $s['step'] ? 'now' : '') }}">
                                <span class="dot"></span>{{ $label }}
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>

            @if ($order->status === \App\Models\Order::STATUS_REJECTED && $order->rejection_reason)
                <div class="f-flash err"><i data-lucide="circle-x"></i><span><strong>Rejected:</strong> {{ $order->rejection_reason }}</span></div>
            @elseif ($order->status === \App\Models\Order::STATUS_CANCELLED && $order->cancellation_reason)
                <div class="f-flash err"><i data-lucide="ban"></i><span><strong>Cancelled:</strong> {{ $order->cancellation_reason }}</span></div>
            @elseif ($pendingAmt > 0.009 && $collectNow > 0.009)
                <div class="f-warn">{{ __('field.collected_waiting_still_collect', [
                    'amount' => \App\Support\DemoData::taka($pendingAmt),
                    'left' => \App\Support\DemoData::taka($collectNow),
                ]) }}</div>
            @elseif ($pendingAmt > 0.009)
                <div class="f-warn">{{ __('field.collected_waiting_office', ['amount' => \App\Support\DemoData::taka($pendingAmt)]) }}</div>
            @elseif ($settle['fully_settled'])
                <div class="f-flash ok"><i data-lucide="check-circle-2"></i><span>Paid in full.</span></div>
            @endif

            @if ($collectNow > 0.009 && $order->canCollectNow())
                <form id="collect-bill" class="f-od-pay" method="post" action="{{ route('field.orders.collect', $order) }}"
                      x-data="{
                          due: {{ json_encode($collectNow) }},
                          prev: {{ json_encode($prevBaki) }},
                          paidNow: {{ json_encode((float) old('amount', $collectNow)) }},
                          payMethod: {{ json_encode(old('method', 'cash')) }},
                          labels: {
                              enter: {{ json_encode(__('field.enter_amount')) }},
                              all: {{ json_encode(__('field.collect_all')) }},
                              collect: {{ json_encode(__('field.collect')) }}
                          },
                          taka(n) { return '৳ ' + Math.round(Number(n) || 0).toLocaleString('en-IN'); },
                          left() { return Math.max(0, Math.round((this.due - Number(this.paidNow || 0)) * 100) / 100); },
                          totalLeft() { return Math.max(0, Math.round((this.left() + Number(this.prev || 0)) * 100) / 100); }
                      }">
                    @csrf
                    <div class="f-od-pay-head">
                        <strong>{{ __('field.payment_at_shop') }}</strong>
                        <span>{{ __('field.this_order_due_collect', ['amount' => \App\Support\DemoData::taka($collectNow)]) }}</span>
                    </div>
                    @if ($pendingAmt > 0.009)
                        <p class="f-muted f-small" style="margin:0">{{ __('field.office_confirming', ['amount' => \App\Support\DemoData::taka($pendingAmt)]) }}</p>
                    @endif
                    <div class="f-pay-seg" role="group" aria-label="{{ __('field.collect') }}">
                        <button type="button" :class="Number(paidNow) === Math.round(due) ? 'active' : ''" @click="paidNow = Math.round(due)">{{ __('field.pay_all') }}</button>
                        <button type="button" :class="due > 0 && Number(paidNow) === Math.round(due / 2) ? 'active' : ''" @click="paidNow = Math.round(due / 2)">{{ __('field.half_now') }}</button>
                        <button type="button" :class="Number(paidNow) === 0 ? 'active' : ''" @click="paidNow = 0">{{ __('field.clear') }}</button>
                    </div>
                    <div class="f-od-pay-row">
                        <div class="f-money f-money-sm">
                            <span>৳</span>
                            <input id="collect-amount" type="number" inputmode="decimal" min="0" step="1" name="amount" x-model.number="paidNow" :max="Math.round(due)" placeholder="0">
                        </div>
                        <button class="f-btn f-btn-primary f-btn-sm" type="submit" :disabled="Number(paidNow) <= 0 || Number(paidNow) > due + 0.01">
                            <span x-text="Number(paidNow) <= 0 ? labels.enter : (left() > 0.009 ? labels.collect + ' ' + taka(paidNow) : labels.all)"></span>
                        </button>
                    </div>
                    <div class="f-od-pay-left">
                        <div><span>{{ __('field.left_to_collect') }}</span><b x-text="taka(left())"></b></div>
                        @if ($prevBaki > 0.009)
                            <div class="is-total-baki"><span>{{ __('field.total_baki') }}</span><b x-text="taka(totalLeft())"></b></div>
                        @endif
                    </div>
                    <template x-if="Number(paidNow) > 0">
                        <div class="f-od-pay-how">
                            <select id="collect-method" class="f-input" name="method" x-model="payMethod" aria-label="{{ __('field.method') }}">
                                <option value="cash">{{ __('field.cash') }}</option>
                                <option value="mobile_banking">{{ __('field.mobile_banking') }}</option>
                                <option value="bank_transfer">{{ __('field.bank_transfer') }}</option>
                                <option value="cheque">{{ __('field.cheque') }}</option>
                            </select>
                            <input id="collect-ref" class="f-input" type="text" name="reference" value="{{ old('reference') }}" maxlength="120" placeholder="{{ __('field.reference_optional') }}">
                        </div>
                    </template>
                </form>
            @elseif ($collectNow > 0.009)
                <div class="f-od-pay f-od-pay-wait">
                    <div class="f-od-pay-head">
                        <strong>Payment after delivery</strong>
                        <span>This order is {{ \App\Support\DemoData::taka($collectNow) }} baki. Collect when the goods are delivered.</span>
                    </div>
                </div>
            @endif

            @if ($order->fieldCanMarkDelivered(auth()->user()))
                <form class="f-od-deliver" method="post" action="{{ route('field.orders.deliver', $order) }}"
                      onsubmit="return confirm('Mark this order as delivered?')">
                    @csrf
                    <div>
                        <strong>Deliver</strong>
                        <span class="f-muted f-small">Assigned to you. Or open the delivery for receive / quantities.</span>
                    </div>
                    <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
                        <button class="f-btn f-btn-primary f-btn-sm" type="submit">Mark delivered</button>
                        @if ($order->delivery)
                            <a class="f-btn f-btn-ghost f-btn-sm" href="{{ route('field.deliveries.show', $order->delivery) }}">Open delivery</a>
                        @endif
                    </div>
                </form>
            @elseif ($order->canDeliver())
                <div class="f-od-deliver">
                    <div>
                        <strong>Delivery</strong>
                        @if (! $order->delivery)
                            <span class="f-muted f-small">Waiting for warehouse dispatch and admin assignment.</span>
                        @elseif (! $order->delivery->assigned_to)
                            <span class="f-muted f-small">Dispatched — waiting for admin to assign a delivery man.</span>
                        @else
                            <span class="f-muted f-small">Assigned to {{ $order->delivery->assignee?->name ?: 'another rider' }}. You can track the order here; delivery actions are on their panel.</span>
                        @endif
                    </div>
                </div>
            @endif

            <section class="f-od-sheet">
                <div class="f-od-kicker">Items</div>
                @foreach ($order->items as $item)
                    <div class="f-line-item">
                        <span>
                            {{ $item->product_name }}
                            <span class="f-muted">× {{ $item->quantity }} · {{ \App\Support\DemoData::taka($item->unit_price) }}</span>
                            @if ($item->wasPriceChanged())
                                <span class="f-muted"> · list {{ \App\Support\DemoData::taka($item->originalUnitPrice()) }}</span>
                            @endif
                        </span>
                        <span>{{ \App\Support\DemoData::taka($item->line_total) }}</span>
                    </div>
                @endforeach
                <div class="f-od-totals">
                    @if ((float) $order->discount_total > 0)
                        <div><span>Subtotal</span><span>{{ \App\Support\DemoData::taka($order->subtotal) }}</span></div>
                        <div><span>Discount</span><span>− {{ \App\Support\DemoData::taka($order->discount_total) }}</span></div>
                    @endif
                    <div class="is-total"><span>This order</span><strong>{{ \App\Support\DemoData::taka($order->total) }}</strong></div>
                    @if ($paidSoFar > 0.009)
                        <div><span>{{ __('field.paid_so_far') }}</span><span>{{ \App\Support\DemoData::taka($paidSoFar) }}</span></div>
                    @endif
                    @if ($pendingAmt > 0.009)
                        <div><span>{{ __('field.waiting_confirm') }}</span><span>{{ \App\Support\DemoData::taka($pendingAmt) }}</span></div>
                    @endif
                    @if ($collectNow > 0.009)
                        <div class="is-baki"><span>{{ __('field.collect_now') }}</span><span>{{ \App\Support\DemoData::taka($collectNow) }}</span></div>
                    @endif
                    @if ($pendingAmt > 0.009 && $openOnBill > 0.009)
                        <div><span>{{ __('field.open_on_bill') }}</span><span>{{ \App\Support\DemoData::taka($openOnBill) }}</span></div>
                    @elseif ($collectNow <= 0.009 && $openOnBill > 0.009)
                        <div class="is-baki"><span>{{ __('field.this_order_baki') }}</span><span>{{ \App\Support\DemoData::taka($openOnBill) }}</span></div>
                    @endif
                    @if ($prevBaki > 0.009)
                        <div class="is-baki"><span>{{ __('field.previous_shop_baki') }}</span><span>{{ \App\Support\DemoData::taka($prevBaki) }}</span></div>
                    @endif
                    @if ($totalCollect > 0.009 && $prevBaki > 0.009)
                        <div class="is-grand is-baki"><span>{{ __('field.total_baki') }}</span><strong>{{ \App\Support\DemoData::taka($totalCollect) }}</strong></div>
                    @endif
                </div>

                @if ($order->canApplyDiscount())
                    <form class="f-od-disc" method="post" action="{{ route('field.orders.discount', $order) }}"
                          x-data="{ extra: 0, current: {{ json_encode((float) $order->discount_total) }}, subtotal: {{ json_encode((float) $order->subtotal) }},
                              add(n) { this.extra = Math.min(this.subtotal - this.current, Math.round((Number(this.extra) || 0) + n)); } }">
                        @csrf
                        <input type="hidden" name="add" value="1">
                        <div class="f-od-disc-label">
                            <strong>Reduce total</strong>
                            @if ((float) $order->discount_total > 0.009)
                                <span>Already −{{ \App\Support\DemoData::taka($order->discount_total) }}</span>
                            @endif
                        </div>
                        <div class="f-disc-add" role="group" aria-label="Add more discount">
                            <button type="button" @click="add(50)">+50</button>
                            <button type="button" @click="add(100)">+100</button>
                            <button type="button" @click="add(200)">+200</button>
                        </div>
                        <div class="f-od-pay-row">
                            <div class="f-money f-money-sm">
                                <span>৳</span>
                                <input id="field-discount" type="number" name="discount_total" min="0" step="1" :max="Math.round(subtotal - current)" x-model.number="extra" placeholder="Add more">
                            </div>
                            <button class="f-btn f-btn-primary f-btn-sm" type="submit" :disabled="Number(extra) <= 0">Add discount</button>
                        </div>
                    </form>
                @endif
            </section>
        </div>

        <aside class="f-od-side">
            <div class="f-od-shop">
                <div class="f-od-kicker">Shop</div>
                <div class="f-od-shop-name">{{ $order->shop?->name }}</div>
                @if ($order->shop?->owner_name)<div class="f-kv"><span>Owner</span><span>{{ $order->shop->owner_name }}</span></div>@endif
                @if ($order->shop?->phone)<div class="f-kv"><span>Phone</span><span>{{ $order->shop->phone }}</span></div>@endif
                @if ($order->shop?->city)<div class="f-kv"><span>Area</span><span>{{ $order->shop->city }}</span></div>@endif
                @if ($paidSoFar > 0.009)
                    <div class="f-kv"><span>{{ __('field.paid_so_far') }}</span><span>{{ \App\Support\DemoData::taka($paidSoFar) }}</span></div>
                @endif
                @if ($pendingAmt > 0.009)
                    <div class="f-kv"><span>{{ __('field.waiting_confirm') }}</span><span>{{ \App\Support\DemoData::taka($pendingAmt) }}</span></div>
                @endif
                @if ($collectNow > 0.009)
                    <div class="f-kv"><span>{{ __('field.collect_now') }}</span><span class="is-debt">{{ \App\Support\DemoData::taka($collectNow) }}</span></div>
                @endif
                @if ($pendingAmt > 0.009 && $openOnBill > 0.009)
                    <div class="f-kv"><span>{{ __('field.open_on_bill') }}</span><span>{{ \App\Support\DemoData::taka($openOnBill) }}</span></div>
                @elseif ($collectNow <= 0.009 && $openOnBill > 0.009)
                    <div class="f-kv"><span>{{ __('field.this_order_baki') }}</span><span class="is-debt">{{ \App\Support\DemoData::taka($openOnBill) }}</span></div>
                @endif
                @if ($prevBaki > 0.009)
                    <div class="f-kv"><span>{{ __('field.previous_baki') }}</span><span class="is-debt">{{ \App\Support\DemoData::taka($prevBaki) }}</span></div>
                @endif
                @if ($totalCollect > 0.009 && $prevBaki > 0.009)
                    <div class="f-kv"><span>{{ __('field.total_baki') }}</span><span class="is-debt">{{ \App\Support\DemoData::taka($totalCollect) }}</span></div>
                @endif
                <div class="f-od-shop-act">
                    @if ($phone)
                        <a class="f-btn f-btn-soft f-btn-sm" href="tel:{{ $phone }}"><i data-lucide="phone"></i> Call shop</a>
                    @endif
                    <a class="f-btn f-btn-ghost f-btn-sm" href="{{ route('field.shops') }}"><i data-lucide="store"></i> My shops</a>
                </div>
            </div>
            @if ($order->notes)
                <div class="f-od-shop">
                    <div class="f-od-kicker">Note</div>
                    <div class="f-od-note">{{ $order->notes }}</div>
                </div>
            @endif
        </aside>
    </div>
@endsection
