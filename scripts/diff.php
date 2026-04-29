<?php

declare(strict_types=1);

/**
 * CLI wrapper around the Differ.
 *
 * Usage:
 *   php diff.php --head=<path> --base=<path> --output=<path> [filter flags]
 *
 * Filter flags (see Differ for semantics):
 *   --include-internal[=true|false]
 *   --types=class,interface,trait,enum,method,property,constant
 *   --visibility=public,protected[,private]
 *   --show-removed=true|false
 *   --show-modified=true|false
 *   --comment-marker=<string>
 *   --heading=<string>
 */

require __DIR__ . '/../vendor/autoload.php';

use Composer\ApiSurfaceCheck\Differ;

$opts = parseArgs($argv);
$head = loadSnapshot($opts['head']);
$base = loadSnapshot($opts['base']);

$differ = new Differ([
    'include-internal' => $opts['include-internal'],
    'types' => $opts['types'],
    'visibility' => $opts['visibility'],
    'show-removed' => $opts['show-removed'],
    'show-modified' => $opts['show-modified'],
    'comment-marker' => $opts['comment-marker'],
    'heading' => $opts['heading'],
    'repo' => $opts['repo'],
    'pr-number' => $opts['pr-number'],
]);

file_put_contents($opts['output'], $differ->diff($head, $base));

function parseArgs(array $argv): array
{
    $opts = [
        'head' => null,
        'base' => null,
        'output' => null,
        'include-internal' => false,
        'types' => array_keys(Differ::KIND_LABELS),
        'visibility' => ['public', 'protected'],
        'show-removed' => true,
        'show-modified' => false,
        'comment-marker' => '<!-- api-surface-bot -->',
        'heading' => '## API Surface Changes',
        'repo' => null,
        'pr-number' => null,
    ];
    foreach (array_slice($argv, 1) as $arg) {
        if (str_starts_with($arg, '--head=')) {
            $opts['head'] = substr($arg, 7);
        } elseif (str_starts_with($arg, '--base=')) {
            $opts['base'] = substr($arg, 7);
        } elseif (str_starts_with($arg, '--output=')) {
            $opts['output'] = substr($arg, 9);
        } elseif ($arg === '--include-internal' || $arg === '--include-internal=true') {
            $opts['include-internal'] = true;
        } elseif ($arg === '--include-internal=false') {
            $opts['include-internal'] = false;
        } elseif (str_starts_with($arg, '--types=')) {
            $opts['types'] = parseList(substr($arg, 8));
        } elseif (str_starts_with($arg, '--visibility=')) {
            $opts['visibility'] = parseList(substr($arg, 13));
        } elseif (str_starts_with($arg, '--show-removed=')) {
            $opts['show-removed'] = parseBool(substr($arg, 15));
        } elseif (str_starts_with($arg, '--show-modified=')) {
            $opts['show-modified'] = parseBool(substr($arg, 16));
        } elseif (str_starts_with($arg, '--comment-marker=')) {
            $opts['comment-marker'] = substr($arg, 17);
        } elseif (str_starts_with($arg, '--heading=')) {
            $opts['heading'] = substr($arg, 10);
        } elseif (str_starts_with($arg, '--repo=')) {
            $value = substr($arg, 7);
            $opts['repo'] = $value !== '' ? $value : null;
        } elseif (str_starts_with($arg, '--pr-number=')) {
            $value = substr($arg, 12);
            $opts['pr-number'] = $value !== '' ? $value : null;
        }
    }
    foreach (['head', 'base', 'output'] as $required) {
        if ($opts[$required] === null) {
            fwrite(STDERR, "Missing --{$required}\n");
            exit(2);
        }
    }
    return $opts;
}

function parseBool(string $value): bool
{
    return in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
}

function parseList(string $value): array
{
    return array_values(array_filter(array_map('trim', explode(',', $value))));
}

function loadSnapshot(string $path): array
{
    if (!is_file($path)) {
        return [];
    }
    $data = json_decode(file_get_contents($path), true);
    return is_array($data) ? $data : [];
}
