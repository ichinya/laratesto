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
     * Canonical marker pattern (see the compatibility contract); the scanner's regex is
     * derived from it.
     */
    public const string PATTERN = 'laratesto-residual(code=%s, rule=%s, severity=%s): %s';

    /**
     * Marks the class with this code, unless it already carries a marker of the same
     * code — the contract's identity is `code + AST node`, so one class may carry
     * several distinct codes without any of them duplicating.
     *
     * @param non-empty-string $code Stable `[A-Z0-9_]+` residual code.
     * @param class-string $rule
     * @param non-empty-string $reason Human-readable reason, no closing comment marker.
     * @param non-empty-string $severity `manual` or `warning`.
     * @return bool Whether the marker was added (false = same code already marked).
     */
    public static function mark(Class_ $class, string $code, string $rule, string $reason, string $severity = 'manual'): bool
    {
        $needle = \sprintf('laratesto-residual(code=%s,', $code);

        foreach ($class->getComments() as $comment) {
            if (\str_contains($comment->getText(), $needle)) {
                return false;
            }
        }

        $comments = $class->getAttribute(AttributeKey::COMMENTS) ?? [];
        $comments[] = new Comment(\sprintf('/* ' . self::PATTERN . ' */', $code, $rule, $severity, $reason));
        $class->setAttribute(AttributeKey::COMMENTS, $comments);

        return true;
    }

    /**
     * Whether the node already carries a marker of this code.
     */
    public static function isMarked(Node $node, string $code): bool
    {
        $needle = \sprintf('laratesto-residual(code=%s,', $code);

        foreach ($node->getComments() as $comment) {
            if (\str_contains($comment->getText(), $needle)) {
                return true;
            }
        }

        return false;
    }
}
