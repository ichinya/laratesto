<?php

declare(strict_types=1);

namespace Laratesto\Rector\Tests\Residuals;

use Laratesto\Rector\Residuals\ResidualMarker;
use PhpParser\Node\Stmt\Class_;
use PhpParser\ParserFactory;
use Testo\Assert;
use Testo\Test;

/**
 * Marker reconciliation (PR #8 fix plan, stage 5.2): a marker is created once,
 * refreshed when the constructs behind it change, and untouched — byte-identical —
 * when they do not.
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
