<?php

namespace Tests;

use Illuminate\Mail\Mailable;
use Illuminate\Foundation\Testing\TestCase as FrameworkTestCase;

final class ExampleMail extends Mailable
{
    protected function renderForAssertions()
    {
        return ['Hello world', 'Hello world'];
    }
}

final class MailableAssertionsTest extends FrameworkTestCase
{
    public function createApplication()
    {
        $app = require getenv('LARATESTO_ISSUE10_APP').'/bootstrap/app.php';
        $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        return $app;
    }

    public function testPositiveMailAssertions(): void
    {
        $mail = new ExampleMail();
        $mail->assertSeeInHtml('Hello');
        $mail->assertDontSeeInHtml('Missing');
    }

    public function testPositiveChainWithNamedArgument(): void
    {
        $mail = new ExampleMail();
        $mail->assertSeeInHtml(string: 'Hello')->assertSeeInText('world');
    }

    public function testNegativeMailAssertion(): void
    {
        $mail = new ExampleMail();
        $mail->assertDontSeeInHtml('Hello');
    }

    public function testNegativeChainedAssertion(): void
    {
        $mail = new ExampleMail();
        $mail->assertSeeInHtml('Hello')->assertDontSeeInHtml('world');
    }
}
