<?php

namespace Tests\Unit\Issue10;

use PHPUnit\Framework\TestCase;

final class AssertionParityTest extends TestCase
{
    public function testSubstringWithExtraMessageText(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('operation disabled');
        throw new \RuntimeException('operation disabled: policy restriction');
    }

    public function testExactMessageControl(): void
    {
        self::expectException(\RuntimeException::class);
        self::expectExceptionMessage('operation disabled');
        throw new \RuntimeException('operation disabled');
    }

    public function testRegexCharactersAreLiteralAndDynamicValueIsEvaluatedOnce(): void
    {
        $evaluations = 0;
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage($this->expectedMessage($evaluations));
        if ($evaluations !== 1) {
            throw new \LogicException('Expected exactly one evaluation.');
        }
        throw new \RuntimeException('prefix literal ~ [a-z].* suffix');
    }

    public function testLastMessageExpectationWins(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('first');
        $this->expectExceptionMessage('second');
        throw new \RuntimeException('second with extra text');
    }

    public function testEmptyMessageControl(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('');
        throw new \RuntimeException('');
    }

    public function testWeakStringCoercion(): void
    {
        $this->assertStringContainsString('123', new \Illuminate\Support\HtmlString('value 123'));
        $this->assertStringContainsString('2', 123);
        $this->assertStringNotContainsString('9', 123);
        $this->assertDoesNotMatchRegularExpression('/9/', '123');
    }

    public function testEmptyAndStrictMembership(): void
    {
        $this->assertEmpty(new \ArrayObject());
        $this->assertEmpty(new \EmptyIterator());
        $this->assertEmpty('0');
        $this->assertNotEmpty(new \ArrayObject([1]));
        $this->assertIsArray([]);
        $this->assertNotContains('1', [1]);
        $this->addToAssertionCount(2);
    }

    public function testNegativeWrongType(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('operation disabled');
        throw new \LogicException('operation disabled: policy restriction');
    }

    public function testNegativeWrongMessage(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('operation disabled');
        throw new \RuntimeException('different message');
    }

    public function testNegativeEmptyMessage(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('');
        throw new \RuntimeException('nonempty');
    }

    public function testNegativeNoException(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('operation disabled');
    }

    private function expectedMessage(int &$evaluations): string
    {
        ++$evaluations;
        return 'literal ~ [a-z].*';
    }
}
