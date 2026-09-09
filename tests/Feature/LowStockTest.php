<?php

namespace Tests\Feature;

use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LowStockTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_returns_products_at_or_below_the_requested_threshold(): void
    {
        Product::factory()->create(['stock_on_hand' => 2]);
        Product::factory()->create(['stock_on_hand' => 7]);

        $this->getJson('/api/products/low-stock?threshold=5')
            ->assertOk()
            ->assertJsonPath('threshold', 5)
            ->assertJsonCount(1, 'data');
    }

    public function test_it_falls_back_to_the_configured_default_threshold_when_none_is_given(): void
    {
        config(['inventory.low_stock_threshold' => 5]);

        Product::factory()->create(['stock_on_hand' => 3]);
        Product::factory()->create(['stock_on_hand' => 9]);

        $this->getJson('/api/products/low-stock')
            ->assertOk()
            ->assertJsonPath('threshold', 5)
            ->assertJsonCount(1, 'data');
    }

    public function test_it_rejects_a_negative_threshold(): void
    {
        $this->getJson('/api/products/low-stock?threshold=-1')
            ->assertStatus(422);
    }
}
