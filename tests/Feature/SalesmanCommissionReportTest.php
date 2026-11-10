<?php

namespace Tests\Feature;

use App\Models\Commission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class SalesmanCommissionReportTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    public function test_commission_report_breaks_down_each_salesman_and_exports(): void
    {
        $admin = User::where('email', 'admin@maksinternational.com')->firstOrFail();
        $salesman = User::where('email', 'karim@field.local')->firstOrFail();
        $day = Carbon::parse('2024-03-15 12:00:00');

        $due = Commission::query()->create([
            'number' => 'COM-RPT-DUE',
            'salesman_id' => $salesman->id,
            'type' => Commission::TYPE_COLLECTION,
            'year' => 2024,
            'month' => 3,
            'base_amount' => 1000,
            'rate_percent' => 5,
            'commission_amount' => 50,
            'status' => Commission::STATUS_APPROVED,
        ]);
        $due->forceFill(['created_at' => $day, 'updated_at' => $day])->save();

        $paid = Commission::query()->create([
            'number' => 'COM-RPT-PAID',
            'salesman_id' => $salesman->id,
            'type' => Commission::TYPE_COLLECTION,
            'year' => 2024,
            'month' => 3,
            'base_amount' => 400,
            'rate_percent' => 5,
            'commission_amount' => 20,
            'status' => Commission::STATUS_PAID,
            'paid_at' => $day,
        ]);
        $paid->forceFill(['created_at' => $day, 'updated_at' => $day])->save();

        $period = ['from' => '2024-03-01', 'to' => '2024-03-31'];

        $this->actingAs($admin)
            ->get(route('reports.show', ['report' => 'commissions'] + $period))
            ->assertOk()
            ->assertSee($salesman->name)
            ->assertSee('Total sales')
            ->assertSee('Total orders')
            ->assertSee('Total profit')
            ->assertSee('Commission rate')
            ->assertSee('Total commission')
            ->assertSee('Paid commission')
            ->assertSee('Due commission')
            ->assertSee('Commission status')
            ->assertSee('Payment date')
            ->assertSee('Partially paid')
            ->assertSee('Commission payment history')
            ->assertSee('This month')
            ->assertSee('COM-RPT-PAID');

        $this->actingAs($admin)
            ->get(route('reports.export', ['report' => 'commissions', 'format' => 'xlsx'] + $period))
            ->assertOk();

        $this->actingAs($admin)
            ->get(route('reports.export', ['report' => 'commissions', 'format' => 'pdf'] + $period))
            ->assertOk();
    }
}
