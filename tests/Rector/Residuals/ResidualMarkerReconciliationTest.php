<?php

declare(strict_types=1);

namespace Laratesto\Rector\Tests\Residuals;

use Laratesto\Rector\Residuals\ResidualMarker;
use Laratesto\Rector\Residuals\ResidualsScanner;
use PhpParser\Node\Stmt\Class_;
use PhpParser\ParserFactory;
use Rector\NodeTypeResolver\Node\AttributeKey;
use Testo\Assert;
use Testo\Test;

/**
 * Marker reconciliation (PR #8 fix plan, stage 5.2 + review point 3): a marker is
 * created once, refreshed when the constructs behind it change, and untouched —
 * byte-identical — when they do not. Several rules failing the same code on one
 * class merge into the single marker comment without losing earlier reasons.
 * Comments that merely mention the marker substring — docblocks, line comments,
 * hand-merged multi-code comments — are user documentation: never owned, never
 * reconciled, never rewritten (M5 hardening).
 */
final class ResidualMarkerReconciliationTest
{
    #[Test]
    public function aNewMarkerIsAdded(): void
    {
        $class = $this->class('final class DemoTest {}');

        Assert::true(ResidualMarker::mark($class, 'LIFECYCLE_UNSUPPORTED', 'Rule\A', 'the old reason'));
        Assert::true(ResidualMarker::isMarked($class, 'LIFECYCLE_UNSUPPORTED'));
        Assert::same(1, $this->markerCount($class));
        Assert::string($this->markerText($class))->contains('the old reason');
    }

    #[Test]
    public function anIdenticalMarkerIsANoOp(): void
    {
        $class = $this->class('final class DemoTest {}');

        ResidualMarker::mark($class, 'LIFECYCLE_UNSUPPORTED', 'Rule\A', 'the reason');

        Assert::false(ResidualMarker::mark($class, 'LIFECYCLE_UNSUPPORTED', 'Rule\A', 'the reason'));
        Assert::same(1, $this->markerCount($class));
    }

    #[Test]
    public function aChangedReasonIsReconciledInPlace(): void
    {
        $class = $this->class('/** user doc */ final class DemoTest {}');

        ResidualMarker::mark($class, 'HTTP_UNSUPPORTED_SIGNATURE', 'Rule\A', 'the old reason');

        Assert::true(ResidualMarker::mark($class, 'HTTP_UNSUPPORTED_SIGNATURE', 'Rule\A', 'the new reason'));

        Assert::same(1, $this->markerCount($class), 'Reconciliation must not duplicate the marker.');
        Assert::string($this->markerText($class))->contains('the new reason');
        Assert::string($this->markerText($class))->notContains('the old reason');
        Assert::string($class->getDocComment()?->getText() ?? '')->contains('user doc');
    }

    #[Test]
    public function distinctCodesCoexistWithoutDuplication(): void
    {
        $class = $this->class('final class DemoTest {}');

        Assert::true(ResidualMarker::mark($class, 'LIFECYCLE_UNSUPPORTED', 'Rule\A', 'lifecycle reason'));
        Assert::false(ResidualMarker::mark($class, 'LIFECYCLE_UNSUPPORTED', 'Rule\A', 'lifecycle reason'));
        Assert::true(ResidualMarker::mark($class, 'HTTP_UNSUPPORTED_SIGNATURE', 'Rule\B', 'http reason'));
        Assert::true(ResidualMarker::mark($class, 'HTTP_UNSUPPORTED_SIGNATURE', 'Rule\B', 'http reason v2'));

        Assert::same(2, $this->markerCount($class));
        Assert::string($this->markerText($class))->contains('http reason v2');
    }

    #[Test]
    public function aSecondRuleMergesIntoTheSameMarkerWithoutLosingReasons(): void
    {
        $class = $this->class('final class DemoTest {}');

        Assert::true(ResidualMarker::mark($class, 'HTTP_UNSUPPORTED_SIGNATURE', 'Rule\B', 'detection reason'));
        Assert::true(ResidualMarker::mark($class, 'HTTP_UNSUPPORTED_SIGNATURE', 'Rule\A', 'base class reason'));

        Assert::same(1, $this->markerCount($class), 'One marker per code: the second rule must merge, not add a comment.');

        $residuals = (new ResidualsScanner())->scan('Demo.php', "<?php\n" . $this->markerText($class));

        Assert::count($residuals, 2, 'The scanner must report one finding per rule contribution.');

        Assert::same($residuals[0]?->rule, 'Rule\A', 'Contributions render sorted by rule, not by arrival order.');
        Assert::same($residuals[0]?->reason, 'base class reason');
        Assert::same($residuals[1]?->rule, 'Rule\B');
        Assert::same($residuals[1]?->reason, 'detection reason', 'The earlier rule reason must survive the merge.');
    }

