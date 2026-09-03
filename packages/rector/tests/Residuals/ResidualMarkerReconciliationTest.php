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

        $residuals = (new ResidualsScanner())->scan('Demo.php', $this->markerText($class));

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
}
