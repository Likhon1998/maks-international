<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class NotificationController extends Controller
{
    public function index(Request $request)
    {
        $filter = $request->get('filter', 'all'); // all | unread | read

        $notifications = $request->user()
            ->notifications()
            ->when($filter === 'unread', fn ($q) => $q->whereNull('read_at'))
            ->when($filter === 'read', fn ($q) => $q->whereNotNull('read_at'))
            ->when($request->search, function ($q, $search) {
                $q->where(function ($inner) use ($search) {
                    $inner->where('data->title', 'like', "%{$search}%")
                        ->orWhere('data->body', 'like', "%{$search}%")
                        ->orWhere('data->category', 'like', "%{$search}%");
                });
            })
            ->latest()
            ->paginate(40)
            ->withQueryString();

        $unreadCount = $request->user()->unreadNotifications()->count();
        $totalCount = $request->user()->notifications()->count();

        return view('admin.notifications.index', compact('notifications', 'filter', 'unreadCount', 'totalCount'));
    }

    public function poll(Request $request)
    {
        $user = $request->user();
        $latest = $user->notifications()->latest('created_at')->latest('id')->first();

        return response()->json([
            'unread' => $user->unreadNotifications()->count(),
            'latest_id' => $latest?->id,
            'latest_at' => $latest?->created_at?->toIso8601String(),
            'latest_title' => is_array($latest?->data) ? ($latest->data['title'] ?? null) : null,
        ]);
    }

    public function feed(Request $request)
    {
        $user = $request->user();
        $items = $user->notifications()->latest()->limit(10)->get()->map(fn ($n) => $this->serialize($n));

        return response()->json([
            'unread' => $user->unreadNotifications()->count(),
            'items' => $items,
            'latest_id' => $items->first()['id'] ?? null,
        ]);
    }

    public function markSeen(Request $request)
    {
        $data = $request->validate([
            'ids' => ['nullable', 'array', 'max:50'],
            'ids.*' => ['required', 'string', 'max:64'],
        ]);

        $user = $request->user();
        $query = $user->unreadNotifications();

        if (! empty($data['ids'])) {
            $query->whereIn('id', $data['ids']);
        } else {
            $query->latest()->limit(10);
        }

        $query->get()->each->markAsRead();

        return response()->json([
            'unread' => $user->unreadNotifications()->count(),
            'ok' => true,
        ]);
    }

    public function markRead(Request $request, string $id)
    {
        $notification = $request->user()->notifications()->where('id', $id)->firstOrFail();
        $notification->markAsRead();

        $url = $notification->data['url'] ?? null;
        if (! is_string($url) || $url === '') {
            return back();
        }

        // Only ever follow a path inside this admin site (no external or protocol-relative URLs).
        $path = parse_url($url, PHP_URL_PATH);
        $query = parse_url($url, PHP_URL_QUERY);
        if (is_string($path) && preg_match('#^/admin(/|$)#', $path) && ! str_starts_with($path, '//')) {
            return redirect()->to($path.($query ? '?'.$query : ''));
        }

        return redirect()->route('notifications.index');
    }

    public function markAllRead(Request $request)
    {
        $request->user()->unreadNotifications->markAsRead();

        if ($request->expectsJson()) {
            return response()->json(['unread' => 0, 'ok' => true]);
        }

        return back()->with('success', 'All notifications marked as read.');
    }

    /**
     * @param  \Illuminate\Notifications\DatabaseNotification  $n
     * @return array<string, mixed>
     */
    private function serialize($n): array
    {
        $data = is_array($n->data) ? $n->data : [];

        return [
            'id' => $n->id,
            'title' => (string) ($data['title'] ?? 'Alert'),
            'body' => Str::limit((string) ($data['body'] ?? ''), 80),
            'category' => (string) ($data['category'] ?? 'system'),
            'url' => is_string($data['url'] ?? null) ? $data['url'] : null,
            'read' => $n->read_at !== null,
            'when' => $n->created_at?->timezone(config('app.timezone'))->format('d M · h:i A'),
        ];
    }
}