    #[Test]
    public function mergedContributionsAreOrderIndependent(): void
    {
        $baseClassFirst = $this->class('final class DemoTest {}');
        $detectionFirst = $this->class('final class DemoTest {}');

        ResidualMarker::mark($baseClassFirst, 'HTTP_UNSUPPORTED_SIGNATURE', 'Rule\A', 'base class reason');
        ResidualMarker::mark($baseClassFirst, 'HTTP_UNSUPPORTED_SIGNATURE', 'Rule\B', 'detection reason');

        ResidualMarker::mark($detectionFirst, 'HTTP_UNSUPPORTED_SIGNATURE', 'Rule\B', 'detection reason');
        ResidualMarker::mark($detectionFirst, 'HTTP_UNSUPPORTED_SIGNATURE', 'Rule\A', 'base class reason');

        Assert::same(
            $this->markerText($baseClassFirst),
            $this->markerText($detectionFirst),
            'Any marking order must produce identical marker bytes.',
        );
    }

    #[Test]
    public function reMarkingAnUnchangedMergedSetIsANoOp(): void
    {
        $class = $this->class('final class DemoTest {}');

        ResidualMarker::mark($class, 'HTTP_UNSUPPORTED_SIGNATURE', 'Rule\A', 'base class reason');
        ResidualMarker::mark($class, 'HTTP_UNSUPPORTED_SIGNATURE', 'Rule\B', 'detection reason');
        $before = $this->markerText($class);

        Assert::false(ResidualMarker::mark($class, 'HTTP_UNSUPPORTED_SIGNATURE', 'Rule\A', 'base class reason'));
        Assert::false(ResidualMarker::mark($class, 'HTTP_UNSUPPORTED_SIGNATURE', 'Rule\B', 'detection reason'));

        Assert::same($this->markerText($class), $before);
        Assert::same(1, $this->markerCount($class));
    }

    #[Test]
    public function reconcilingOneRuleKeepsTheOtherRulesContributionByteIdentical(): void
    {
        $class = $this->class('final class DemoTest {}');

        ResidualMarker::mark($class, 'HTTP_UNSUPPORTED_SIGNATURE', 'Rule\A', 'the old reason');
        ResidualMarker::mark($class, 'HTTP_UNSUPPORTED_SIGNATURE', 'Rule\B', 'detection reason');

        Assert::true(ResidualMarker::mark($class, 'HTTP_UNSUPPORTED_SIGNATURE', 'Rule\A', 'the new reason'));

        Assert::same(1, $this->markerCount($class));

        $text = $this->markerText($class);
        Assert::string($text)->contains('the new reason');
        Assert::string($text)->notContains('the old reason');
        Assert::string($text)->contains('rule=Rule\B, severity=manual): detection reason', 'A rule reconciles only its own contribution.');
    }

    #[Test]
    public function aManuallyRemovedMarkerIsRecreatedWithoutStaleContributions(): void
    {
        $class = $this->class('final class DemoTest {}');

        ResidualMarker::mark($class, 'HTTP_UNSIGNED_SIGNATURE', 'Rule\B', 'other code reason');
        ResidualMarker::mark($class, 'HTTP_UNSUPPORTED_SIGNATURE', 'Rule\B', 'detection reason');

        // The manual stale-marker workflow: the user deletes the whole marker comment
        // together with the fix.
        $comments = $class->getAttribute(AttributeKey::COMMENTS) ?? [];
        $class->setAttribute(AttributeKey::COMMENTS, array_values(array_filter(
            $comments,
            static fn ($comment): bool => ! $comment instanceof \PhpParser\Comment
                || ! \str_contains($comment->getText(), 'laratesto-residual('),
        )));

        Assert::false(ResidualMarker::isMarked($class, 'HTTP_UNSUPPORTED_SIGNATURE'));

        Assert::true(ResidualMarker::mark($class, 'HTTP_UNSUPPORTED_SIGNATURE', 'Rule\A', 'fresh reason'));

        Assert::same(1, $this->markerCount($class));

        $text = $this->markerText($class);
        Assert::string($text)->contains('fresh reason');
        Assert::string($text)->notContains('Rule\B', 'A recreated marker must not carry stale contributions.');
        Assert::string($text)->notContains('detection reason');
    }

    #[Test]
    public function aDocblockMentioningTheMarkerIsNeverOwned(): void
    {
        $class = $this->class(<<<'PHP'
            /**
             * Migrator hint: to silence this rule by hand, drop a
             * laratesto-residual(code=LIFECYCLE_UNSUPPORTED, rule=Rule\A, severity=manual): pretend this is a marker
             * comment above the class.
             */
            final class DemoTest {}
            PHP);

        $docBefore = $class->getDocComment()?->getText();

        Assert::false(ResidualMarker::isMarked($class, 'LIFECYCLE_UNSUPPORTED'), 'Documentation must not block the rule as an owned marker.');

        Assert::true(ResidualMarker::mark($class, 'LIFECYCLE_UNSUPPORTED', 'Rule\A', 'the real reason'));

        Assert::same($class->getDocComment()?->getText(), $docBefore, 'The user docblock must stay byte-identical.');
        Assert::count($this->ownedMarkers($class), 1, 'A fresh canonical marker is appended, the docblock is not reused.');
        Assert::string($this->ownedMarkerText($class))->contains('the real reason');
        Assert::string($this->ownedMarkerText($class))->notContains('pretend this is a marker');
        Assert::true(ResidualMarker::isMarked($class, 'LIFECYCLE_UNSUPPORTED'));
    }

