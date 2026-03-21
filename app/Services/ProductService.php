<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\ProductRepository;
use InvalidArgumentException;

final class ProductService
{
    public function __construct(private readonly ProductRepository $products)
    {
    }

    public function addProduct(string $productName, string $sku, float $price, int $stockQuantity, int $categoryId): int
    {
        $this->validateInput($productName, $sku, $price, $stockQuantity, $categoryId);

        return $this->products->create(trim($productName), trim($sku), $price, $stockQuantity, $categoryId);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listProducts(?string $query = null): array
    {
        return $this->products->listAll($query !== null ? trim($query) : null);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getProduct(int $id): ?array
    {
        if ($id < 1) {
            return null;
        }

        return $this->products->findById($id);
    }

    public function editProduct(int $id, string $productName, string $sku, float $price, int $stockQuantity, int $categoryId): void
    {
        if ($id < 1) {
            throw new InvalidArgumentException('Invalid product id.');
        }

        $this->validateInput($productName, $sku, $price, $stockQuantity, $categoryId);

        $ok = $this->products->update($id, trim($productName), trim($sku), $price, $stockQuantity, $categoryId);
        if (!$ok) {
            throw new InvalidArgumentException('Failed to update product.');
        }
    }

    public function deactivateProduct(int $id): void
    {
        if ($id < 1) {
            throw new InvalidArgumentException('Invalid product id.');
        }

        $this->products->setActive($id, false);
    }

    public function activateProduct(int $id): void
    {
        if ($id < 1) {
            throw new InvalidArgumentException('Invalid product id.');
        }

        $this->products->setActive($id, true);
    }

    private function validateInput(string $productName, string $sku, float $price, int $stockQuantity, int $categoryId): void
    {
        $productName = trim($productName);
        $sku = trim($sku);

        if ($productName === '') {
            throw new InvalidArgumentException('Product name is required.');
        }

        if ($sku === '') {
            throw new InvalidArgumentException('SKU is required.');
        }

        if ($price < 0) {
            throw new InvalidArgumentException('Price cannot be negative.');
        }

        if ($stockQuantity < 0) {
            throw new InvalidArgumentException('Stock quantity cannot be negative.');
        }

        if ($categoryId < 1) {
            throw new InvalidArgumentException('Category is required.');
        }
    }
}

