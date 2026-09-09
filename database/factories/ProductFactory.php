<?php

namespace Database\Factories;

use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Product> */
class ProductFactory extends Factory
{
    protected $model = Product::class;

    public function definition(): array
    {
        return [
            'name' => fake()->unique()->words(2, true),
            'code' => strtoupper(fake()->unique()->bothify('SKU-####')),
            'price' => fake()->randomFloat(2, 10, 500),
            'tax_percentage' => fake()->randomElement([0, 5, 12, 18]),
            'stock_on_hand' => fake()->numberBetween(2, 50),
        ];
    }
}
