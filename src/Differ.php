<?php

declare(strict_types=1);

namespace Composer\ApiSurfaceCheck;

/**
 * Diffs two snapshots and renders a markdown body suitable for a PR comment.
 */
final class Differ
{
    public const KIND_LABELS = [
        'class' => 'Classes',
        'interface' => 'Interfaces',
        'trait' => 'Traits',
        'enum' => 'Enums',
        'method' => 'Methods',
        'property' => 'Properties',
        'constant' => 'Constants',
    ];

    public const KIND_ORDER = ['class', 'interface', 'trait', 'enum', 'method', 'property', 'constant'];

    /**
     * @param array{
     *     include-internal?: bool,
     *     types?: list<string>,
     *     visibility?: list<string>,
     *     show-removed?: bool,
     *     show-modified?: bool,
     *     comment-marker?: string,
     *     heading?: string,
     *     repo?: string,
     *     pr-number?: int|string,
     * } $options
     */
    public function __construct(private array $options = [])
    {
    }

    /**
     * @param list<array<string,mixed>> $headRecords
     * @param list<array<string,mixed>> $baseRecords
     */
    public function diff(array $headRecords, array $baseRecords): string
    {
        $head = $this->applyFilters($headRecords);
        $base = $this->applyFilters($baseRecords);

        $headByKey = self::indexByKey($head);
        $baseByKey = self::indexByKey($base);

        $added = [];
        $removed = [];
        $modified = [];

        foreach ($headByKey as $key => $rec) {
            if (!isset($baseByKey[$key])) {
                $added[] = $rec;
            } elseif ($baseByKey[$key]['signature_hash'] !== $rec['signature_hash']) {
                $modified[] = ['head' => $rec, 'base' => $baseByKey[$key]];
            }
        }
        foreach ($baseByKey as $key => $rec) {
            if (!isset($headByKey[$key])) {
                $removed[] = $rec;
            }
        }

        $showRemoved = $this->options['show-removed'] ?? true;
        $showModified = $this->options['show-modified'] ?? false;

        $body = '';
        if (!empty($added)) {
            $body .= $this->renderSection('New API Surface', $added, side: 'R');
        }
        if ($showRemoved && !empty($removed)) {
            $body .= $this->renderSection('Removed API Surface', $removed, side: 'L');
        }
        if ($showModified && !empty($modified)) {
            $body .= $this->renderModifiedSection($modified);
        }

        if ($body === '') {
            return '';
        }

        $marker = $this->options['comment-marker'] ?? '<!-- api-surface-bot -->';
        $heading = $this->options['heading'] ?? '## API Surface Changes';
        $intro = "If any of the additions below are not intended as public API, mark them with `@internal` in the docblock.\n";

        return $marker . "\n" . $heading . "\n\n" . $intro . "\n" . $body;
    }

    /**
     * @param list<array<string,mixed>> $records
     * @return list<array<string,mixed>>
     */
    private function applyFilters(array $records): array
    {
        $kinds = array_flip($this->options['types'] ?? array_keys(self::KIND_LABELS));
        $visibilities = array_flip($this->options['visibility'] ?? ['public', 'protected']);
        $includeInternal = $this->options['include-internal'] ?? false;

        return array_values(array_filter($records, static function (array $r) use ($kinds, $visibilities, $includeInternal): bool {
            if (!isset($kinds[$r['type']])) {
                return false;
            }
            if (!isset($visibilities[$r['visibility']])) {
                return false;
            }
            if (!$includeInternal && !empty($r['internal'])) {
                return false;
            }
            return true;
        }));
    }

    /**
     * @param list<array<string,mixed>> $records
     * @return array<string,array<string,mixed>>
     */
    private static function indexByKey(array $records): array
    {
        $out = [];
        foreach ($records as $r) {
            $out[self::recordKey($r)] = $r;
        }
        return $out;
    }

    public static function recordKey(array $record): string
    {
        return $record['type'] . ':' . $record['fqcn']
            . ($record['member'] !== null ? '::' . $record['member'] : '');
    }

    /**
     * @param list<array<string,mixed>> $records
     */
    private function renderSection(string $title, array $records, string $side): string
    {
        $byKind = [];
        foreach ($records as $r) {
            $byKind[$r['type']][] = $r;
        }
        $out = "### {$title}\n";
        foreach (self::KIND_ORDER as $kind) {
            if (empty($byKind[$kind])) {
                continue;
            }
            $out .= "\n#### " . self::KIND_LABELS[$kind] . "\n";
            foreach ($byKind[$kind] as $r) {
                $out .= $this->renderRecord($r, $side) . "\n";
            }
        }
        return $out . "\n";
    }