    #[Test]
    public function anInlineCommentMentioningTheMarkerIsNeverOwned(): void
    {
        $class = $this->class(<<<'PHP'
            // laratesto-residual(code=HTTP_UNSUPPORTED_SIGNATURE, rule=Rule\A, severity=manual): how a marker looks
            final class DemoTest {}
            PHP);

        $lineCommentBefore = $class->getComments()[0]?->getText();

        Assert::false(ResidualMarker::isMarked($class, 'HTTP_UNSUPPORTED_SIGNATURE'));

        Assert::true(ResidualMarker::mark($class, 'HTTP_UNSUPPORTED_SIGNATURE', 'Rule\B', 'the real reason'));

        Assert::same($class->getComments()[0]?->getText(), $lineCommentBefore, 'The user line comment must stay byte-identical.');
        Assert::count($this->ownedMarkers($class), 1);
        Assert::string($this->ownedMarkerText($class))->contains('Rule\B');
        Assert::string($this->ownedMarkerText($class))->notContains('how a marker looks');
    }

    #[Test]
    public function aHandMergedMultiCodeCommentIsNeverOwned(): void
    {
        $class = $this->class(<<<'PHP'
            /* laratesto-residual(code=LIFECYCLE_UNSUPPORTED, rule=Rule\A, severity=manual): first; laratesto-residual(code=HTTP_UNSUPPORTED_SIGNATURE, rule=Rule\B, severity=manual): second */
            final class DemoTest {}
            PHP);

        $mixedBefore = $class->getComments()[0]?->getText();

        Assert::false(ResidualMarker::isMarked($class, 'LIFECYCLE_UNSUPPORTED'), 'The contract never mixes codes, so the hand-merged comment owns neither.');
        Assert::false(ResidualMarker::isMarked($class, 'HTTP_UNSUPPORTED_SIGNATURE'));

        Assert::true(ResidualMarker::mark($class, 'LIFECYCLE_UNSUPPORTED', 'Rule\A', 'fresh reason'));

        Assert::same($class->getComments()[0]?->getText(), $mixedBefore, 'Reconciliation must append, never rewrite the foreign comment.');

        $fresh = '';

        foreach ($class->getComments() as $comment) {
            if ($comment->getText() !== $mixedBefore) {
                $fresh .= $comment->getText();
            }
        }

        Assert::string($fresh)->contains('fresh reason', 'A fresh canonical marker is appended for the new contribution.');
        Assert::string($fresh)->notContains(': first', 'The fresh marker must not absorb the hand-merged contributions.');
        Assert::true(ResidualMarker::isMarked($class, 'LIFECYCLE_UNSUPPORTED'));
    }

    #[Test]
    public function repeatedRunsOverAdversarialCommentsAreByteIdentical(): void
    {
        $class = $this->class(<<<'PHP'
            /**
             * Docs: laratesto-residual(code=LIFECYCLE_UNSUPPORTED, rule=Rule\A, severity=manual): example
             */
            final class DemoTest {}
            PHP);

        ResidualMarker::mark($class, 'LIFECYCLE_UNSUPPORTED', 'Rule\A', 'the reason');

        $before = $this->allCommentsText($class);

        Assert::false(ResidualMarker::mark($class, 'LIFECYCLE_UNSUPPORTED', 'Rule\A', 'the reason'), 'A repeated run must be a no-op.');
        Assert::same($this->allCommentsText($class), $before, 'Repeated runs must leave every comment byte-identical.');
    }

    private function class(string $code): Class_
    {
        $parser = (new ParserFactory())->createForNewestSupportedVersion();

        /** @var array<Class_> $statements */
        $statements = $parser->parse('<?php ' . $code);

        return $statements[0];
    }

    private function markerCount(Class_ $class): int
    {
        return \count(\array_filter(
            $class->getComments(),
            static fn($comment): bool => \str_contains($comment->getText(), 'laratesto-residual('),
        ));
    }

    private function markerText(Class_ $class): string
    {
        $text = '';

        foreach ($class->getComments() as $comment) {
            if (\str_contains($comment->getText(), 'laratesto-residual(')) {
                $text .= $comment->getText() . "\n";
            }
        }

        return $text;
    }

    /**
     * The comments whose entire text is one canonical marker comment — documentation
     * that merely mentions the marker substring does not count.
     *
     * @return list<string>
     */
    private function ownedMarkers(Class_ $class): array
    {
        $markers = [];

        foreach ($class->getComments() as $comment) {
            if (\preg_match(ResidualMarker::MARKER_COMMENT_REGEX, $comment->getText(), $match) === 1
                && $match[0] === $comment->getText()
            ) {
                $markers[] = $comment->getText();
            }
        }

        return $markers;
    }

    private function ownedMarkerText(Class_ $class): string
    {
        return \implode("\n", $this->ownedMarkers($class));
    }

    private function allCommentsText(Class_ $class): string
    {
        return \implode("\n", \array_map(
            static fn($comment): string => $comment->getText(),
            $class->getComments(),
        ));
    }
}
