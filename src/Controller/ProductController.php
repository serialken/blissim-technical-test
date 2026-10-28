<?php

declare(strict_types=1);

namespace App\Controller;

use App\Dto\CommentInput;
use App\Model\Product;
use App\Repository\CommentRepository;
use App\Service\ProductApiClient;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;

/**
 * Product catalog: list and detail page with its comments.
 * API errors (ProductApiException) are turned into a 503 page by ServiceUnavailableListener.
 */
final class ProductController extends AbstractController
{
    public function __construct(
        private readonly ProductApiClient $products,
        private readonly CommentRepository $comments,
    ) {
    }

    #[Route('/', name: 'product_index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('products/index.html.twig', [
            'products' => $this->products->findAll(),
        ]);
    }

    #[Route('/products/{id}', name: 'product_show', requirements: ['id' => Requirement::POSITIVE_INT], methods: ['GET'])]
    public function show(int $id): Response
    {
        return $this->render('products/show.html.twig', [
            'product' => $this->findProductOr404($id),
            'comments' => $this->comments->findByProduct($id),
            'input' => new CommentInput(),
            'errors' => [],
        ]);
    }

    private function findProductOr404(int $id): Product
    {
        return $this->products->find($id)
            ?? throw $this->createNotFoundException(sprintf('Product %d not found.', $id));
    }
}