    private function renderRecord(array $record, string $side): string
    {
        $label = $record['member'] !== null
            ? $record['fqcn'] . '::' . $record['member']
            : $record['fqcn'];

        $head = $this->formatLabel($label, $record['file'], (int) $record['line'], $side);
        $sig = trim($record['signature']);
        $line = '- ' . $head . ' — `' . $sig . '`';
        if (!$this->hasRepoLink()) {
            $line .= ' in `' . $record['file'] . ':' . $record['line'] . '`';
        }
        return $line;
    }

    /**
     * @param list<array{head: array<string,mixed>, base: array<string,mixed>}> $changes
     */
    private function renderModifiedSection(array $changes): string
    {
        $byKind = [];
        foreach ($changes as $c) {
            $byKind[$c['head']['type']][] = $c;
        }
        $out = "### Modified API Surface\n";
        foreach (self::KIND_ORDER as $kind) {
            if (empty($byKind[$kind])) {
                continue;
            }
            $out .= "\n#### " . self::KIND_LABELS[$kind] . "\n";
            foreach ($byKind[$kind] as $c) {
                $h = $c['head'];
                $b = $c['base'];
                $label = $h['member'] !== null ? ($h['fqcn'] . '::' . $h['member']) : $h['fqcn'];

                $head = $this->formatLabel($label, $h['file'], (int) $h['line'], 'R');
                $line = '- ' . $head;
                if (!$this->hasRepoLink()) {
                    $line .= ' in `' . $h['file'] . ':' . $h['line'] . '`';
                }
                $out .= $line . "\n";
                $out .= self::renderSignatureDiff(
                    self::sourceFor($b),
                    self::sourceFor($h),
                ) . "\n";
            }
        }
        return $out . "\n";
    }

    private function hasRepoLink(): bool
    {
        return !empty($this->options['repo']) && !empty($this->options['pr-number']);
    }

    private function formatLabel(string $label, string $file, int $line, string $side): string
    {
        if (!$this->hasRepoLink()) {
            return '`' . $label . '`';
        }
        $url = sprintf(
            'https://github.com/%s/pull/%s/files#diff-%s%s%d',
            $this->options['repo'],
            $this->options['pr-number'],
            hash('sha256', $file),
            $side,
            $line,
        );
        return '[`' . $label . '`](' . $url . ')';
    }

    private static function sourceFor(array $record): string
    {
        if (!empty($record['signature_source'])) {
            return rtrim($record['signature_source']);
        }
        return rtrim($record['signature']);
    }

    /**
     * Render the was → now diff. For short snippets (≤ 5 lines on both sides),
     * emit the full was prefixed by `-` and now by `+` inside a ```diff fence.
     * For longer snippets, emit a unified diff (only changed hunks) via `diff -u`.
     */
    private static function renderSignatureDiff(string $was, string $now): string
    {
        $wasLines = explode("\n", $was);
        $nowLines = explode("\n", $now);

        if (count($wasLines) <= 5 && count($nowLines) <= 5) {
            $body = '';
            foreach ($wasLines as $l) {
                $body .= '- ' . $l . "\n";
            }
            foreach ($nowLines as $l) {
                $body .= '+ ' . $l . "\n";
            }
            return "  ```diff\n" . self::indentBlock($body) . "  ```";
        }

        $diff = self::computeUnifiedDiff($was, $now);
        return "  ```diff\n" . self::indentBlock($diff) . "  ```";
    }

    private static function computeUnifiedDiff(string $was, string $now): string
    {
        $wasFile = tempnam(sys_get_temp_dir(), 'asc_was_');
        $nowFile = tempnam(sys_get_temp_dir(), 'asc_now_');
        try {
            file_put_contents($wasFile, $was . "\n");
            file_put_contents($nowFile, $now . "\n");
            $cmd = sprintf(
                'diff -U1 --label was --label now %s %s',
                escapeshellarg($wasFile),
                escapeshellarg($nowFile),
            );
            $out = shell_exec($cmd) ?? '';
        } finally {
            @unlink($wasFile);
            @unlink($nowFile);
        }

        // Strip the file headers (--- was / +++ now) — the @@ hunks are what's useful.
        $lines = explode("\n", rtrim($out, "\n"));
        $kept = [];
        foreach ($lines as $line) {
            if (str_starts_with($line, '--- ') || str_starts_with($line, '+++ ')) {
                continue;
            }
            $kept[] = $line;
        }
        return implode("\n", $kept) . "\n";
    }

    private static function indentBlock(string $block): string
    {
        $lines = explode("\n", rtrim($block, "\n"));
        return implode("\n", array_map(static fn (string $l) => '  ' . $l, $lines)) . "\n";
    }
}
