<?php

declare(strict_types=1);

namespace Laratesto\Rector\Residuals;

use PhpParser\Comment;
use PhpParser\Node;
use PhpParser\Node\Stmt\Class_;
use Rector\NodeTypeResolver\Node\AttributeKey;

/**
 * Attaches the canonical residual marker to a class node.
 *
 * The marker is the single source of truth for "not migrated automatically": rules
 * attach it, {@see ResidualsScanner} collects it. Idempotent by contract — a class
 * carrying any laratesto-residual marker is never given a second one (replace-or-skip
 * from the ticket-04 spec), so repeated runs cannot bloat the code.
 */
final class ResidualMarker
{
    /**
     * Canonical marker pattern; the scanner's regex is derived from it.
     */
    public const string PATTERN = 'laratesto-residual(rule=%s): %s';

    /**
     * Marks the class, unless it already carries a residual marker.
     *
     * @param class-string $rule
     * @param non-empty-string $reason Human-readable reason, no closing comment marker.
     * @return bool Whether the marker was added (false = already marked, skipped).
     */
    public static function mark(Class_ $class, string $rule, string $reason): bool
    {
        foreach ($class->getComments() as $comment) {
            if (\str_contains($comment->getText(), 'laratesto-residual')) {
                return false;
            }
        }

        $comments = $class->getAttribute(AttributeKey::COMMENTS) ?? [];
        $comments[] = new Comment(\sprintf('/* ' . self::PATTERN . ' */', $rule, $reason));
        $class->setAttribute(AttributeKey::COMMENTS, $comments);

        return true;
    }

    /**
     * Whether the node already carries a residual marker.
     */
    public static function isMarked(Node $node): bool
    {
        foreach ($node->getComments() as $comment) {
            if (\str_contains($comment->getText(), 'laratesto-residual')) {
                return true;
            }
        }

        return false;
    }
}
