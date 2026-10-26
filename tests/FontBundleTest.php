<?php

declare(strict_types=1);

namespace FancyCms\Tests;

use FancyCms\Render\BundledFonts;
use FancyCms\Render\CssEmitter;
use PHPUnit\Framework\TestCase;

final class FontBundleTest extends TestCase
{
    public function test_unmodified_inter_has_complete_provenance_and_licence(): void
    {
        $root = __DIR__.'/../fonts/inter-4.1/';
        self::assertFileExists($root.'manifest.json');
        $manifest = json_decode(file_get_contents($root.'manifest.json'), true, 128, JSON_THROW_ON_ERROR);
        self::assertSame('Inter', $manifest['family']);
        self::assertSame('bundled', $manifest['source']);
        self::assertSame('4.1', $manifest['version']);
        self::assertSame('https://github.com/rsms/inter/releases/download/v4.1/Inter-4.1.zip', $manifest['archive']['url']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $manifest['archive']['sha256']);
        self::assertSame('OFL-1.1', $manifest['licence']['spdx']);
        self::assertSame('OFL.txt', $manifest['licence']['file']);
        self::assertSame($manifest['licence']['sha256'], hash_file('sha256', $root.'OFL.txt'));
        self::assertStringContainsString('SIL OPEN FONT LICENSE Version 1.1', file_get_contents($root.'OFL.txt'));
        self::assertStringContainsString('Unmodified', file_get_contents($root.'NOTICE'));
        self::assertCount(2, $manifest['faces']);
        $styles = [];
        foreach ($manifest['faces'] as $face) {
            self::assertMatchesRegularExpression('/^[A-Za-z0-9.-]+\.woff2$/D', $face['file']);
            $bytes = file_get_contents($root.$face['file']);
            self::assertSame('wOF2', substr($bytes, 0, 4));
            self::assertSame(strlen($bytes), unpack('Nlength', substr($bytes, 8, 4))['length']);
            self::assertSame($face['bytes'], strlen($bytes));
            self::assertSame($face['sha256'], hash('sha256', $bytes));
            self::assertSame([100, 900], $face['weight']);
            $styles[] = $face['style'];
        }
        sort($styles);
        self::assertSame(['italic', 'normal'], $styles);
    }

    public function test_host_explicitly_publishes_the_bundled_faces(): void
    {
        self::assertTrue(class_exists(BundledFonts::class));
        $files = BundledFonts::interFiles();
        foreach ($files as $file) {
            self::assertFileExists($file['path']);
            self::assertSame($file['sha256'], hash_file('sha256', $file['path']));
        }
        $asset = BundledFonts::interAsset(static fn (array $file): string => '/fonts/'.$file['sha256'].'.woff2');
        self::assertCount(2, $asset['faces']);
        $doc = ['theme' => ['font' => ['body' => ['family' => 'Inter', 'source' => 'bundled', 'weights' => [400, 600, 700], 'styles' => ['normal', 'italic']]]], 'nodes' => []];
        self::assertStringContainsString('font-style: italic;', CssEmitter::emit($doc, static fn () => $asset));
        self::assertSame(hash_file('sha256', __DIR__.'/../fonts/inter-4.1/manifest.json'), $asset['manifestSha256']);
    }
}
