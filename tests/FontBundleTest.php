<?php

declare(strict_types=1);

namespace FancyCms\Tests\FontBundleTest;

use FancyCms\Render\BundledFonts;
use FancyCms\Render\CssEmitter;

test('unmodified inter has complete provenance and licence', function () {
    $root = __DIR__.'/../fonts/inter-4.1/';
    $this->assertFileExists($root.'manifest.json');
    $manifest = json_decode(file_get_contents($root.'manifest.json'), true, 128, JSON_THROW_ON_ERROR);
    $this->assertSame('Inter', $manifest['family']);
    $this->assertSame('bundled', $manifest['source']);
    $this->assertSame('4.1', $manifest['version']);
    $this->assertSame('https://github.com/rsms/inter/releases/download/v4.1/Inter-4.1.zip', $manifest['archive']['url']);
    $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $manifest['archive']['sha256']);
    $this->assertSame('OFL-1.1', $manifest['licence']['spdx']);
    $this->assertSame('OFL.txt', $manifest['licence']['file']);
    $this->assertSame($manifest['licence']['sha256'], hash_file('sha256', $root.'OFL.txt'));
    $this->assertStringContainsString('SIL OPEN FONT LICENSE Version 1.1', file_get_contents($root.'OFL.txt'));
    $this->assertStringContainsString('Unmodified', file_get_contents($root.'NOTICE'));
    $this->assertCount(2, $manifest['faces']);
    $styles = [];
    foreach ($manifest['faces'] as $face) {
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9.-]+\.woff2$/D', $face['file']);
        $bytes = file_get_contents($root.$face['file']);
        $this->assertSame('wOF2', substr($bytes, 0, 4));
        $this->assertSame(strlen($bytes), unpack('Nlength', substr($bytes, 8, 4))['length']);
        $this->assertSame($face['bytes'], strlen($bytes));
        $this->assertSame($face['sha256'], hash('sha256', $bytes));
        $this->assertSame([100, 900], $face['weight']);
        $styles[] = $face['style'];
    }
    sort($styles);
    $this->assertSame(['italic', 'normal'], $styles);
});

test('host explicitly publishes the bundled faces', function () {
    $this->assertTrue(class_exists(BundledFonts::class));
    $files = BundledFonts::interFiles();
    foreach ($files as $file) {
        $this->assertFileExists($file['path']);
        $this->assertSame($file['sha256'], hash_file('sha256', $file['path']));
    }
    $asset = BundledFonts::interAsset(static fn (array $file): string => '/fonts/'.$file['sha256'].'.woff2');
    $this->assertCount(2, $asset['faces']);
    $doc = ['theme' => ['font' => ['body' => ['family' => 'Inter', 'source' => 'bundled', 'weights' => [400, 600, 700], 'styles' => ['normal', 'italic']]]], 'nodes' => []];
    $this->assertStringContainsString('font-style: italic;', CssEmitter::emit($doc, static fn () => $asset));
    $this->assertSame(hash_file('sha256', __DIR__.'/../fonts/inter-4.1/manifest.json'), $asset['manifestSha256']);
});
