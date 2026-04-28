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
            $body .= self::renderSection('New API Surface', $added);
        }
        if ($showRemoved && !empty($removed)) {
            $body .= self::renderSection('Removed API Surface', $removed);
        }
        if ($showModified && !empty($modified)) {
            $body .= self::renderModifiedSection($modified);
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
    private static function renderSection(string $title, array $records): string
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
                $out .= self::renderRecord($r) . "\n";
            }
        }
        return $out . "\n";
    }

    private static function renderRecord(array $record): string
    {
        $loc = '`' . $record['file'] . ':' . $record['line'] . '`';
        if ($record['member'] !== null) {
            $label = $record['fqcn'] . '::' . $record['member'];
            return "- `{$label}` — `" . trim($record['signature']) . '` in ' . $loc;
        }
        return "- `" . trim($record['signature']) . '` in ' . $loc;
    }

    /**
     * @param list<array{head: array<string,mixed>, base: array<string,mixed>}> $changes
     */
    private static function renderModifiedSection(array $changes): string
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
                $out .= "- `{$label}` in `" . $h['file'] . ':' . $h['line'] . "`\n";
                $out .= "  - was: `" . trim($b['signature']) . "`\n";
                $out .= "  - now: `" . trim($h['signature']) . "`\n";
            }
        }
        return $out . "\n";
    }
}
