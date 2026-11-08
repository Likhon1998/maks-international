<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ContactMessage;
use App\Models\PartnerInquiry;
use App\Models\User;
use App\Services\PartnerInquiryService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Throwable;

class PartnerInquiryController extends Controller
{
    public function __construct(private PartnerInquiryService $partners) {}

    public function index(Request $request)
    {
        abort_unless($request->user()->can('shops.view'), 403);

        $status = $request->get('status');
        $source = in_array($request->get('source'), [PartnerInquiry::SOURCE_WEBSITE, PartnerInquiry::SOURCE_FIELD], true) ? $request->get('source') : null;

        $counts = [
            'all' => PartnerInquiry::query()->count(),
            'pending' => PartnerInquiry::query()->whereIn('status', [PartnerInquiry::STATUS_NEW, PartnerInquiry::STATUS_CONTACTED])->count(),
            'accepted' => PartnerInquiry::query()->where('status', PartnerInquiry::STATUS_CONVERTED)->count(),
            'rejected' => PartnerInquiry::query()->where('status', PartnerInquiry::STATUS_CLOSED)->count(),
        ];
        $sourceCounts = PartnerInquiry::query()->selectRaw('source, COUNT(*) as n')->groupBy('source')->pluck('n', 'source');

        $inquiries = PartnerInquiry::query()
            ->with(['shop', 'submitter'])
            ->when($source, fn ($q) => $q->where('source', $source))
            ->when($status === 'pending', fn ($q) => $q->whereIn('status', [PartnerInquiry::STATUS_NEW, PartnerInquiry::STATUS_CONTACTED]))
            ->when($status === 'accepted', fn ($q) => $q->where('status', PartnerInquiry::STATUS_CONVERTED))
            ->when($status === 'rejected', fn ($q) => $q->where('status', PartnerInquiry::STATUS_CLOSED))
            ->when($status && ! in_array($status, ['pending', 'accepted', 'rejected'], true), fn ($q) => $q->where('status', $status))
            ->latest()
            ->paginate(20, ['*'], 'partners_page')
            ->withQueryString();

        $messages = ContactMessage::query()
            ->latest()
            ->paginate(12, ['*'], 'messages_page')
            ->withQueryString();

        return view('admin.partner-inquiries.index', compact('inquiries', 'messages', 'counts', 'source', 'sourceCounts'));
    }

    public function accept(Request $request, PartnerInquiry $partnerInquiry)
    {
        abort_unless($request->user()->can('shops.approve'), 403);

        try {
            $result = $this->partners->accept($partnerInquiry, $request->user());
        } catch (Throwable $e) {
            return back()->with('error', $e->getMessage());
        }

        if ($result['login_by_field']) {
            return back()->with('success', 'Approved. Shop '.$result['shop']->code.' is active. '
                .($partnerInquiry->submitter?->name ?? 'The field officer').' will now create the owner\'s login from the field app.');
        }

        return $this->withCredentials($request, $result);
    }

    public function issueLogin(Request $request, PartnerInquiry $partnerInquiry)
    {
        abort_unless($request->user()->can('shops.approve'), 403);

        if (! $partnerInquiry->needsLogin()) {
            return back()->with('error', 'This request already has a portal login.');
        }

        try {
            $password = $this->partners->temporaryPassword();
            $login = $this->partners->setLogin($partnerInquiry, $partnerInquiry->email, $password, $request->user());
        } catch (Throwable $e) {
            return back()->with('error', $e->getMessage());
        }

        return $this->withCredentials($request, [
            'inquiry' => $partnerInquiry->fresh(),
            'shop' => $login['shop'],
            'email' => $login['user']->email,
            'password' => $password,
            'whatsapp' => $login['whatsapp'],
        ]);
    }

    private function withCredentials(Request $request, array $result)
    {
        $this->rememberCredentials($request, $result['inquiry']->id, [
            'email' => $result['email'],
            'password' => $result['password'],
            'shop' => $result['shop']->code,
        ]);

        return back()
            ->with('success', 'Approved. Shop '.$result['shop']->code.' is active with portal login '.$result['email'].'.')
            ->with('accepted_inquiry_id', $result['inquiry']->id)
            ->with('whatsapp_chat_url', $result['whatsapp']['chat_url'] ?? null)
            ->with('cred_email', $result['email'])
            ->with('cred_password', $result['password'])
            ->with('cred_shop', $result['shop']->code);
    }

