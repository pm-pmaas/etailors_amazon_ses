<?php

declare(strict_types=1);

namespace MauticPlugin\AmazonSesBundle\Tests\Unit\Mailer\Bulk;

use Mautic\EmailBundle\Helper\MailHelper;
use Mautic\EmailBundle\Mailer\Message\MauticMessage;
use MauticPlugin\AmazonSesBundle\Mailer\Bulk\IneligibleMessage;
use MauticPlugin\AmazonSesBundle\Mailer\Bulk\SharedTemplateCompiler;
use PHPUnit\Framework\TestCase;

class SharedTemplateCompilerTest extends TestCase
{
    /** @dataProvider cases */
    public function testMatchesMauticRendering(string $html, string $text, array $tokens): void
    {
        $message = (new MauticMessage())->subject('News {contactfield=email}')->html($html)->text($text);
        $compiled = (new SharedTemplateCompiler())->compile($message, $tokens);
        $raw = clone $message;
        ksort($tokens);
        MailHelper::searchReplaceTokens(array_keys($tokens), $tokens, $raw);
        $data = json_decode($compiled['data'], true, 512, JSON_THROW_ON_ERROR);
        $render = static fn (string $value): string => preg_replace_callback('/\{\{([a-zA-Z0-9_]+)\}\}/', static fn (array $match): string => $data[$match[1]], $value);
        self::assertSame($raw->getHtmlBody(), $render($compiled['template']['Html']));
        self::assertSame($raw->getTextBody(), $render($compiled['template']['Text']));
        self::assertSame($raw->getSubject(), $render($compiled['template']['Subject']));
        self::assertSame($html, $message->getHtmlBody());
    }

    public function cases(): array
    {
        return [
            'newsletter footer' => ['<p>News</p><p>{CONTACTFIELD=email}</p>{webview_text}{unsubscribe_text}<img src="{tracking_pixel}">', 'News {contactfield=email} {unsubscribe_text}', ['{contactfield=email}' => 'person@example.com', '{webview_text}' => '<a href="https://example.test/view/a">View</a>', '{unsubscribe_text}' => '<a href="https://example.test/u/a">Unsubscribe</a>', '{tracking_pixel}' => 'https://example.test/p/a']],
            'sorted nested replacement' => ['{a} {b}', '{a} {b}', ['{b}' => '✓ & "quoted"', '{a}' => '{b}']],
            'earlier token remains literal' => ['{b}', '{b}', ['{a}' => 'value', '{b}' => '{a}']],
            'strip entire text' => ['{a}', '<b>Fixed</b> {a}', ['{a}' => '<a href="https://example.test">Link</a>']],
            'tags across boundaries' => ['{a}', '<{a}>Word</b>', ['{a}' => 'b']],
            'unchanged text keeps tags' => ['{a}', '<b>{a}</b>', ['{a}' => '{a}']],
            'plain shared text' => ['{a}', 'Hello {a}', ['{a}' => 'World']],
            'static newsletter' => ['<h1>News</h1>', 'News', []],
            'shared text with unsubscribe link' => ['<p>News</p>{unsubscribe_text}', 'Thanks for reading our newsletter, {contactfield=email}. {unsubscribe_text}', ['{contactfield=email}' => 'person@example.com', '{unsubscribe_text}' => '<a href="https://example.test/u/a">Unsubscribe</a> to no longer receive emails from us.']],
            'unclosed tag before text' => ['{a}', '{a} Shared text follows.', ['{a}' => '<b']],
        ];
    }

    public function testTextPartIsSharedWhenStrippingIsDistributive(): void
    {
        [$html, $text, $tokens] = $this->cases()['shared text with unsubscribe link'];
        $compiled = (new SharedTemplateCompiler())->compile((new MauticMessage())->subject('News')->html($html)->text($text), $tokens);
        self::assertStringContainsString('Thanks for reading our newsletter, ', $compiled['template']['Text']);
        self::assertStringContainsString('{{t_', $compiled['template']['Text']);
        self::assertArrayNotHasKey('ses_plain_text', json_decode($compiled['data'], true, 512, JSON_THROW_ON_ERROR));
    }

    public function testTextPartFallsBackWhenStrippingIsNotDistributive(): void
    {
        [$html, $text, $tokens] = $this->cases()['unclosed tag before text'];
        $compiled = (new SharedTemplateCompiler())->compile((new MauticMessage())->subject('News')->html($html)->text($text), $tokens);
        self::assertSame('{{ses_plain_text}}', $compiled['template']['Text']);
    }

    public function testTemplateIsSharedAndDataDoesNotRepeatTheNewsletter(): void
    {
        $html = str_repeat('<p>Same newsletter article for everyone.</p>', 3000).'{contactfield=email}';
        $message = (new MauticMessage())->subject('News')->html($html);
        $compiler = new SharedTemplateCompiler();
        $a = $compiler->compile($message, ['{contactfield=email}' => 'a@example.com']);
        $b = $compiler->compile($message, ['{contactfield=email}' => 'b@example.com']);
        self::assertSame($a['template'], $b['template']);
        self::assertLessThan(100, strlen($a['data']));
        self::assertNotSame($a['data'], $b['data']);
    }

    public function testTextTemplateIsSharedAndDataDoesNotRepeatThePlainText(): void
    {
        $text = str_repeat('Same newsletter sentence for everyone. ', 3000).'{unsubscribe_text}';
        $message = (new MauticMessage())->subject('News')->html('<p>News</p>{unsubscribe_text}')->text($text);
        $compiler = new SharedTemplateCompiler();
        $a = $compiler->compile($message, ['{unsubscribe_text}' => '<a href="https://example.test/u/a">Unsubscribe</a>']);
        $b = $compiler->compile($message, ['{unsubscribe_text}' => '<a href="https://example.test/u/b">Unsubscribe</a>']);
        self::assertSame($a['template']['Text'], $b['template']['Text']);
        self::assertLessThan(300, strlen($a['data']));
        self::assertLessThan(300, strlen($b['data']));
        self::assertNotSame($a['data'], $b['data']);
    }

    /** @dataProvider unsupported */
    public function testRejectsUnsupportedInputs(string $html, array $tokens, string $reason): void
    {
        $this->expectException(IneligibleMessage::class);
        $this->expectExceptionMessage($reason);
        (new SharedTemplateCompiler())->compile((new MauticMessage())->subject('News')->html($html), $tokens);
    }

    public function unsupported(): array
    {
        return [
            ['{{literal}}', [], 'literal_template_delimiters'],
            ['{a}', ['{a}' => '{{value}}'], 'literal_template_delimiters'],
            ['{a}', ['{a}' => 'x', '{A}' => 'y'], 'case_colliding_tokens'],
            ['abc', ['ab' => 'x'], 'unsupported_token'],
            ['{a}', ['{a}' => str_repeat('x', 262144)], 'replacement_data_size'],
            ["\xff", [], 'invalid_utf8'],
        ];
    }

    public function testAttachmentsAreIneligible(): void
    {
        $this->expectException(IneligibleMessage::class);
        (new SharedTemplateCompiler())->compile((new MauticMessage())->html('News')->attach('data', 'test.txt'), []);
    }

    public function testExplicitMimeIsIneligible(): void
    {
        $this->expectException(IneligibleMessage::class);
        (new SharedTemplateCompiler())->compile((new MauticMessage())->html('News')->setBody(new \Symfony\Component\Mime\Part\TextPart('custom')), []);
    }
}
