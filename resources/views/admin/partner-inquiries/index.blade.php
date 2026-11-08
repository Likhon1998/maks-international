@extends('layouts.app')
@section('title', 'Partner leads')
@section('content')
    <div class="partner-desk">
        <div class="partner-desk-top">
            <div>
                <div class="page-kicker"><strong>Partner applications</strong> / Approve website &amp; field registrations here — then the shop becomes active</div>
            </div>
            <div class="partner-desk-top-actions">
                <a class="btn btn-ghost btn-sm" href="{{ route('shops.index') }}">Shops</a>
                <a class="btn btn-ghost btn-sm" href="{{ route('site.partner') }}" target="_blank">Apply form</a>
            </div>
        </div>

        @if (session('success') || session('error'))
            <div class="partner-flash {{ session('error') ? 'is-error' : 'is-ok' }}">
                {{ session('error') ?: session('success') }}
            </div>
        @endif

        @if (session('cred_email'))
            <div class="partner-cred">
                <div class="partner-cred-main">
                    <div class="partner-cred-title">Credentials ready · {{ session('cred_shop') }}</div>
                    <div class="partner-cred-grid">
                        <span><em>Email</em> {{ session('cred_email') }}</span>
                        <span><em>Temp pass</em> {{ session('cred_password') }}</span>
                        <span><em>Portal</em> {{ url('/portal/login') }}</span>
                    </div>
                </div>
                <div class="partner-cred-actions">
                    @if (session('accepted_inquiry_id'))
                        <form method="post" action="{{ route('partner-inquiries.send-whatsapp', session('accepted_inquiry_id')) }}">
                            @csrf
                            <button class="btn btn-primary btn-sm" type="submit">Send WhatsApp</button>
                        </form>
                    @endif
                    @if (session('whatsapp_chat_url'))
                        <a class="btn btn-ghost btn-sm" href="{{ session('whatsapp_chat_url') }}" target="_blank" rel="noopener">Open chat</a>
                    @endif
                </div>
            </div>
        @endif

        <div class="partner-desk-grid">
            <section class="partner-panel">
                <div class="partner-panel-head">
                    <div class="partner-tabs">
                        @foreach ([
                            '' => ['All', $counts['all']],
                            'pending' => ['Pending', $counts['pending']],
                            'accepted' => ['Accepted', $counts['accepted']],
                            'rejected' => ['Rejected', $counts['rejected']],
                        ] as $key => [$label, $count])
                            <a
                                href="{{ route('partner-inquiries.index', array_filter(['status' => $key ?: null, 'source' => $source])) }}"
                                class="partner-tab {{ request('status', '') === $key ? 'is-active' : '' }}"
                            >
                                {{ $label }} <b>{{ $count }}</b>
                            </a>
                        @endforeach
                    </div>
                    <div class="partner-sources" role="group" aria-label="Where the request came from">
                        @foreach ([
                            '' => ['All sources', $sourceCounts->sum()],
                            'website' => ['Website', $sourceCounts['website'] ?? 0],
                            'field' => ['Field officers', $sourceCounts['field'] ?? 0],
                        ] as $key => [$label, $count])
                            <a
                                href="{{ route('partner-inquiries.index', array_filter(['status' => request('status'), 'source' => $key ?: null])) }}"
                                class="partner-src {{ ($source ?? '') === $key ? 'is-active' : '' }}"
                            >{{ $label }} <b>{{ $count }}</b></a>
                        @endforeach
                    </div>
                </div>

                <div class="partner-list">
                    @forelse ($inquiries as $row)
                        <article class="partner-row {{ $row->isPending() ? 'is-pending' : '' }}">
                            <div class="partner-row-main">
                                <div class="partner-row-title">
                                    <strong>{{ $row->business_name }}</strong>
                                    <x-badge :status="$row->statusLabel()" />
                                    @if ($row->shop)
                                        <a class="link partner-shop-link" href="{{ route('shops.show', $row->shop) }}">{{ $row->shop->code }}</a>
                                    @endif
                                    @if ($row->isFromField())
                                        <span class="partner-source" title="Submitted from the field app">Field · {{ $row->submitter?->name ?? 'Salesman' }}</span>
                                    @else
                                        <span class="partner-source is-web" title="Submitted on the public website">Website</span>
                                    @endif
                                </div>
                                <div class="partner-row-meta">
                                    <span>{{ $row->contact_name ?: 'No owner name' }}</span>
                                    <span>{{ $row->email }}</span>
                                    <span>{{ $row->phone ?: '—' }}</span>
                                    <span>{{ $row->city ?: 'No city' }}</span>
                                    <span>{{ $row->created_at?->format('d M, H:i') }}</span>
                                </div>
                                @if ($row->message)
                                    <p class="partner-row-note">{{ \Illuminate\Support\Str::limit($row->message, 140) }}</p>
                                @endif
                                @if ($row->isAccepted())
                                    @if ($row->needsLogin())
                                        <p class="partner-login is-waiting">Waiting for {{ $row->submitter?->name ?? 'the field officer' }} to create the owner's login</p>
                                    @else
                                        <p class="partner-login is-ready">Portal login {{ $row->portal_email }}{{ $row->isFromField() ? ' · set by '.($row->submitter?->name ?? 'field officer') : '' }}</p>
                                    @endif
                                @elseif ($row->isPending() && $row->isFromField())
                                    <p class="partner-login">On accept, {{ $row->submitter?->name ?? 'the field officer' }} will create the login with the owner</p>
                                @endif
                            </div>
                            <div class="partner-row-actions">
                                @can('shops.approve')
                                    @if ($row->isPending())
                                        <form method="post" action="{{ route('partner-inquiries.accept', $row) }}">
                                            @csrf
                                            <button class="btn btn-primary btn-sm" type="submit">Approve</button>
                                        </form>
                                        <form method="post" action="{{ route('partner-inquiries.reject', $row) }}" onsubmit="return confirm('Reject this request?')">
                                            @csrf
                                            <button class="btn btn-ghost btn-sm partner-btn-reject" type="submit">Reject</button>
                                        </form>
                                    @elseif ($row->needsLogin())
                                        <form method="post" action="{{ route('partner-inquiries.issue-login', $row) }}" onsubmit="return confirm('Create the login yourself instead of the field officer?')">
                                            @csrf
                                            <button class="btn btn-ghost btn-sm" type="submit">Issue login</button>
                                        </form>
                                    @elseif ($row->isAccepted())
                                        <form method="post" action="{{ route('partner-inquiries.send-whatsapp', $row) }}">
                                            @csrf
                                            <button class="btn btn-primary btn-sm" type="submit">WhatsApp</button>
                                        </form>
                                    @else
                                        <form method="post" action="{{ route('partner-inquiries.pending', $row) }}">
                                            @csrf
                                            <button class="btn btn-ghost btn-sm" type="submit">Reopen</button>
                                        </form>
                                    @endif
                                @else
                                    <span class="muted" style="font-size:12px">Needs shops.approve</span>
                                @endcan
                            </div>
                        </article>
                    @empty
                        <div class="partner-empty">No partner requests in this filter.</div>
                    @endforelse
                </div>

                @if ($inquiries->hasPages())
                    <div class="partner-pager">{{ $inquiries->links() }}</div>
                @endif
            </section>

            <aside class="partner-panel partner-messages">
                <div class="partner-panel-head">
                    <strong>Contact inbox</strong>
                    <span class="muted" style="font-size:12px">{{ $messages->total() }} total</span>
                </div>
                <div class="partner-list">
                    @forelse ($messages as $msg)
                        <article class="partner-msg">
                            <div class="partner-msg-top">
                                <strong>{{ $msg->name }}</strong>
                                <x-badge :status="$msg->statusLabel()" />
                            </div>
                            <div class="partner-row-meta">
                                <span>{{ $msg->email }}</span>
                                <span>{{ $msg->created_at?->diffForHumans() }}</span>
                            </div>
                            <div class="partner-msg-subject">{{ $msg->subject ?: 'General enquiry' }}</div>
                            <p class="partner-row-note">{{ \Illuminate\Support\Str::limit($msg->message, 110) }}</p>
                            <form method="post" action="{{ route('contact-messages.update', $msg) }}" class="partner-msg-status">
                                @csrf
                                @method('put')
                                <select class="select" name="status" onchange="this.form.submit()">
                                    @foreach (['new' => 'New', 'read' => 'Read', 'closed' => 'Closed'] as $value => $label)
                                        <option value="{{ $value }}" @selected($msg->status === $value)>{{ $label }}</option>
                                    @endforeach
                                </select>
                            </form>
                        </article>
                    @empty
                        <div class="partner-empty">No contact messages.</div>
                    @endforelse
                </div>
                @if ($messages->hasPages())
                    <div class="partner-pager">{{ $messages->links() }}</div>
                @endif
            </aside>
        </div>
    </div>
@endsection
