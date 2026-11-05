<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const TARGET = 'https://maksgadget.com';

    /** Empty or old misspelled URLs that should become maksgadget.com. */
    private const REPLACE = [
        '',
        'https://makgadget.com',
        'https://makgadget.com/',
        'https://makgadget.com/shop',
        'https://makgadget.com/shop/',
    ];

    public function up(): void
    {
        $row = DB::table('site_settings')->where('section', 'general')->first();
        if ($row) {
            $content = is_array($row->content)
                ? $row->content
                : json_decode((string) $row->content, true);

            if (is_array($content)) {
                $current = trim((string) ($content['maks_gadget_url'] ?? ''));
                $normalized = array_map(fn ($u) => rtrim($u, '/'), self::REPLACE);
                if (in_array(rtrim($current, '/'), $normalized, true)) {
                    $content['maks_gadget_url'] = self::TARGET;
                    DB::table('site_settings')->where('section', 'general')->update([
                        'content' => json_encode($content),
                        'updated_at' => now(),
                    ]);
                }
            }
        }

        Cache::forget('site_content.v1');
    }

    public function down(): void
    {
        //
    }
};
