<?php

declare(strict_types=1);

namespace LaravelAwsSso\Support;

/**
 * Helpers for turning raw AWS CLI output into something safe to show a developer.
 */
final class ProcessOutput
{
    private const int MAX_LINES = 5;

    private const int MAX_CHARACTERS = 500;

    /**
     * Reduce raw CLI output to a short, trimmed excerpt.
     *
     * AWS CLI errors are usually one or two lines, but a stack trace or a
     * paginated response should never be dumped into the developer's terminal.
     */
    public static function excerpt(string $output): string
    {
        $lines = array_filter(
            array_map(rtrim(...), preg_split('/\R/', trim($output)) ?: []),
            static fn (string $line): bool => $line !== '',
        );

        if ($lines === []) {
            return '';
        }

        $truncated = count($lines) > self::MAX_LINES;
        $excerpt = implode(PHP_EOL, array_slice($lines, 0, self::MAX_LINES));

        if (mb_strlen($excerpt) > self::MAX_CHARACTERS) {
            $excerpt = mb_substr($excerpt, 0, self::MAX_CHARACTERS);
            $truncated = true;
        }

        return $truncated ? $excerpt.PHP_EOL.'...' : $excerpt;
    }
}
