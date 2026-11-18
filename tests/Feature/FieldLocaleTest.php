<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FieldLocaleTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    public function test_salesman_can_switch_between_bangla_and_english_instantly(): void
    {
        $salesman = User::where('email', 'karim@field.local')->firstOrFail();

        $this->actingAs($salesman)
            ->post(route('field.locale'), ['locale' => 'bn'])
            ->assertRedirect();

        $this->assertSame('bn', session('locale'));

        $this->actingAs($salesman)
            ->get(route('field.dashboard'))
            ->assertOk()
            ->assertSee('বাকি আদায়', false)
            ->assertSee('হোম', false)
            ->assertSee('বরাদ্দ দোকান', false)
            ->assertSee('এই মাসের বিক্রি', false)
            ->assertSee('সাম্প্রতিক অর্ডার', false)
            ->assertSee('প্রতিদিন ভিজিট করা দোকান', false)
            ->assertDontSee('Assigned shops', false)
            ->assertDontSee('Recent orders', false)
            ->assertSee('lang="bn"', false);

        $this->actingAs($salesman)
            ->post(route('field.locale'), ['locale' => 'en'])
            ->assertRedirect();

        $this->assertSame('en', session('locale'));

        $this->actingAs($salesman)
            ->get(route('field.dues'))
            ->assertOk()
            ->assertSee('Collect baki', false)
            ->assertSee('lang="en"', false);
    }

    public function test_login_page_respects_locale_toggle(): void
    {
        $this->post(route('field.locale'), ['locale' => 'bn'])->assertRedirect();

        $this->get(route('field.login'))
            ->assertOk()
            ->assertSee('সাইন ইন', false)
            ->assertSee('lang="bn"', false);
    }
}
