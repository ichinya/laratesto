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
 * attach it, {@see ResidualsScanner} collects it.
 *
 * Marker contract: the marker's identity is `code + AST node` — a class carries at
 * most one marker comment per code. A marker holds one contribution per rule; when
 * several rules fail the same code on one class, their contributions merge into the
 * single comment instead of overwriting each other. Contributions render sorted by
 * rule class-string, so any arrival order produces identical bytes, and re-marking
 * an unchanged contribution set is a no-op — repeated runs cannot bloat the code.
 * A rule's re-mark replaces only its own contribution (reconciliation); a
 * contribution goes away only with the whole comment, which the user deletes
 * together with the fix.
 *
 * Ownership: a comment is owned only when its entire text is the canonical marker
 * form ({@see MARKER_COMMENT_REGEX}) of one code. Documentation that merely mentions
 * the marker substring — a docblock example, a line comment — is user prose: never
 * reconciled, never blocking, never rewritten.
 */
final class ResidualMarker
{
    /**
     * Canonical contribution shape (see the compatibility contract).
     */
    public const PATTERN = 'laratesto-residual(code=%s, rule=%s, severity=%s): %s';

    /**
     * Canonical contribution grammar; the scanner parses with the same expression, so
     * everything {@see mark()} writes is scanner-readable. A reason ends at the
     * `; ` separator before the next contribution or at the closing comment marker,
     * so parsed reasons never accumulate separator bytes.
     */
    public const MARKER_REGEX =
        '/laratesto-residual\(code=(?<code>[^,)]+),\s*rule=(?<rule>[^,)]+)(?:,\s*severity=(?<severity>[^)]+))?\):\s*(?<reason>[^*]*?)(?=;\s*laratesto-residual\(|\s*\*\/)/';

    /**
     * One canonical contribution, capture-free, so the whole-comment grammar below
     * can repeat it (PCRE forbids duplicate named groups). Kept in step with
     * MARKER_REGEX; the reconciliation tests round-trip every rendered marker
     * through both expressions.
     */
    private const CONTRIBUTION_SHAPE =
        'laratesto-residual\(code=[A-Z0-9_]+,\s*rule=[^,)]+(?:,\s*severity=[^)]+)?\):\s*[^*]*?(?=;\s*laratesto-residual\(|\s*\*\/)';

    /**
     * The entire canonical marker comment: the opening `/* ` marker plus one or
     * more contributions of a single code joined by `'; '` plus the closing
     * comment marker. Ownership requires this form to span the whole comment —
     * documentation that merely mentions `laratesto-residual(code=…)` inside a
     * docblock or line comment is prose, never an owned marker.
     */
    public const MARKER_COMMENT_REGEX =
        '/\/\* ' . self::CONTRIBUTION_SHAPE . '(?:; ' . self::CONTRIBUTION_SHAPE . ')* \*\//';

    /**
     * Glue between rule contributions inside one marker comment.
     */
    private const CONTRIBUTION_SEPARATOR = '; ';

