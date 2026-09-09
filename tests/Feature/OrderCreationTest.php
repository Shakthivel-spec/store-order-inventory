<?php

namespace Tests\Feature;

use App\Jobs\SendOrderConfirmationJob;
use App\Models\Customer;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class OrderCreationTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_an_order_calculates_tax_and_reduces_stock(): void
    {
        Queue::fake();

        $product = Product::factory()->create([
            'name' => 'Notebook',
            'price' => 100,
            'tax_percentage' => 18,
            'stock_on_hand' => 5,
        ]);

        $response = $this->postJson('/api/orders', [
            'customer' => [
                'name' => 'Test Customer',
                'email' => 'test@example.com',
            ],
            'products' => [
                ['product_id' => $product->id, 'quantity' => 2],
            ],
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.subtotal', 200)
            ->assertJsonPath('data.tax_amount', 36)
            ->assertJsonPath('data.grand_total', 236);

        $this->assertDatabaseHas('customers', ['email' => 'test@example.com']);
        $this->assertDatabaseHas('orders', [
            'subtotal' => 200,
            'tax_amount' => 36,
            'grand_total' => 236,
        ]);
        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'stock_on_hand' => 3,
        ]);

        Queue::assertPushed(SendOrderConfirmationJob::class);
    }

    public function test_it_rejects_an_order_when_stock_is_insufficient_and_does_not_create_order(): void
    {
        $product = Product::factory()->create([
            'price' => 50,
            'tax_percentage' => 5,
            'stock_on_hand' => 1,
        ]);

        $response = $this->postJson('/api/orders', [
            'customer' => [
                'name' => 'Test Customer',
                'email' => 'test@example.com',
            ],
            'products' => [
                ['product_id' => $product->id, 'quantity' => 2],
            ],
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('products');

        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'stock_on_hand' => 1,
        ]);
    }


    public function test_a_second_order_cannot_oversell_the_last_unit(): void
    {
        $product = Product::factory()->create([
            'price' => 100,
            'tax_percentage' => 0,
            'stock_on_hand' => 1,
        ]);

        $this->postJson('/api/orders', [
            'customer' => [
                'name' => 'First Customer',
                'email' => 'first@example.com',
            ],
            'products' => [
                ['product_id' => $product->id, 'quantity' => 1],
            ],
        ])->assertCreated();

        $this->postJson('/api/orders', [
            'customer' => [
                'name' => 'Second Customer',
                'email' => 'second@example.com',
            ],
            'products' => [
                ['product_id' => $product->id, 'quantity' => 1],
            ],
        ])->assertStatus(422);

        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'stock_on_hand' => 0,
        ]);
    }

    public function test_it_returns_customer_order_history_by_email(): void
    {
        $customer = Customer::factory()->create(['email' => 'history@example.com']);
        $product = Product::factory()->create(['stock_on_hand' => 10]);

        $this->postJson('/api/orders', [
            'customer' => [
                'name' => $customer->name,
                'email' => $customer->email,
            ],
            'products' => [
                ['product_id' => $product->id, 'quantity' => 1],
            ],
        ])->assertCreated();

        $this->getJson('/api/orders/history?email=history@example.com')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_it_returns_404_for_order_history_of_unknown_email(): void
    {
        $this->getJson('/api/orders/history?email=nobody@example.com')
            ->assertNotFound();
    }

    public function test_it_reuses_an_existing_customer_by_email_instead_of_creating_a_duplicate(): void
    {
        $customer = Customer::factory()->create(['email' => 'existing@example.com', 'name' => 'Original Name']);
        $product = Product::factory()->create(['stock_on_hand' => 10]);

        $this->postJson('/api/orders', [
            'customer' => [
                'name' => 'Updated Name',
                'email' => 'existing@example.com',
            ],
            'products' => [
                ['product_id' => $product->id, 'quantity' => 1],
            ],
        ])->assertCreated();

        $this->assertDatabaseCount('customers', 1);
        $this->assertDatabaseHas('customers', [
            'id' => $customer->id,
            'email' => 'existing@example.com',
            'name' => 'Updated Name',
        ]);
    }

    public function test_it_calculates_totals_across_multiple_order_lines(): void
    {
        Queue::fake();

        $notebook = Product::factory()->create(['price' => 100, 'tax_percentage' => 18, 'stock_on_hand' => 5]);
        $pen = Product::factory()->create(['price' => 10, 'tax_percentage' => 5, 'stock_on_hand' => 20]);

        $response = $this->postJson('/api/orders', [
            'customer' => ['name' => 'Multi Line', 'email' => 'multiline@example.com'],
            'products' => [
                ['product_id' => $notebook->id, 'quantity' => 2],
                ['product_id' => $pen->id, 'quantity' => 3],
            ],
        ]);

        // notebook: 200 subtotal + 36 tax; pen: 30 subtotal + 1.5 tax
        $response->assertCreated()
            ->assertJsonPath('data.subtotal', 230)
            ->assertJsonPath('data.tax_amount', 37.5)
            ->assertJsonPath('data.grand_total', 267.5)
            ->assertJsonCount(2, 'data.items');

        $this->assertDatabaseHas('products', ['id' => $notebook->id, 'stock_on_hand' => 3]);
        $this->assertDatabaseHas('products', ['id' => $pen->id, 'stock_on_hand' => 17]);
    }

    public function test_it_merges_duplicate_product_lines_into_a_single_combined_quantity(): void
    {
        $product = Product::factory()->create(['price' => 50, 'tax_percentage' => 0, 'stock_on_hand' => 10]);

        $response = $this->postJson('/api/orders', [
            'customer' => ['name' => 'Duplicate Lines', 'email' => 'duplicate@example.com'],
            'products' => [
                ['product_id' => $product->id, 'quantity' => 1],
                ['product_id' => $product->id, 'quantity' => 2],
            ],
        ]);

        $response->assertCreated()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.quantity', 3)
            ->assertJsonPath('data.subtotal', 150);

        $this->assertDatabaseHas('products', ['id' => $product->id, 'stock_on_hand' => 7]);
    }
}
