<?php

declare(strict_types=1);

use App\Domain\Model\Page;
use App\Domain\ValueObject\Locale;
use App\Domain\ValueObject\PageStatus;
use App\Domain\ValueObject\PostId;
use App\Domain\ValueObject\SeoData;

it('builds SEO data using excerpt when available', function (): void {
    $now = new DateTimeImmutable();
    $page = new Page(
        id: new PostId(42),
        title: 'Sovereign Engine Architecture',
        content: '<p>Long content follows...</p>',
        status: PageStatus::Published,
        locale: Locale::English,
        createdAt: $now,
        slug: 'sovereign-architecture',
        excerpt: 'A high-performance WordPress headless runtime.',
        modifiedAt: $now,
    );

    $seo = $page->buildSeoData('https://example.com');

    expect($seo->title)->toBe('Sovereign Engine Architecture')
        ->and($seo->description)->toBe('A high-performance WordPress headless runtime.')
        ->and($seo->canonicalUrl)->toBe('https://example.com/sovereign-architecture')
        ->and($seo->noIndex)->toBeFalse();
});

it('extracts plain text description from content when excerpt is empty', function (): void {
    $now = new DateTimeImmutable();
    $page = new Page(
        id: new PostId(10),
        title: 'Thai Post Title บทความภาษาไทย',
        content: '<!-- wp:paragraph --><p>นี่คือเนื้อหาภาษาไทยสำหรับทดสอบการทำงานของระบบ SEO บน Sovereign Engine อย่างแท้จริง โดยระบบจะทำการตัดแท็ก HTML ออกและดึงเฉพาะข้อความบริสุทธิ์เพื่อนำไปสร้าง Meta Description ที่ถูกต้อง</p><!-- /wp:paragraph -->',
        status: PageStatus::Published,
        locale: Locale::Thai,
        createdAt: $now,
        slug: 'บทความภาษาไทย',
    );

    $seo = $page->buildSeoData('https://example.com');

    expect($seo->title)->toBe('Thai Post Title บทความภาษาไทย')
        ->and($seo->description)->not->toContain('<p>')
        ->and($seo->description)->not->toContain('<!--')
        ->and($seo->description)->toContain('นี่คือเนื้อหาภาษาไทยสำหรับทดสอบการทำงาน')
        ->and($seo->canonicalUrl)->toBe('https://example.com/' . rawurlencode('บทความภาษาไทย'))
        ->and($seo->noIndex)->toBeFalse();
});

it('marks unpublished pages as noIndex in SeoData', function (): void {
    $now = new DateTimeImmutable();
    $page = new Page(
        id: new PostId(99),
        title: 'Draft Post',
        content: '<p>Secret draft</p>',
        status: PageStatus::Draft,
        locale: Locale::English,
        createdAt: $now,
    );

    $seo = $page->buildSeoData('https://example.com');

    expect($seo->noIndex)->toBeTrue();
});

it('preserves explicitly injected SeoData', function (): void {
    $now = new DateTimeImmutable();
    $customSeo = new SeoData(
        title: 'Custom Title',
        description: 'Custom Description',
        canonicalUrl: 'https://custom.org/target',
        noIndex: true,
    );

    $page = new Page(
        id: new PostId(1),
        title: 'Original Title',
        content: 'Original Content',
        status: PageStatus::Published,
        locale: Locale::English,
        createdAt: $now,
        seo: $customSeo,
    );

    expect($page->buildSeoData('https://example.com'))->toBe($customSeo);
});
