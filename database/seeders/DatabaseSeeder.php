<?php

namespace Database\Seeders;

use App\Models\Customer;
use App\Models\Product;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        collect([
            [
                'name' => 'Colgate Toothpaste',
                'code' => 'SKU-COLGATE',
                'price' => 54.00,
                'tax_percentage' => 18.00,
                'stock_on_hand' => 12,
            ],
            [
                'name' => 'Parle-G Biscuit',
                'code' => 'SKU-PARLEG',
                'price' => 10.00,
                'tax_percentage' => 5.00,
                'stock_on_hand' => 9,
            ],
            [
                'name' => 'Milk 1L',
                'code' => 'SKU-MILK1L',
                'price' => 62.00,
                'tax_percentage' => 5.00,
                'stock_on_hand' => 4,
            ],
            [
                'name' => 'Eggs (12)',
                'code' => 'SKU-EGGS12',
                'price' => 90.00,
                'tax_percentage' => 5.00,
                'stock_on_hand' => 2,
            ],
            [
                'name' => 'Shampoo 180ml',
                'code' => 'SKU-SHAMPOO',
                'price' => 145.00,
                'tax_percentage' => 18.00,
                'stock_on_hand' => 20,
            ],
        ])->each(
            fn (array $product) => Product::factory()
                ->state($product)
                ->create()
        );

        collect([
            [
                'name' => 'Thomas Anderson',
                'email' => 'thomas@example.com',
            ],
            [
                'name' => 'Priya Kumar',
                'email' => 'priya@example.com',
            ],
            [
                'name' => 'Arun Raj',
                'email' => 'arun@example.com',
            ],
        ])->each(
            fn (array $customer) => Customer::factory()
                ->state($customer)
                ->create()
        );
    }
}