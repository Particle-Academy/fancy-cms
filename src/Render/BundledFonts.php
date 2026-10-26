<?php

declare(strict_types=1);

namespace FancyCms\Render;

use RuntimeException;

/** Opt-in publication metadata. Installing the package never publishes a route or stylesheet. */
final class BundledFonts
{
    private const ROOT = __DIR__.'/../../fonts/inter-4.1/';

    /** @return list<array{file:string,path:string,sha256:string,bytes:int,weight:array{int,int},style:string}> */
    public static function interFiles(): array
    {
        $manifest = json_decode((string) file_get_contents(self::ROOT.'manifest.json'), true, 32, JSON_THROW_ON_ERROR);
        $files = [];
        foreach ($manifest['faces'] as $face) {
            if (! in_array($face['file'], ['InterVariable.woff2', 'InterVariable-Italic.woff2'], true)) {
                throw new RuntimeException('Invalid bundled Inter file');
            }
            $path = self::ROOT.$face['file'];
            if (! is_file($path) || filesize($path) !== $face['bytes'] || hash_file('sha256', $path) !== $face['sha256']) {
                throw new RuntimeException('Bundled Inter file identity mismatch');
            }
            $files[] = ['file' => $face['file'], 'path' => $path, 'sha256' => $face['sha256'], 'bytes' => $face['bytes'], 'weight' => $face['weight'], 'style' => $face['style']];
        }

        return $files;
    }

    /** The host supplies each canonical root-relative URL after explicitly publishing interFiles(). */
    public static function interAsset(callable $sourceForFile): array
    {
        $faces = [];
        foreach (self::interFiles() as $file) {
            $faces[] = ['src' => $sourceForFile($file), 'sha256' => $file['sha256'], 'weight' => $file['weight'], 'style' => $file['style']];
        }

        return ['family' => 'Inter', 'source' => 'bundled', 'manifestSha256' => hash_file('sha256', self::ROOT.'manifest.json'), 'faces' => $faces];
    }
}
