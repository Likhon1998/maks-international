<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdminNotificationLiveTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    public function test_admin_can_poll_feed_and_mark_notifications_seen(): void
    {
        $admin = User::where('email', 'admin@maksinternational.com')->firstOrFail();
        $admin->unreadNotifications->markAsRead();

        $note = $admin->notifications()->create([
            'id' => (string) Str::uuid(),
            'type' => 'App\\Notifications\\SystemAlert',
            'data' => [
                'title' => 'Live alert',
                'body' => 'Something needs attention',
                'category' => 'orders',
                'url' => '/admin/orders',
            ],
        ]);

        $this->actingAs($admin)
            ->getJson(route('notifications.poll'))
            ->assertOk()
            ->assertJsonPath('unread', 1)
            ->assertJsonPath('latest_id', $note->id)
            ->assertJsonPath('latest_title', 'Live alert');

        $this->getJson(route('notifications.feed'))
            ->assertOk()
            ->assertJsonPath('unread', 1)
            ->assertJsonFragment(['id' => $note->id, 'title' => 'Live alert', 'read' => false]);

        $this->postJson(route('notifications.seen'), ['ids' => [$note->id]])
            ->assertOk()
            ->assertJsonPath('unread', 0)
            ->assertJsonPath('ok', true);

        $this->assertNotNull($note->fresh()->read_at);

        $this->getJson(route('notifications.poll'))
            ->assertOk()
            ->assertJsonPath('unread', 0);
    }

    public function test_opening_notification_history_page_is_allowed_and_lists_items(): void
    {
        $admin = User::where('email', 'admin@maksinternational.com')->firstOrFail();
        $admin->notifications()->create([
            'id' => (string) Str::uuid(),
            'type' => 'App\\Notifications\\SystemAlert',
            'data' => ['title' => 'History row', 'body' => 'Seen on page', 'category' => 'system'],
        ]);

        $this->actingAs($admin)
            ->get(route('notifications.index'))
            ->assertOk()
            ->assertSee('History row', false)
            ->assertSee('Unread', false);
    }
}
