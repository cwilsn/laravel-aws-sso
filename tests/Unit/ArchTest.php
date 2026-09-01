<?php

declare(strict_types=1);

arch('the package never shells out')
    ->expect(['exec', 'shell_exec', 'system', 'passthru', 'popen', 'proc_open', 'pcntl_exec'])
    ->not->toBeUsed();

arch('the package never writes to the filesystem')
    ->expect(['file_put_contents', 'fopen', 'fwrite', 'unlink', 'mkdir', 'touch', 'copy', 'rename'])
    ->not->toBeUsed();

arch('the package avoids debugging helpers')
    ->preset()->php();

arch('exceptions are final and marked as ours')
    ->expect([
        LaravelAwsSso\Exceptions\AwsAuthenticationFailed::class,
        LaravelAwsSso\Exceptions\AwsCliNotFound::class,
        LaravelAwsSso\Exceptions\StaticCredentialsDetected::class,
        LaravelAwsSso\Exceptions\UnexpectedAwsAccount::class,
        LaravelAwsSso\Exceptions\UnexpectedAwsRole::class,
    ])
    ->toBeFinal()
    ->toExtend(RuntimeException::class)
    ->toImplement(LaravelAwsSso\Exceptions\LaravelAwsSsoException::class);

arch('source is strictly typed')
    ->expect('LaravelAwsSso')
    ->toUseStrictTypes();

it('never references aws credential or sso cache locations', function (): void {
    $forbidden = [
        '.aws/credentials',
        '.aws/config',
        'sso/cache',
        'AWS_SECRET_ACCESS_KEY=',
    ];

    /** @var SplFileInfo $file */
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__.'/../../src')) as $file) {
        if ($file->isDir() || $file->getExtension() !== 'php') {
            continue;
        }

        $contents = (string) file_get_contents($file->getPathname());

        foreach ($forbidden as $needle) {
            expect($contents)->not->toContain($needle);
        }
    }
});
