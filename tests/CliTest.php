<?php

declare(strict_types=1);

namespace FancyCms\Tests;

use PHPUnit\Framework\TestCase;

final class CliTest extends TestCase
{
    public function test_css_cli_refuses_invalid_json_without_a_fatal_error(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'fancycms');
        try {
            file_put_contents($path, '{broken json');
            $process = proc_open([PHP_BINARY, dirname(__DIR__).'/bin/emit-css.php', $path], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
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
