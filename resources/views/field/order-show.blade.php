@extends('field.layouts.app')
@php
    $s = $order->simpleStatus();
    $phone = preg_replace('/[^0-9+]/', '', (string) $order->shop?->phone);
    $steps = [1 => 'Sent', 2 => 'Approved', 3 => 'Delivered'];
    $settle = $order->settlement();
    $baki = (float) $settle['baki'];
    $openBaki = (float) $settle['remaining_due'];
    $dueNow = (float) ($collectibleBalance ?? $baki);
    $prevBaki = $order->previousShopBaki();
    $totalBaki = round($openBaki + $prevBaki, 2);
    $pendingAmt = (float) ($settle['pending_sales'] ?? 0);
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
            @elseif (! empty($pendingBill))
                <div class="f-warn">Collected <b>{{ \App\Support\DemoData::taka($pendingBill->amount) }}</b> — waiting for office to confirm. This order baki stays until then.</div>
            @elseif ($settle['fully_settled'])
                <div class="f-flash ok"><i data-lucide="check-circle-2"></i><span>Paid in full.</span></div>
            @endif

            @if ($dueNow > 0.009 && $order->canCollectNow())
                <form id="collect-bill" class="f-od-pay" method="post" action="{{ route('field.orders.collect', $order) }}"
                      x-data="{
                          due: {{ json_encode($dueNow) }},
                          prev: {{ json_encode($prevBaki) }},
                          paidNow: {{ json_encode((float) old('amount', 0)) }},
                          payMethod: {{ json_encode(old('method', 'cash')) }},
                          taka(n) { return '৳ ' + Math.round(Number(n) || 0).toLocaleString('en-IN'); },
                          baki() { return Math.max(0, Math.round((this.due - Number(this.paidNow || 0)) * 100) / 100); },
                          totalBaki() { return Math.max(0, Math.round((this.baki() + Number(this.prev || 0)) * 100) / 100); }
                      }">
                    @csrf
                    <div class="f-od-pay-head">
                        <strong>Payment at shop</strong>
                        <span>This order due {{ \App\Support\DemoData::taka($dueNow) }} · goods delivered, collect now</span>
                    </div>
                    @if (! empty($pendingBill))
                        <p class="f-muted f-small" style="margin:0">Office is confirming {{ \App\Support\DemoData::taka($pendingBill->amount) }}.</p>
                    @endif
                    <div class="f-pay-seg" role="group" aria-label="How much to collect">
                        <button type="button" :class="Number(paidNow) === 0 ? 'active' : ''" @click="paidNow = 0">Full baki</button>
                        <button type="button" :class="due > 0 && Number(paidNow) === Math.round(due / 2) ? 'active' : ''" @click="paidNow = Math.round(due / 2)">Half now</button>
                        <button type="button" :class="due > 0 && Number(paidNow) >= due ? 'active' : ''" @click="paidNow = Math.round(due)">Pay all</button>
                    </div>
                    <div class="f-od-pay-row">
                        <div class="f-money f-money-sm">
                            <span>৳</span>
                            <input id="collect-amount" type="number" inputmode="decimal" min="0" step="1" name="amount" x-model.number="paidNow" :max="Math.round(due)" placeholder="0">
                        </div>
                        <button class="f-btn f-btn-primary f-btn-sm" type="submit" :disabled="Number(paidNow) <= 0 || Number(paidNow) > due + 0.01">
                            <span x-text="Number(paidNow) <= 0 ? 'Collect' : (baki() > 0.009 ? 'Collect ' + taka(paidNow) : 'Collect all')"></span>
                        </button>
                    </div>
                    <div class="f-od-pay-left">
                        <div><span>This order baki</span><b x-text="taka(baki())"></b></div>
                        @if ($prevBaki > 0.009)
                            <div class="is-total-baki"><span>Total baki</span><b x-text="taka(totalBaki())"></b></div>
                        @endif
                    </div>
                    <template x-if="Number(paidNow) > 0">
                        <div class="f-od-pay-how">
                            <select id="collect-method" class="f-input" name="method" x-model="payMethod" aria-label="How did they pay?">
                                <option value="cash">Cash</option>
                                <option value="mobile_banking">bKash / Nagad / Rocket</option>
                                <option value="bank_transfer">Bank transfer</option>
                                <option value="cheque">Cheque</option>
                            </select>
                            <input id="collect-ref" class="f-input" type="text" name="reference" value="{{ old('reference') }}" maxlength="120" placeholder="Reference (optional)">
                        </div>
                    </template>
                </form>
            @elseif ($dueNow > 0.009)
                <div class="f-od-pay f-od-pay-wait">
                    <div class="f-od-pay-head">
                        <strong>Payment after delivery</strong>
                        <span>This order is {{ \App\Support\DemoData::taka($dueNow) }} baki. Collect when the goods are delivered.</span>
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
                    @if ($settle['paid_to_date'] > 0.009)
                        <div><span>Paid so far</span><span>{{ \App\Support\DemoData::taka($settle['paid_to_date']) }}</span></div>
                    @endif
                    @if ($pendingAmt > 0.009)
                        <div><span>Waiting confirm</span><span>{{ \App\Support\DemoData::taka($pendingAmt) }}</span></div>
                    @endif
                    @if ($openBaki > 0.009)
                        <div class="is-baki"><span>This order baki</span><span>{{ \App\Support\DemoData::taka($openBaki) }}</span></div>
                    @endif
                    @if ($prevBaki > 0.009)
                        <div class="is-baki"><span>Previous shop baki</span><span>{{ \App\Support\DemoData::taka($prevBaki) }}</span></div>
                    @endif
                    @if ($totalBaki > 0.009 && $prevBaki > 0.009)
                        <div class="is-grand is-baki"><span>Total baki</span><strong>{{ \App\Support\DemoData::taka($totalBaki) }}</strong></div>
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
                @if ($prevBaki > 0.009)
                    <div class="f-kv"><span>Previous baki</span><span class="is-debt">{{ \App\Support\DemoData::taka($prevBaki) }}</span></div>
                @endif
                @if ($openBaki > 0.009)
                    <div class="f-kv"><span>This order baki</span><span class="is-debt">{{ \App\Support\DemoData::taka($openBaki) }}</span></div>
                @endif
                @if ($totalBaki > 0.009 && $prevBaki > 0.009)
                    <div class="f-kv"><span>Total baki</span><span class="is-debt">{{ \App\Support\DemoData::taka($totalBaki) }}</span></div>
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
