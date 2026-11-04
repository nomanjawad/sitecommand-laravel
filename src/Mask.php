<?php

namespace Sitecommand\Laravel;

/**
 * Hide secrets before anything leaves the app: log lines, messages and
 * stack traces can contain tokens, passwords, emails or connection strings.
 * Same rules as @sitecommand/node.
 */
final class Mask
{
    private const RULES = [
        // URLs with credentials: mysql://user:pass@host
        ['~([a-z][a-z0-9+.-]*://)[^\s/:@]+:[^\s/@]+@~i', '$1***:***@'],
        // Bearer / Basic tokens
        ['~\b(Bearer|Basic)\s+[A-Za-z0-9._\~+/=-]{8,}~', '$1 ***'],
        // key=value style secrets
        ['~\b([A-Za-z0-9_-]*(?:pass(?:word)?|secret|token|api[_-]?key|private[_-]?key|auth)[A-Za-z0-9_-]*)\s*[=:]\s*["\']?[^\s"\',;]{3,}~i', '$1=***'],
        // JWTs
        ['~\beyJ[A-Za-z0-9_-]{10,}\.[A-Za-z0-9_-]{10,}\.[A-Za-z0-9_-]{10,}~', '***jwt***'],
        // well-known key shapes (Stripe, GitHub, AWS, Slack, Google)
        ['~\b(sk|pk|rk)_(live|test)_[A-Za-z0-9]{10,}~', '***key***'],
        ['~\bgh[pousr]_[A-Za-z0-9]{20,}~', '***key***'],
        ['~\bAKIA[0-9A-Z]{16}\b~', '***key***'],
        ['~\bxox[abposr]-[A-Za-z0-9-]{10,}~', '***key***'],
        ['~\bAIza[0-9A-Za-z_-]{30,}~', '***key***'],
        // Laravel APP_KEY values
        ['~\bbase64:[A-Za-z0-9+/]{20,}={0,2}~', '***key***'],
        // emails
        ['~\b[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}\b~', '***@***'],
        // long hex or base64 blobs (secrets, hashes)
        ['~\b[0-9a-f]{40,}\b~i', '***'],
        // (a real blob mixes digits, upper and lower case; long file paths do not)
        ['~\b(?=[A-Za-z0-9+/]*\d)(?=[A-Za-z0-9+/]*[A-Z])(?=[A-Za-z0-9+/]*[a-z])[A-Za-z0-9+/]{48,}={0,2}~', '***'],
    ];

    public static function text(string $text, int $max = 4000): string
    {
        $out = strlen($text) > $max * 2 ? substr($text, 0, $max * 2) : $text;
        foreach (self::RULES as [$re, $to]) {
            $out = preg_replace($re, $to, $out) ?? $out;
        }
        if (! mb_check_encoding($out, 'UTF-8')) {
            $out = mb_convert_encoding($out, 'UTF-8', 'UTF-8');
        }

        return mb_strlen($out) > $max ? mb_substr($out, 0, $max).'…' : $out;
    }
}
