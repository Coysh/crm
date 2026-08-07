<?php

declare(strict_types=1);

namespace CoyshCRM\Services;

/**
 * Minimal parser for the Prometheus text exposition format.
 *
 * Only what Uptime Kuma's /metrics endpoint emits is supported: plain gauges
 * with quoted string labels. No histograms, no exemplars.
 */
final class PrometheusParser
{
    /**
     * @return array<int, array{metric: string, labels: array<string, ?string>, value: ?float}>
     */
    public static function parse(string $text): array
    {
        $samples = [];

        foreach (preg_split('/\r\n|\n|\r/', $text) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') continue;

            // metric_name{label="value",...} 123 [timestamp]
            if (!preg_match('/^([a-zA-Z_:][a-zA-Z0-9_:]*)(?:\{(.*)\})?\s+(\S+)/', $line, $m)) {
                continue;
            }

            $samples[] = [
                'metric' => $m[1],
                'labels' => self::parseLabels($m[2] ?? ''),
                'value'  => self::parseValue($m[3]),
            ];
        }

        return $samples;
    }

    /** @return array<string, ?string> */
    private static function parseLabels(string $raw): array
    {
        if (trim($raw) === '') return [];

        $labels = [];
        preg_match_all('/([a-zA-Z_][a-zA-Z0-9_]*)="((?:[^"\\\\]|\\\\.)*)"/', $raw, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            $value = strtr($match[2], ['\\\\' => '\\', '\\"' => '"', '\\n' => "\n"]);

            // prom-client stringifies Uptime Kuma's null hostname/port/url as the
            // literal "null" — don't let that become a hostname called "null".
            $labels[$match[1]] = ($value === '' || $value === 'null') ? null : $value;
        }

        return $labels;
    }

    /**
     * Non-numeric values (Nan, +Inf, -Inf) mean "unknown", not zero — coercing
     * a NaN response time to 0 would poison the response-time average.
     */
    private static function parseValue(string $raw): ?float
    {
        return is_numeric($raw) ? (float)$raw : null;
    }
}
