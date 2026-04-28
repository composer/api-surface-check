<?php

declare(strict_types=1);

/**
 * CLI wrapper around the Snapshotter.
 *
 * Usage:
 *   php snapshot.php --files=<comma-or-newline-list> --roots=<comma-or-newline-list> --output=<path>
 */

require __DIR__ . '/../vendor/autoload.php';

use Composer\ApiSurfaceCheck\Snapshotter;

$opts = parseArgs($argv);

$snapshotter = new Snapshotter($opts['roots']);
$records = $snapshotter->snapshot($opts['files']);

file_put_contents($opts['output'], json_encode($records, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

function parseArgs(array $argv): array
{
    $opts = ['files' => [], 'roots' => [], 'output' => null];
    foreach (array_slice($argv, 1) as $arg) {
        if (str_starts_with($arg, '--files=')) {
            $opts['files'] = splitList(substr($arg, 8));
        } elseif (str_starts_with($arg, '--roots=')) {
            $opts['roots'] = splitList(substr($arg, 8));
        } elseif (str_starts_with($arg, '--output=')) {
            $opts['output'] = substr($arg, 9);
        }
    }
    if ($opts['output'] === null) {
        fwrite(STDERR, "Missing --output\n");
        exit(2);
    }
    return $opts;
}

function splitList(string $value): array
{
    $parts = preg_split('/[,\n]+/', $value, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    return array_values(array_map('trim', $parts));
}
