<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\ContactMessage;
use App\Models\PartnerInquiry;
use App\Models\Product;
use App\Rules\BangladeshPhone;
use App\Services\AppNotificationService;
use App\Support\SiteContent;
use Illuminate\Http\Request;

class SiteController extends Controller
{
    public function home()
    {
        return view('site.home');
    }

    public function products(Request $request, SiteContent $site)
    {
        $categoryId = $request->integer('category') ?: null;
        $search = trim((string) $request->input('q', ''));

        return view('site.products.index', [
            'products' => $site->catalogProducts($categoryId, $search !== '' ? $search : null),
            'categories' => Category::query()->where('is_active', true)->orderBy('name')->get(),
            'categoryId' => $categoryId,
            'search' => $search,
        ]);
    }

    public function showProduct(Product $product)
    {
        $product->load(['category', 'brand', 'images']);

        $related = Product::query()
            ->with(['category', 'brand', 'images'])
            ->whereKeyNot($product->id)
            ->when($product->category_id, fn ($q) => $q->where('category_id', $product->category_id))
            ->latest()
            ->limit(4)
            ->get();

        return view('site.products.show', compact('product', 'related'));
    }

    public function about()
    {
        return view('site.about');
    }

    public function contact()
    {
        return view('site.contact');
    }

    public function storeContact(Request $request, AppNotificationService $notifications, SiteContent $site)
    {
        $thanks = $site->get('contact.success_message') ?: 'Thanks — we received your message and will reply soon.';
        if ($request->filled('website')) {
            return back()->with('success', $thanks);
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:180'],
            'subject' => ['nullable', 'string', 'max:180'],
            'message' => ['required', 'string', 'max:5000'],
        ]);

        $msg = ContactMessage::query()->create($data + ['status' => ContactMessage::STATUS_NEW]);

        try {
            $notifications->notifyAdmins(
                'New contact message',
                $msg->name.' · '.($msg->subject ?: 'General enquiry'),
                '/admin/partner-leads',
                'contact',
                'info',
            );
        } catch (\Throwable) {
            // non-blocking
        }

        return back()->with('success', $thanks);
    }

    public function partner(SiteContent $site)
    {
        if (! $site->enabled('general.show_partner_button')) {
            return redirect()->route('site.contact');
        }

        return view('site.partner');
    }

    public function storePartner(Request $request, AppNotificationService $notifications, SiteContent $site)
    {
        if (! $site->enabled('general.show_partner_button')) {
            return redirect()->route('site.contact');
        }

        $received = $site->get('partner.success_message') ?: 'Application received. Our team will review and contact you with next steps.';
        if ($request->filled('website')) {
            return redirect()->route('site.partner')->with('success', $received);
        }

        $data = $request->validate([
            'business_name' => ['required', 'string', 'max:180'],
            'contact_name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:180'],
            'phone' => ['required', 'string', 'max:20', new BangladeshPhone(required: true)],
            'city' => ['nullable', 'string', 'max:80'],
            'business_type' => ['nullable', 'in:retailer,distributor,other'],
            'message' => ['nullable', 'string', 'max:5000'],
        ]);

        $data['phone'] = BangladeshPhone::normalize($data['phone']);
        $data['email'] = strtolower(trim($data['email']));

        $alreadyOpen = PartnerInquiry::query()
            ->whereIn('status', [PartnerInquiry::STATUS_NEW, PartnerInquiry::STATUS_CONTACTED])
            ->where(fn ($q) => $q->where('email', $data['email'])->orWhere('phone', $data['phone']))
            ->exists();
        if ($alreadyOpen) {
            return redirect()->route('site.partner')->with('success', 'We already have your application and will contact you soon.');
        }

        $inquiry = PartnerInquiry::query()->create($data + ['status' => PartnerInquiry::STATUS_NEW]);

        try {
            $notifications->notifyAdmins(
                'New partner application',
                $inquiry->business_name.' · '.($inquiry->city ?: 'No city').' · waiting for approval',
                '/admin/partner-leads?status=pending',
                'partners',
                'warning',
            );
        } catch (\Throwable) {
            // non-blocking
        }

        return redirect()->route('site.partner')->with('success', $received);
    }
}
