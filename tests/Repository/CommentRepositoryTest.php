<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Model\Comment;
use App\Repository\CommentRepository;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Integration tests against a real MySQL database (blissim_test).
 *
 * The schema comes from sql/comments.sql, the same file as the application.
 * Each test runs in a transaction rolled back in tearDown(), so tests are isolated.
 * The PDO service is the one configured in config/services.yaml.
 */
final class CommentRepositoryTest extends KernelTestCase
{
    private \PDO $pdo;
    private CommentRepository $repository;

    public static function setUpBeforeClass(): void
    {
        $databaseName = $_SERVER['DB_NAME'] ?? '';
        // sql/comments.sql drops the table: never run it on another database
        if (!str_ends_with($databaseName, '_test')) {
            self::fail(sprintf('DB_NAME must end with "_test", got "%s".', $databaseName));
        }

        self::bootKernel();
        try {
            $pdo = self::getContainer()->get(\PDO::class);
        } catch (\PDOException $e) {
            self::markTestSkipped(sprintf('Test database unavailable (%s). Run: docker compose up -d database', $e->getMessage()));
        }

        $pdo->exec((string) file_get_contents(dirname(__DIR__, 2).'/sql/comments.sql'));
        // Start from an empty table: the demo comments are not test fixtures
        $pdo->exec('DELETE FROM comments');
        self::ensureKernelShutdown();
    }

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        $this->pdo = $container->get(\PDO::class);
        $this->repository = $container->get(CommentRepository::class);

        $this->pdo->beginTransaction();
    }

    protected function tearDown(): void
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }

        parent::tearDown();
    }

    public function testAddPersistsCommentAndReturnsIt(): void
    {
        $comment = $this->repository->add(1, 'Camille', 'Très bon produit.');

        self::assertGreaterThan(0, $comment->id);
        self::assertSame(1, $comment->productId);
        self::assertSame('Camille', $comment->author);
        self::assertSame('Très bon produit.', $comment->content);
        self::assertFalse($comment->isEdited());
        self::assertEquals($comment, $this->repository->findOneForProduct($comment->id, 1));
    }

    public function testValuesAreStoredLiterally(): void
    {
        // Prepared statements: SQL and HTML are stored as plain text
        $content = "'; DROP TABLE comments; -- <script>alert(1)</script>";

        $comment = $this->repository->add(1, "O'Connor", $content);

        self::assertSame("O'Connor", $comment->author);
        self::assertSame($content, $comment->content);
        self::assertCount(1, $this->repository->findByProduct(1));
    }

    public function testFindByProductReturnsOnlyThisProductMostRecentFirst(): void
    {
        $oldest = $this->insert(1, 'Ancien', '-2 days');
        $newest = $this->insert(1, 'Récent', '-1 hour');
        $middle = $this->insert(1, 'Milieu', '-1 day');
        $this->insert(2, 'Autre produit', 'now');

        $comments = $this->repository->findByProduct(1);

        self::assertSame([$newest, $middle, $oldest], array_map(static fn (Comment $c): int => $c->id, $comments));
    }

    public function testFindByProductReturnsEmptyListWhenNoComment(): void
    {
        self::assertSame([], $this->repository->findByProduct(42));
    }

    public function testFindOneForProductReturnsNullForAnotherProduct(): void
    {
        $id = $this->insert(1, 'Camille', 'now');

        self::assertNull($this->repository->findOneForProduct($id, 2));
        self::assertNull($this->repository->findOneForProduct(999999, 1));
    }

    public function testUpdateModifiesComment(): void
    {
        $id = $this->insert(1, 'Camille', '-1 day');

        $updated = $this->repository->update($id, 1, 'Camille M.', 'Contenu modifié');

        self::assertNotNull($updated);
        self::assertSame('Camille M.', $updated->author);
        self::assertSame('Contenu modifié', $updated->content);
        self::assertTrue($updated->isEdited());
    }

    public function testUpdateWithSameValuesStillReturnsComment(): void
    {
        // MySQL rowCount() is 0 here: the repository must not treat it as "not found"
        $id = $this->insert(1, 'Camille', 'now');

        self::assertNotNull($this->repository->update($id, 1, 'Camille', 'Contenu'));
    }

    public function testUpdateDoesNothingForAnotherProduct(): void
    {
        $id = $this->insert(1, 'Camille', 'now');

        self::assertNull($this->repository->update($id, 2, 'Pirate', 'Modifié'));
        self::assertSame('Camille', $this->repository->findOneForProduct($id, 1)?->author);
    }

    public function testDeleteRemovesComment(): void
    {
        $id = $this->insert(1, 'Camille', 'now');

        self::assertTrue($this->repository->delete($id, 1));
        self::assertNull($this->repository->findOneForProduct($id, 1));
        self::assertFalse($this->repository->delete($id, 1));
    }

    public function testDeleteDoesNothingForAnotherProduct(): void
    {
        $id = $this->insert(1, 'Camille', 'now');

        self::assertFalse($this->repository->delete($id, 2));
        self::assertNotNull($this->repository->findOneForProduct($id, 1));
    }

    public function testDatabaseRejectsBlankAuthor(): void
    {
        // Last line of defence when the application validation is bypassed
        $this->expectException(\PDOException::class);

        $this->repository->add(1, '   ', 'Contenu');
    }

    /**
     * Inserts a comment with a controlled creation date, to test ordering.
     */
    private function insert(int $productId, string $author, string $createdAt): int
    {
        $date = (new \DateTimeImmutable($createdAt))->format('Y-m-d H:i:s');

        $statement = $this->pdo->prepare(
            'INSERT INTO comments (product_id, author, content, created_at, updated_at)
             VALUES (:product_id, :author, :content, :created_at, :updated_at)'
        );
        $statement->execute([
            'product_id' => $productId,
            'author' => $author,
            'content' => 'Contenu',
            'created_at' => $date,
            // Same date: the comment has never been edited.
            // A named parameter cannot be used twice with native prepared statements.
            'updated_at' => $date,
        ]);

        return (int) $this->pdo->lastInsertId();
    }
}
