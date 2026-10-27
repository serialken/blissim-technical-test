<?php

declare(strict_types=1);

namespace App\Model;

/**
 * Read-only product coming from the external API.
 * The price is only displayed, never used in calculations,
 * so a float is acceptable here (unlike the SQL part, which uses DECIMAL).
 */
final readonly class Product
{
    public function __construct(
        public int $id,
        public string $title,
        public float $price,
        public string $description,
        public string $category,
        public string $image,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     *
     * @throws \InvalidArgumentException when a required field is missing or has the wrong type
     */
    public static function fromArray(array $data): self
    {
        if (!isset($data['id']) || !is_int($data['id'])) {
            throw new \InvalidArgumentException('missing or invalid "id"');
        }
        if (!isset($data['title']) || !is_string($data['title'])) {
            throw new \InvalidArgumentException('missing or invalid "title"');
        }
        if (!isset($data['price']) || !is_numeric($data['price'])) {
            throw new \InvalidArgumentException('missing or invalid "price"');
        }

        return new self(
            id: $data['id'],
            title: $data['title'],
            price: (float) $data['price'],
            description: is_string($data['description'] ?? null) ? $data['description'] : '',
            category: is_string($data['category'] ?? null) ? $data['category'] : '',
            image: is_string($data['image'] ?? null) ? $data['image'] : '',
        );
    }
}