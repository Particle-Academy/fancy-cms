<?php

declare(strict_types=1);

namespace FancyCms\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CliTest extends TestCase
{
    public static function renderClis(): array
    {
        return [['emit-css.php'], ['render-html.php']];
    }

    /**
     * The CLIs run without Composer's autoloader (they `require` each class), so a
     * new class used by the renderer must be required there too. render-html.php
     * missed FontAssets and fatally failed on EVERY document, which broke the HTML
     * parity gate. The invalid-JSON test above never reaches the renderer, so it
     * could not see it.
     */
    #[DataProvider('renderClis')]
    public function test_cli_renders_a_valid_document_standalone(string $cli): void
    {
        $path = tempnam(sys_get_temp_dir(), 'fancycms');
        try {
            file_put_contents($path, '{"version":1,"root":"r","nodes":{"r":{"id":"r","type":"section","children":[]}}}');
            $process = proc_open([PHP_BINARY, dirname(__DIR__).'/bin/'.$cli, $path], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            self::assertIsResource($process);
            $out = stream_get_contents($pipes[1]);
            $err = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            self::assertSame(0, proc_close($process), $err);
            self::assertSame('', $err);
            self::assertNotSame('', $out);
        } finally {
            unlink($path);
        }
    }

    #[DataProvider('renderClis')]
    public function test_cli_refuses_invalid_json_without_a_fatal_error(string $cli): void
    {
        $path = tempnam(sys_get_temp_dir(), 'fancycms');
        try {
            file_put_contents($path, '{broken json');
            $process = proc_open([PHP_BINARY, dirname(__DIR__).'/bin/'.$cli, $path], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            self::assertIsResource($process);
            $out = stream_get_contents($pipes[1]);
            $err = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            self::assertSame(2, proc_close($process));
            self::assertSame('', $out);
            self::assertSame("invalid JSON\n", $err);
        } finally {
            unlink($path);
        }
    }
}
