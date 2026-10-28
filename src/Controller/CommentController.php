<?php

declare(strict_types=1);

namespace App\Controller;

use App\Dto\CommentInput;
use App\Model\Comment;
use App\Model\Product;
use App\Repository\CommentRepository;
use App\Service\ProductApiClient;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Create, edit and delete the comments of a product.
 * Every write is a POST protected by a CSRF token, followed by a redirect (Post/Redirect/Get).
 */
#[Route('/products/{productId}/comments', requirements: ['productId' => Requirement::POSITIVE_INT])]
final class CommentController extends AbstractController
{
    private const CSRF_COMMENT = 'comment';
    private const CSRF_DELETE = 'delete-comment-%d';

    public function __construct(
        private readonly ProductApiClient $products,
        private readonly CommentRepository $comments,
        private readonly ValidatorInterface $validator,
    ) {
    }

    #[Route('', name: 'comment_create', methods: ['POST'])]
    public function create(Request $request, int $productId): Response
    {
        $product = $this->findProductOr404($productId);
        $this->checkCsrfToken($request, self::CSRF_COMMENT);

        $input = CommentInput::fromRequest($request);
        $errors = $this->validate($input);

        if ([] !== $errors) {
            // Same page as the product detail, with the submitted values and the errors
            return $this->render('products/show.html.twig', [
                'product' => $product,
                'comments' => $this->comments->findByProduct($productId),
                'input' => $input,
                'errors' => $errors,
            ], new Response(status: Response::HTTP_UNPROCESSABLE_ENTITY));
        }

        $this->comments->add($productId, $input->author, $input->content);
        $this->addFlash('success', 'Votre commentaire a été ajouté.');

        return $this->redirectToRoute('product_show', ['id' => $productId]);
    }

    #[Route('/{id}/edit', name: 'comment_edit', requirements: ['id' => Requirement::POSITIVE_INT], methods: ['GET', 'POST'])]
    public function edit(Request $request, int $productId, int $id): Response
    {
        $product = $this->findProductOr404($productId);
        $comment = $this->findCommentOr404($id, $productId);

        $input = CommentInput::fromComment($comment);
        $errors = [];

        if ($request->isMethod('POST')) {
            $this->checkCsrfToken($request, self::CSRF_COMMENT);

            $input = CommentInput::fromRequest($request);
            $errors = $this->validate($input);

            if ([] === $errors) {
                $this->comments->update($id, $productId, $input->author, $input->content)
                    ?? throw $this->createNotFoundException(sprintf('Comment %d not found.', $id));
                $this->addFlash('success', 'Le commentaire a été modifié.');

                return $this->redirectToRoute('product_show', ['id' => $productId]);
            }
        }

        return $this->render('comments/edit.html.twig', [
            'product' => $product,
            'comment' => $comment,
            'input' => $input,
            'errors' => $errors,
        ], new Response(status: [] === $errors ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY));
    }

    #[Route('/{id}/delete', name: 'comment_delete', requirements: ['id' => Requirement::POSITIVE_INT], methods: ['POST'])]
    public function delete(Request $request, int $productId, int $id): Response
    {
        $this->checkCsrfToken($request, sprintf(self::CSRF_DELETE, $id));

        if (!$this->comments->delete($id, $productId)) {
            throw $this->createNotFoundException(sprintf('Comment %d not found.', $id));
        }
        $this->addFlash('success', 'Le commentaire a été supprimé.');

        return $this->redirectToRoute('product_show', ['id' => $productId]);
    }

    /**
     * @return array<string, string> first error message per field
     */
    private function validate(CommentInput $input): array
    {
        $errors = [];
        foreach ($this->validator->validate($input) as $violation) {
            $errors[$violation->getPropertyPath()] ??= (string) $violation->getMessage();
        }

        return $errors;
    }

    private function checkCsrfToken(Request $request, string $tokenId): void
    {
        if (!$this->isCsrfTokenValid($tokenId, $request->getPayload()->getString('_token'))) {
            throw new AccessDeniedHttpException('Invalid CSRF token.');
        }
    }

    private function findProductOr404(int $id): Product
    {
        return $this->products->find($id)
            ?? throw $this->createNotFoundException(sprintf('Product %d not found.', $id));
    }

    private function findCommentOr404(int $id, int $productId): Comment
    {
        return $this->comments->findOneForProduct($id, $productId)
            ?? throw $this->createNotFoundException(sprintf('Comment %d not found.', $id));
    }
}
