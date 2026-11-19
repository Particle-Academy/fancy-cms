<?php

declare(strict_types=1);

namespace FancyCms\Tests\ImageAssetsTest;

use FancyCms\Render\PageRenderer;
use FancyCms\Tests\Support\PageFixtures as F;

function asset(): array {
    return ['src' => 'https://cdn.example.test/hero.jpg', 'width' => 1536, 'height' => 1024,
        'variants' => [['src' => '/hero-960.jpg', 'width' => 960, 'height' => 640], ['src' => '/hero-480.jpg', 'width' => 480, 'height' => 320]],
        'sizes' => '(max-width: 640px) 100vw, 640px'];
}

function render(array $props, ?callable $resolver = null): string {
    $doc = F::doc();
    $node = F::node('image');
    $node['type'] = 'image';
    $node['props'] = $props;
    $doc['nodes'] = ['image' => $node];

    return PageRenderer::render($doc, [], $resolver)['html'];
}

test('only host metadata produces intrinsic and responsive attributes', function () {
    $html = render(['asset' => 'brief/hero', 'src' => 'https://untrusted.invalid/fallback.jpg', 'width' => 1, 'srcSet' => 'https://untrusted.invalid/override.jpg 1w', 'alt' => 'Scene <one> & two', 'loading' => 'eager', 'fit' => 'contain'], static fn (string $handle) => $handle === 'brief/hero' ? asset() : null);
    $this->assertSame('<link rel="preload" as="image" imageSrcSet="/hero-480.jpg 480w, /hero-960.jpg 960w" imageSizes="(max-width: 640px) 100vw, 640px"/><div data-cms="image"><img src="https://cdn.example.test/hero.jpg" alt="Scene &lt;one&gt; &amp; two" width="1536" height="1024" srcSet="/hero-480.jpg 480w, /hero-960.jpg 960w" sizes="(max-width: 640px) 100vw, 640px" loading="eager" data-cms-fit="contain"/></div>', $html);
});

function unavailable(): iterable {
    foreach ([null, 'missing', '', 42, ['$bind' => 'asset']] as $handle) {
        yield [$handle];
    }
}

test('unavailable asset never falls back to authored urls', function (mixed $handle) {
    $this->assertSame('<div data-cms="image"><img alt="Retain alt" loading="lazy"/></div>', render(['asset' => $handle, 'src' => 'https://untrusted.invalid/fallback.jpg', 'alt' => 'Retain alt'], static fn (string $id) => null));
})->with(fn (): iterable => unavailable());

test('asset without a resolver is inert and legacy images remain compatible', function () {
    $this->assertSame('<div data-cms="image"><img alt="" loading="lazy"/></div>', render(['asset' => 'brief/hero', 'src' => '/legacy.jpg']));
    $this->assertSame('<div data-cms="image"><img src="/legacy.jpg" alt="" loading="lazy"/></div>', render(['src' => '/legacy.jpg']));
});

function invalidMetadata(): iterable {
    foreach ([['width' => 0], ['height' => '1024'], ['src' => 'javascript:run()'], ['src' => '//outside.invalid/image.jpg'], ['src' => '/bad image.jpg'], ['variants' => [['src' => '/a.jpg', 'width' => 480, 'height' => 320], ['src' => '/b.jpg', 'width' => 480, 'height' => 320]]], ['variants' => [['src' => 'data:image/png;base64,aA==', 'width' => 480, 'height' => 320]]], ['variants' => [['src' => '/a.jpg', 'width' => 480]]], ['sizes' => ''], ['variants' => 'not-a-list']] as $patch) {
        yield [$patch];
    }
}

test('invalid host metadata is refused without partial url output', function (array $patch) {
    $asset = array_replace(asset(), $patch);
    $this->assertSame('<div data-cms="image"><img alt="" loading="lazy"/></div>', render(['asset' => 'brief/hero'], static fn (string $id) => $asset));
})->with(fn (): iterable => invalidMetadata());

test('a single host asset keeps dimensions without inventing variants', function () {
    $this->assertSame('<div data-cms="image"><img src="/single.jpg" alt="" width="20" height="10" loading="lazy"/></div>', render(['asset' => 'one'], static fn (string $id) => ['src' => '/single.jpg', 'width' => 20, 'height' => 10]));
});

test('resolver results do not leak between renders', function () {
    $a = render(['asset' => 'same'], static fn (string $id) => ['src' => '/a.jpg', 'width' => 20, 'height' => 10]);
    $b = render(['asset' => 'same'], static fn (string $id) => ['src' => '/b.jpg', 'width' => 40, 'height' => 20]);
    $this->assertStringContainsString('src="/a.jpg"', $a);
    $this->assertStringNotContainsString('/a.jpg', $b);
    $this->assertStringContainsString('width="40"', $b);
});
