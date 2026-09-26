<?php

namespace App\Interfaces;

use App\Models\Product;
use Illuminate\Database\Eloquent\Collection;

interface ProductRepositoryInterface 
{
    public function all(): Collection;
    
    public function findById(int $id): ?Product;

    public function create(array $data): Product;

    public function update(int $id, array $data): bool;

    public function delete(int $id):bool;

}


?>