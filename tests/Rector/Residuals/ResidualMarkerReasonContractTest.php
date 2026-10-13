<?php

declare(strict_types=1);

namespace Laratesto\Rector\Tests\Residuals;

use Laratesto\Rector\Residuals\ResidualMarker;
use PhpParser\Node\Stmt\Class_;
use PhpParser\ParserFactory;
use Testo\Assert;
use Testo\Test;

/**
 * The reason contract (final review mn5): mark() enforces the documented reason
 * grammar — non-empty, no `*`, no `laratesto-residual(` substring. A violating
 * reason would render a comment that is never owned (MARKER_COMMENT_REGEX forbids
 * `*`; a `; laratesto-residual(` inside the reason parses as the next
 * contribution), so every run would append another duplicate marker. mark()
 * therefore fails closed, before the node is touched.
 */
final class ResidualMarkerReasonContractTest
{
    #[Test]
    public function aContractualReasonIsAcceptedAndStaysIdempotent(): void
    {
        $class = $this->class('final class DemoTest {}');

        // Contract-legal stress case: separators, parens and the marker verb itself
        // without the opening paren all survive the scanner round-trip.
        $legal = 'calls ; separated, (grouped), laratesto-residual without paren';

        Assert::true(ResidualMarker::mark($class, 'LIFECYCLE_UNSUPPORTED', 'Rule\A', $legal));
        Assert::true(ResidualMarker::isMarked($class, 'LIFECYCLE_UNSUPPORTED'));
        Assert::same(
            $class->getComments()[0]?->getText(),
            '/* laratesto-residual(code=LIFECYCLE_UNSUPPORTED, rule=Rule\A, severity=manual): ' . $legal . ' */',
        );
    }

    #[Test]
    public function aReasonContainingAnAsteriskIsRejected(): void
    {
        $class = $this->class('/** user doc */ final class DemoTest {}');

        $this->assertRejected($class, 'wildcard * reason');
    }

    #[Test]
    public function aReasonContainingTheMarkerOpeningIsRejected(): void
    {
        $class = $this->class('/** user doc */ final class DemoTest {}');

        $this->assertRejected($class, 'see laratesto-residual(code=OTHER, rule=Rule\X) docs');
    }

    #[Test]
    public function aReasonThatOnlyStartsAMarkerContributionIsRejected(): void
    {
        $class = $this->class('final class DemoTest {}');

        // The adversarial tail: the rendered reason ends in `; laratesto-residual(`,
        // which the scanner grammar reads as the start of a second contribution.
        $this->assertRejected($class, 'broken; laratesto-residual(');
    }

    #[Test]
    public function anEmptyReasonIsRejected(): void
    {
        $class = $this->class('final class DemoTest {}');

        $this->assertRejected($class, '');
    }

    /**
     * Fails closed: the rejection happens before any mutation, the node keeps zero
     * markers, and the next contractual call starts from a clean slate instead of
     * finding an unownable stale marker and appending duplicates forever.
     *
     * @param non-empty-string|'' $reason
     */
    private function assertRejected(Class_ $class, string $reason): void
    {
        $docBefore = $class->getDocComment()?->getText();
        $commentsBefore = $class->getComments();

        try {
            ResidualMarker::mark($class, 'HTTP_UNSUPPORTED_SIGNATURE', 'Rule\A', $reason);

            Assert::fail('Expected the reason contract to reject ' . \var_export($reason, true) . '.');
        } catch (\InvalidArgumentException $rejection) {
            Assert::true(\str_contains($rejection->getMessage(), 'Invalid residual reason'), $rejection->getMessage());
        }

        Assert::false(ResidualMarker::isMarked($class, 'HTTP_UNSUPPORTED_SIGNATURE'), 'A rejected reason must fail closed: no marker may exist.');
        Assert::same($this->markerCount($class), 0);
        Assert::same($class->getComments(), $commentsBefore, 'A rejected reason must not touch any comment.');
        Assert::same($class->getDocComment()?->getText(), $docBefore, 'A rejected reason must not touch the docblock.');

        Assert::true(ResidualMarker::mark($class, 'HTTP_UNSUPPORTED_SIGNATURE', 'Rule\A', 'the real reason'));
        Assert::same(1, $this->markerCount($class), 'The next run must create exactly one marker, not duplicate a stale one.');
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