    /**
     * Marks the class with this code, merging into an existing marker of the same code.
     *
     * Only comments created under the contract — a comment whose entire text is the
     * canonical marker form ({@see MARKER_COMMENT_REGEX}) of this code — are merged
     * into or reconciled. Prose that merely mentions the marker substring is user
     * documentation: a fresh canonical marker is appended and the prose stays untouched.
     *
     * One marker comment per code: a second rule failing the same code merges its
     * contribution next to the existing one (canonical order, identical bytes for any
     * arrival order), never overwriting or losing the earlier rule's reason. When the
     * constructs behind this rule's contribution changed, only this rule's segment is
     * refreshed in place; when nothing changed, the call is a byte-identical no-op.
     *
     * @param non-empty-string $code Stable `[A-Z0-9_]+` residual code (see ResidualCode).
     * @param class-string $rule
     * @param non-empty-string $reason Human-readable reason, no `*` and no
     *        `laratesto-residual(` substring.
     * @param non-empty-string $severity `manual` or `warning`.
     * @return bool Whether the marker comment changed: created, merged with another
     *         rule's contribution, or this rule's own contribution reconciled.
     */
    public static function mark(Class_ $class, string $code, string $rule, string $reason, string $severity = 'manual'): bool
    {
        $comments = $class->getAttribute(AttributeKey::COMMENTS) ?? [];

        foreach ($comments as $index => $comment) {
            if (! $comment instanceof Comment || ! self::owned($comment->getText(), $code)) {
                continue;
            }

            $contributions = self::contributions($comment->getText(), $code);
            $contributions[$rule] = ['reason' => $reason, 'severity' => $severity];

            $marker = self::render($code, $contributions);

            if ($marker === $comment->getText()) {
                return false;
            }

            // Reconciliation or merge: rewrite in place, keeping the marker's position
            // and every neighbouring comment untouched.
            $comments[$index] = new Comment($marker);
            $class->setAttribute(AttributeKey::COMMENTS, $comments);

            return true;
        }

        $comments[] = new Comment(self::render($code, [$rule => ['reason' => $reason, 'severity' => $severity]]));
        $class->setAttribute(AttributeKey::COMMENTS, $comments);

        return true;
    }

    /**
     * Whether the node already carries an owned marker of this code. Documentation
     * that merely mentions the marker substring never counts — a rule must not be
     * blocked by the user's own words.
     */
    public static function isMarked(Node $node, string $code): bool
    {
        foreach ($node->getComments() as $comment) {
            if (self::owned($comment->getText(), $code)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the entire text is one canonical marker comment of exactly this code:
     * the whole-comment form must match, every contribution must carry the same
     * code (the contract never mixes codes in one comment), and that code must be
     * this one. Anything else — docblock examples, line comments, hand-merged
     * multi-code comments — was not created under the contract.
     */
    private static function owned(string $text, string $code): bool
    {
        if (\preg_match(self::MARKER_COMMENT_REGEX, $text, $match) !== 1 || $match[0] !== $text) {
            return false;
        }

        if (\preg_match_all(self::MARKER_REGEX, $text, $matches) === false) {
            return false;
        }

        $codes = [];

        foreach ($matches['code'] as $matchedCode) {
            $codes[] = \trim((string) $matchedCode);
        }

        return $codes !== [] && \array_unique($codes) === [$code];
    }

    /**
     * The contributions of one marker comment, keyed by rule. Only segments carrying
     * this marker's code are merged — the writer never mixes codes in one comment.
     *
     * @return array<class-string, array{reason: string, severity: string}>
     */
    private static function contributions(string $text, string $code): array
    {
        if (\preg_match_all(self::MARKER_REGEX, $text, $matches) === false) {
            return [];
        }

        $contributions = [];

        foreach ($matches['rule'] as $index => $rule) {
            if (\trim((string) $matches['code'][$index]) !== $code) {
                continue;
            }

            $contributions[\trim((string) $rule)] = [
                'reason' => \trim((string) $matches['reason'][$index]),
                'severity' => \trim((string) ($matches['severity'][$index] ?? 'manual')),
            ];
        }

        return $contributions;
    }

    /**
     * The canonical marker comment: contributions sorted by rule class-string and
     * joined by the contribution separator, so the rendering is independent of the
     * order the rules marked in.
     *
     * @param array<class-string, array{reason: string, severity: string}> $contributions
     */
    private static function render(string $code, array $contributions): string
    {
        \ksort($contributions, SORT_STRING);

        $segments = [];

        foreach ($contributions as $rule => ['reason' => $reason, 'severity' => $severity]) {
            $segments[] = \sprintf(self::PATTERN, $code, $rule, $severity, $reason);
        }

        return '/* ' . \implode(self::CONTRIBUTION_SEPARATOR, $segments) . ' */';
    }
}