    /** Lets the admin re-send the same WhatsApp for a short while; stored encrypted, never in plain text. */
    private function rememberCredentials(Request $request, int $inquiryId, array $cred): void
    {
        $request->session()->put('partner_cred_'.$inquiryId, [
            'payload' => Crypt::encryptString(json_encode($cred)),
            'expires' => now()->addMinutes(15)->timestamp,
        ]);
    }

    public function reject(Request $request, PartnerInquiry $partnerInquiry)
    {
        abort_unless($request->user()->can('shops.approve'), 403);

        try {
            $this->partners->reject($partnerInquiry, $request->user(), $request->input('admin_notes'));
        } catch (Throwable $e) {
            return back()->with('error', $e instanceof \Illuminate\Validation\ValidationException ? collect($e->errors())->flatten()->first() : $e->getMessage());
        }

        return back()->with('success', 'Request marked as rejected.');
    }

    public function pending(Request $request, PartnerInquiry $partnerInquiry)
    {
        abort_unless($request->user()->can('shops.approve'), 403);

        try {
            $this->partners->markPending($partnerInquiry, $request->user());
        } catch (Throwable $e) {
            return back()->with('error', $e instanceof \Illuminate\Validation\ValidationException ? collect($e->errors())->flatten()->first() : $e->getMessage());
        }

        return back()->with('success', 'Request kept as pending.');
    }

    public function sendWhatsapp(Request $request, PartnerInquiry $partnerInquiry)
    {
        abort_unless($request->user()->can('shops.approve'), 403);

        if ($partnerInquiry->status !== PartnerInquiry::STATUS_CONVERTED) {
            return back()->with('error', 'Accept the request first, then send WhatsApp.');
        }
        if ($partnerInquiry->needsLogin()) {
            return back()->with('error', 'No portal login yet. Wait for the field officer, or use Issue login.');
        }

        $stored = $request->session()->get('partner_cred_'.$partnerInquiry->id);
        $cred = null;
        if (is_array($stored) && ($stored['expires'] ?? 0) > now()->timestamp) {
            try {
                $cred = json_decode(Crypt::decryptString($stored['payload']), true);
            } catch (Throwable) {
                $cred = null;
            }
        }
        if (! $cred || empty($cred['password'])) {
            // Regenerate password and update portal user
            $password = $this->partners->temporaryPassword();
            $email = $partnerInquiry->portal_email ?: $partnerInquiry->email;
            $user = User::query()->where('email', $email)->where('portal', User::PORTAL_SHOP)->first();
            if (! $user) {
                return back()->with('error', 'Portal user not found. Accept the request again.');
            }
            $user->forceFill(['password' => $password])->save();
            $cred = ['email' => $email, 'password' => $password, 'shop' => $partnerInquiry->shop?->code];
            $this->rememberCredentials($request, $partnerInquiry->id, $cred);
        }

        $result = $this->partners->whatsappPayload($partnerInquiry, $cred['password']);

        $flash = ($result['ok'] ?? false) && ($result['via'] ?? '') === 'meta'
            ? 'WhatsApp message sent.'
            : (($result['ok'] ?? false) && ($result['via'] ?? '') === 'log'
                ? 'WhatsApp message logged. Click Open WhatsApp to send manually, or set WHATSAPP_DRIVER=meta.'
                : 'Open WhatsApp to send the login message.');

        if (! empty($result['error']) && empty($result['chat_url'])) {
            return back()->with('error', $result['error']);
        }

        return back()
            ->with('success', $flash)
            ->with('accepted_inquiry_id', $partnerInquiry->id)
            ->with('whatsapp_chat_url', $result['chat_url'] ?? null)
            ->with('cred_email', $cred['email'])
            ->with('cred_password', $cred['password'])
            ->with('cred_shop', $cred['shop'] ?? null);
    }

    public function markMessage(Request $request, ContactMessage $contactMessage)
    {
        abort_unless($request->user()->can('shops.view'), 403);

        $data = $request->validate([
            'status' => ['required', 'in:new,read,closed'],
        ]);

        $contactMessage->update(['status' => $data['status']]);

        return back()->with('success', 'Contact message updated.');
    }
}
