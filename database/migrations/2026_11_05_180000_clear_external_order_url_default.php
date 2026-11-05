<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** Old schema default that sent public Order buttons off-site. */
    private const LEGACY_URLS = [
        'https://makgadget.com/shop',
        'https://makgadget.com/shop/',
        'https://makgadget.com',
        'https://makgadget.com/',
    ];

    public function up(): void
    {
        $row = DB::table('site_settings')->where('section', 'general')->first();
        if (! $row) {
            Cache::forget('site_content.v1');

            return;
        }

        $content = is_array($row->content)
            ? $row->content
            : json_decode((string) $row->content, true);
        if (! is_array($content)) {
            return;
        }

        $current = trim((string) ($content['maks_gadget_url'] ?? ''));
        if ($current === '' || ! in_array(rtrim($current, '/'), array_map(fn ($u) => rtrim($u, '/'), self::LEGACY_URLS), true)) {
            Cache::forget('site_content.v1');

            return;
        }

        $content['maks_gadget_url'] = '';
        DB::table('site_settings')->where('section', 'general')->update([
            'content' => json_encode($content),
            'updated_at' => now(),
        ]);
        Cache::forget('site_content.v1');
    }

    public function down(): void
    {
        // Intentionally empty — do not restore the external redirect.
    }
};
