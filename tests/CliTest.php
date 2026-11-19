<?php

declare(strict_types=1);

namespace FancyCms\Tests\CliTest;


function renderClis(): array {
    return [['emit-css.php'], ['render-html.php']];
}

/**
 * The CLIs run without Composer's autoloader (they `require` each class), so a
 * new class used by the renderer must be required there too. render-html.php
 * missed FontAssets and fatally failed on EVERY document, which broke the HTML
 * parity gate. The invalid-JSON test above never reaches the renderer, so it
 * could not see it.
 */
test('cli renders a valid document standalone', function (string $cli) {
    $path = tempnam(sys_get_temp_dir(), 'fancycms');
    try {
        file_put_contents($path, '{"version":1,"root":"r","nodes":{"r":{"id":"r","type":"section","children":[]}}}');
        $process = proc_open([PHP_BINARY, dirname(__DIR__).'/bin/'.$cli, $path], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $this->assertIsResource($process);
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $this->assertSame(0, proc_close($process), $err);
        $this->assertSame('', $err);
        $this->assertNotSame('', $out);
    } finally {
        unlink($path);
    }
})->with(fn (): iterable => renderClis());

/**
 * A structured font needs a host resolver, which these CLIs never have, so the
 * document is refused. It must be refused like every other bad input: a
 * constant reason on stderr and a non-zero code, not an uncaught exception
 * (PHP fatal, exit 255, stack trace on stdout).
 */
test('cli refuses a structured font without a fatal error', function (string $cli) {
    $path = tempnam(sys_get_temp_dir(), 'fancycms');
    try {
        file_put_contents($path, '{"version":1,"theme":{"font":{"body":{"family":"Inter","source":"bundled","weights":[400]}}},"root":"r","nodes":{"r":{"id":"r","type":"section","children":[]}}}');
        $process = proc_open([PHP_BINARY, dirname(__DIR__).'/bin/'.$cli, $path], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $this->assertIsResource($process);
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $this->assertSame(3, proc_close($process), $out.$err);
        $this->assertSame('', $out);
        $this->assertSame("missing-resolver\n", $err);
    } finally {
        unlink($path);
    }
})->with(fn (): iterable => renderClis());

test('cli refuses invalid json without a fatal error', function (string $cli) {
    $path = tempnam(sys_get_temp_dir(), 'fancycms');
    try {
        file_put_contents($path, '{broken json');
        $process = proc_open([PHP_BINARY, dirname(__DIR__).'/bin/'.$cli, $path], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $this->assertIsResource($process);
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $this->assertSame(2, proc_close($process));
        $this->assertSame('', $out);
        $this->assertSame("invalid JSON\n", $err);
    } finally {
        unlink($path);
    }
})->with(fn (): iterable => renderClis());
