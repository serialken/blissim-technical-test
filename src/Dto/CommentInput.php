<?php

declare(strict_types=1);

namespace App\Dto;

use App\Model\Comment;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Data submitted by the comment form (create and edit), validated before any database call.
 * Limits match the `comments` table: author VARCHAR(100), content 1 to 2000 characters.
 */
final readonly class CommentInput
{
    public function __construct(
        #[Assert\NotBlank(message: 'Le nom est obligatoire.')]
        #[Assert\Length(max: 100, maxMessage: 'Le nom ne doit pas dépasser {{ limit }} caractères.')]
        public string $author = '',

        #[Assert\NotBlank(message: 'Le commentaire est obligatoire.')]
        #[Assert\Length(max: 2000, maxMessage: 'Le commentaire ne doit pas dépasser {{ limit }} caractères.')]
        public string $content = '',
    ) {
    }

    public static function fromRequest(Request $request): self
    {
        return new self(
            author: trim($request->getPayload()->getString('author')),
            content: trim($request->getPayload()->getString('content')),
        );
    }

    public static function fromComment(Comment $comment): self
    {
        return new self($comment->author, $comment->content);
    }
}
