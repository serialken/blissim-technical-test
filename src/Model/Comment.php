<?php

declare(strict_types=1);

namespace App\Model;

final readonly class Comment
{
    public function __construct(
        public int $id,
        public int $productId,
        public string $author,
        public string $content,
        public \DateTimeImmutable $createdAt,
        public \DateTimeImmutable $updatedAt,
    ) {
    }

    /**
     * Builds a comment from a database row of the `comments` table.
     *
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        return new self(
            id: (int) $row['id'],
            productId: (int) $row['product_id'],
            author: (string) $row['author'],
            content: (string) $row['content'],
            createdAt: new \DateTimeImmutable((string) $row['created_at']),
            updatedAt: new \DateTimeImmutable((string) $row['updated_at']),
        );
    }

    public function isEdited(): bool
    {
        return $this->updatedAt > $this->createdAt;
    }
}