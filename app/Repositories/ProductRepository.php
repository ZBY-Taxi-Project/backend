<?php


namespace App\Repositories;

use App\Interfaces\ProductRepositoryInterface;
use App\Models\Product;
use Illuminate\Database\Eloquent\Collection;

class ProductRepository implements ProductRepositoryInterface
{
    /**
     * Retrieve all products from the database
     */
    public function all(): Collection
    {
        return Product::all();
    }

    /**
     * Find a product by its primary key ID, or return null if not found
     */
    public function findById(int $id): ?Product
    {
        return Product::find($id);
    }

    /**
     * Create and return a new product with validated data.
     */
    public function create(array $data): Product
    {
        return Product::create($data);
    }

    /**
     * Update an existing product by ID
     */
    public function update(int $id, array $data): bool
    {
        $product = Product::find($id);

        if (!$product) {
            return false;
        }

        return $product->update($data);
    }

    /**
     * Delete a product by ID.
     */
    public function delete(int $id): bool
    {
        $product = Product::find($id);

        if (!$product) {
            return false;
        }

        return (bool) $product->delete();
    }


}