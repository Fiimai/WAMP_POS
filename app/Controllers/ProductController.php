<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\Product;

final class ProductController
{
    public function index(?string $search = null): array
    {
        return Product::activeList($search);
    }
}
