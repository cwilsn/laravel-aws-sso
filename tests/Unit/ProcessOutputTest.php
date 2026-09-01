<?php

declare(strict_types=1);

use LaravelAwsSso\Support\ProcessOutput;

it('returns an empty string for blank output', function (): void {
    expect(ProcessOutput::excerpt("  \n \n"))->toBe('');
});

it('keeps short output intact', function (): void {
    expect(ProcessOutput::excerpt("Error loading SSO Token\n"))->toBe('Error loading SSO Token');
});

it('drops blank lines', function (): void {
    expect(ProcessOutput::excerpt("first\n\n\nsecond"))->toBe("first\nsecond");
});

it('truncates output with too many lines', function (): void {
    $excerpt = ProcessOutput::excerpt(implode("\n", array_map(
        static fn (int $i): string => "line {$i}",
        range(1, 20),
    )));

    expect($excerpt)->toContain('line 5')
        ->and($excerpt)->not->toContain('line 6')
        ->and($excerpt)->toEndWith('...');
});

it('truncates very long single lines', function (): void {
    $excerpt = ProcessOutput::excerpt(str_repeat('a', 5000));

    expect(mb_strlen($excerpt))->toBeLessThan(600)
        ->and($excerpt)->toEndWith('...');
});

it('appends a single ellipsis when output is both too long and too many lines', function (): void {
    $excerpt = ProcessOutput::excerpt(implode("\n", array_map(
        static fn (int $i): string => str_repeat('x', 200)." {$i}",
        range(1, 20),
    )));

    expect(mb_strlen($excerpt))->toBeLessThan(600)
        ->and($excerpt)->toEndWith('...')
        ->and(mb_substr_count($excerpt, '...'))->toBe(1);
});

it('normalises carriage returns from windows aws cli output', function (): void {
    expect(ProcessOutput::excerpt("first\r\nsecond\r\n"))->toBe("first\nsecond");
});

it('trims trailing whitespace from each retained line', function (): void {
    expect(ProcessOutput::excerpt("first   \nsecond\t"))->toBe("first\nsecond");
});
