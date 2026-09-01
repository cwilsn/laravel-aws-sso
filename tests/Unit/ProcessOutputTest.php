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
