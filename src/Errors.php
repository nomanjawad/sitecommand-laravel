<?php

namespace Sitecommand\Laravel;

/**
 * Recent server errors, read from the end of the app's own log
 * (storage/logs/laravel.log, or the newest laravel-YYYY-MM-DD.log). Errors
 * that happened before the connector was installed show up too, and nothing
 * extra runs on every request. Messages and stacks are masked.
 */
final class Errors
{
    private const HEADER = '/^\[(\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:[+-]\d{2}:?\d{2}|Z)?)\]\s+([\w.-]+)\.(EMERGENCY|ALERT|CRITICAL|ERROR):\s?(.*)$/';

    /** @return array{ok: true, source: string|null, errors: list<array<string, mixed>>} */
    public static function recent(): array
    {
        $file = self::logFile();
        if ($file === null) {
            return ['ok' => true, 'source' => null, 'errors' => []];
        }
        $text = self::tail($file, (int) config('sitecommand.errors.max_bytes', 512 * 1024));

        return ['ok' => true, 'source' => Health::relative($file), 'errors' => self::parse($text, rtrim(base_path(), '/').'/', (int) config('sitecommand.errors.max_entries', 100))];
    }

    public static function logFile(): ?string
    {
        $dir = storage_path('logs');
        $candidates = array_merge(glob($dir.'/laravel.log') ?: [], glob($dir.'/laravel-*.log') ?: []);
        $candidates = array_filter($candidates, 'is_readable');
        if ($candidates === []) {
            return null;
        }
        usort($candidates, fn ($a, $b) => filemtime($b) <=> filemtime($a));

        return $candidates[0];
    }

    private static function tail(string $file, int $bytes): string
    {
        $size = filesize($file) ?: 0;
        $h = fopen($file, 'rb');
        if (! $h) {
            return '';
        }
        if ($size > $bytes) {
            fseek($h, -$bytes, SEEK_END);
        }
        $text = (string) stream_get_contents($h);
        fclose($h);
        if ($size > $bytes) {
            // Start at the first whole entry.
            $pos = strpos($text, "\n[");
            $text = $pos === false ? '' : substr($text, $pos + 1);
        }

        return $text;
    }

    /**
     * Log text into grouped error records, newest first. Pure.
     *
     * @return list<array{message: string, name: string, where: string, stack: string, count: int, first: string, last: string, level: string}>
     */
    public static function parse(string $text, string $basePath = '', int $max = 100): array
    {
        $entries = [];
        $current = null;
        foreach (preg_split('/\r?\n/', $text) ?: [] as $line) {
            if (preg_match(self::HEADER, $line, $m)) {
                if ($current) {
                    $entries[] = $current;
                }
                $current = ['time' => $m[1], 'level' => $m[3], 'first' => $m[4], 'rest' => []];
            } elseif (str_starts_with($line, '[') && preg_match('/^\[\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}/', $line)) {
                // A lower-level entry (INFO, WARNING…): ends the current error.
                if ($current) {
                    $entries[] = $current;
                }
                $current = null;
            } elseif ($current && count($current['rest']) < 40) {
                $current['rest'][] = $line;
            }
        }
        if ($current) {
            $entries[] = $current;
        }

        $groups = [];
        foreach ($entries as $e) {
            $first = $e['first'];
            $cut = strpos($first, ' {"');
            $message = trim($cut === false ? preg_replace('/\s+(\[\]\s*)+$/', '', $first) : substr($first, 0, $cut));
            $name = 'Error';
            $where = '';
            $context = $cut === false ? '' : substr($first, $cut);
            // Laravel writes the context as JSON: class names arrive as Foo\\\\Bar, paths may hold spaces.
            if (preg_match('/\[object\] \(([A-Za-z_\\\\][\w\\\\]*)\(code: [^)]*\): .*? at (\/[^"]+?|[A-Za-z]:[^"]+?):(\d+)\)/s', $context, $x)) {
                $name = str_replace('\\\\', '\\', $x[1]);
                $where = self::strip(str_replace('\\\\', '\\', $x[2]), $basePath).':'.$x[3];
            }
            $stack = [];
            foreach ($e['rest'] as $l) {
                if (preg_match('/^#\d+ /', $l)) {
                    $stack[] = self::strip($l, $basePath);
                }
                if (count($stack) >= 12) {
                    break;
                }
            }
            $message = Mask::text(self::strip($message, $basePath), 500);
            $key = $name.'|'.$message.'|'.$where;
            $time = self::iso($e['time']);
            if (isset($groups[$key])) {
                $groups[$key]['count']++;
                $groups[$key]['last'] = max($groups[$key]['last'], $time);
                $groups[$key]['first'] = min($groups[$key]['first'], $time);
            } else {
                $groups[$key] = ['message' => $message, 'name' => $name, 'where' => Mask::text($where, 200), 'stack' => Mask::text(implode("\n", $stack), 2000), 'count' => 1, 'first' => $time, 'last' => $time, 'level' => strtolower($e['level'])];
            }
        }
        $list = array_values($groups);
        usort($list, fn ($a, $b) => strcmp($b['last'], $a['last']));

        return array_slice($list, 0, $max);
    }

    private static function strip(string $text, string $basePath): string
    {
        return $basePath === '' ? $text : str_replace($basePath, '', $text);
    }

    private static function iso(string $time): string
    {
        $ts = strtotime($time);

        return $ts === false ? $time : gmdate('Y-m-d\TH:i:s\Z', $ts);
    }
}
