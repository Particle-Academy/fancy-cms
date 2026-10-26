<?php

declare(strict_types=1);

namespace FancyCms\Tests;

use FancyCms\Render\PageRenderer;
use FancyCms\Tests\Support\PageFixtures as F;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ImageAssetsTest extends TestCase
{
    private static function asset(): array
    {
        return ['src' => 'https://cdn.example.test/hero.jpg', 'width' => 1536, 'height' => 1024,
            'variants' => [['src' => '/hero-960.jpg', 'width' => 960, 'height' => 640], ['src' => '/hero-480.jpg', 'width' => 480, 'height' => 320]],
            'sizes' => '(max-width: 640px) 100vw, 640px'];
    }

    private function render(array $props, ?callable $resolver = null): string
    {
        $doc = F::doc();
        $node = F::node('image');
        $node['type'] = 'image';
        $node['props'] = $props;
        $doc['nodes'] = ['image' => $node];

        return PageRenderer::render($doc, [], $resolver)['html'];
    }

    public function test_only_host_metadata_produces_intrinsic_and_responsive_attributes(): void
    {
        $html = $this->render(['asset' => 'brief/hero', 'src' => 'https://untrusted.invalid/fallback.jpg', 'width' => 1, 'srcSet' => 'https://untrusted.invalid/override.jpg 1w', 'alt' => 'Scene <one> & two', 'loading' => 'eager', 'fit' => 'contain'], static fn (string $handle) => $handle === 'brief/hero' ? self::asset() : null);
        self::assertSame('<link rel="preload" as="image" imageSrcSet="/hero-480.jpg 480w, /hero-960.jpg 960w" imageSizes="(max-width: 640px) 100vw, 640px"/><div data-cms="image"><img src="https://cdn.example.test/hero.jpg" alt="Scene &lt;one&gt; &amp; two" width="1536" height="1024" srcSet="/hero-480.jpg 480w, /hero-960.jpg 960w" sizes="(max-width: 640px) 100vw, 640px" loading="eager" data-cms-fit="contain"/></div>', $html);
    }

    public static function unavailable(): iterable
    {
        foreach ([null, 'missing', '', 42, ['$bind' => 'asset']] as $handle) {
            yield [$handle];
        }
    }

    #[DataProvider('unavailable')]
    public function test_unavailable_asset_never_falls_back_to_authored_urls(mixed $handle): void
    {
        self::assertSame('<div data-cms="image"><img alt="Retain alt" loading="lazy"/></div>', $this->render(['asset' => $handle, 'src' => 'https://untrusted.invalid/fallback.jpg', 'alt' => 'Retain alt'], static fn (string $id) => null));
    }

    public function test_asset_without_a_resolver_is_inert_and_legacy_images_remain_compatible(): void
    {
        self::assertSame('<div data-cms="image"><img alt="" loading="lazy"/></div>', $this->render(['asset' => 'brief/hero', 'src' => '/legacy.jpg']));
        self::assertSame('<div data-cms="image"><img src="/legacy.jpg" alt="" loading="lazy"/></div>', $this->render(['src' => '/legacy.jpg']));
    }

    public static function invalidMetadata(): iterable
    {
        foreach ([['width' => 0], ['height' => '1024'], ['src' => 'javascript:run()'], ['src' => '//outside.invalid/image.jpg'], ['src' => '/bad image.jpg'], ['variants' => [['src' => '/a.jpg', 'width' => 480, 'height' => 320], ['src' => '/b.jpg', 'width' => 480, 'height' => 320]]], ['variants' => [['src' => 'data:image/png;base64,aA==', 'width' => 480, 'height' => 320]]], ['variants' => [['src' => '/a.jpg', 'width' => 480]]], ['sizes' => ''], ['variants' => 'not-a-list']] as $patch) {
            yield [$patch];
        }
    }

    #[DataProvider('invalidMetadata')]
    public function test_invalid_host_metadata_is_refused_without_partial_url_output(array $patch): void
    {
        $asset = array_replace(self::asset(), $patch);
        self::assertSame('<div data-cms="image"><img alt="" loading="lazy"/></div>', $this->render(['asset' => 'brief/hero'], static fn (string $id) => $asset));
    }

    public function test_a_single_host_asset_keeps_dimensions_without_inventing_variants(): void
    {
        self::assertSame('<div data-cms="image"><img src="/single.jpg" alt="" width="20" height="10" loading="lazy"/></div>', $this->render(['asset' => 'one'], static fn (string $id) => ['src' => '/single.jpg', 'width' => 20, 'height' => 10]));
    }

    public function test_resolver_results_do_not_leak_between_renders(): void
    {
        $a = $this->render(['asset' => 'same'], static fn (string $id) => ['src' => '/a.jpg', 'width' => 20, 'height' => 10]);
        $b = $this->render(['asset' => 'same'], static fn (string $id) => ['src' => '/b.jpg', 'width' => 40, 'height' => 20]);
        self::assertStringContainsString('src="/a.jpg"', $a);
        self::assertStringNotContainsString('/a.jpg', $b);
        self::assertStringContainsString('width="40"', $b);
    }
}
