<?php

declare(strict_types=1);

final class PharSourceIntegrityVerifier
{
    /**
     * @return array{issues: array<int, string>, file_count: int}
     */
    public static function verify(string $projectRoot, string $pharPath): array
    {
        $projectRoot = realpath($projectRoot) ?: $projectRoot;
        $pharPath = realpath($pharPath) ?: $pharPath;

        if (! is_file($pharPath)) {
            throw new RuntimeException("PHAR does not exist: {$pharPath}");
        }

        $source = self::sourceContents($projectRoot.'/app');
        $archive = self::pharContents($pharPath);

        return [
            'issues' => self::compareContents($source, $archive),
            'file_count' => count($source),
        ];
    }

    /**
     * @param  array<string, string>  $source
     * @param  array<string, string>  $archive
     * @return array<int, string>
     */
    public static function compareContents(array $source, array $archive): array
    {
        $sourcePaths = array_keys($source);
        $archivePaths = array_keys($archive);
        $missing = array_values(array_diff($sourcePaths, $archivePaths));
        $extra = array_values(array_diff($archivePaths, $sourcePaths));
        $shared = array_values(array_intersect($sourcePaths, $archivePaths));
        sort($missing);
        sort($extra);
        sort($shared);
        $issues = [];

        foreach ($missing as $path) {
            $issues[] = "Missing from PHAR: {$path}";
        }

        foreach ($extra as $path) {
            $issues[] = "Extra in PHAR: {$path}";
        }

        foreach ($shared as $path) {
            if (self::semanticTokenStream($source[$path]) !== self::semanticTokenStream($archive[$path])) {
                $issues[] = "Semantic PHP mismatch: {$path}";
            }
        }

        return $issues;
    }

    /** @return array<int, string> */
    public static function semanticTokenStream(string $php): array
    {
        $stream = [];

        foreach (token_get_all($php) as $token) {
            if (! is_array($token)) {
                $stream[] = 'CHAR'.chr(0).$token;

                continue;
            }

            [$id, $text] = $token;

            if (in_array($id, [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $stream[] = in_array($id, [T_OPEN_TAG, T_OPEN_TAG_WITH_ECHO], true)
                ? token_name($id)
                : token_name($id).chr(0).$text;
        }

        return $stream;
    }

    /** @return array<string, string> */
    private static function sourceContents(string $appPath): array
    {
        if (! is_dir($appPath)) {
            throw new RuntimeException("Source app directory does not exist: {$appPath}");
        }

        $appPath = rtrim(str_replace('\\', '/', realpath($appPath) ?: $appPath), '/');
        $contents = [];
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($appPath, RecursiveDirectoryIterator::SKIP_DOTS),
        );

        /** @var SplFileInfo $file */
        foreach ($files as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $path = str_replace('\\', '/', $file->getPathname());
            $relativePath = 'app/'.substr($path, strlen($appPath) + 1);
            $contents[$relativePath] = self::read($path);
        }

        ksort($contents);

        return $contents;
    }

    /** @return array<string, string> */
    private static function pharContents(string $pharPath): array
    {
        $alias = 'quickpay-source-integrity-'.substr(hash('sha256', $pharPath), 0, 12).'.phar';
        Phar::loadPhar($pharPath, $alias);
        $prefix = "phar://{$alias}/";
        $appPath = $prefix.'app';
        $contents = [];
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($appPath, RecursiveDirectoryIterator::SKIP_DOTS),
        );

        /** @var SplFileInfo $file */
        foreach ($files as $file) {
            $path = str_replace('\\', '/', $file->getPathname());

            if (! $file->isFile() || ! str_ends_with($path, '.php')) {
                continue;
            }

            $relativePath = substr($path, strlen($prefix));
            $contents[$relativePath] = self::read($path);
        }

        ksort($contents);

        return $contents;
    }

    private static function read(string $path): string
    {
        $contents = file_get_contents($path);

        if ($contents === false) {
            throw new RuntimeException("Unable to read: {$path}");
        }

        return $contents;
    }
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    $projectRoot = dirname(__DIR__);
    $pharPath = $argv[1] ?? $projectRoot.'/builds/quickpay';

    try {
        $result = PharSourceIntegrityVerifier::verify($projectRoot, $pharPath);
    } catch (Throwable $exception) {
        fwrite(STDERR, "PHAR source integrity verification failed: {$exception->getMessage()}\n");

        exit(1);
    }

    if ($result['issues'] !== []) {
        fwrite(STDERR, "PHAR source integrity verification failed:\n");

        foreach ($result['issues'] as $issue) {
            fwrite(STDERR, "- {$issue}\n");
        }

        exit(1);
    }

    fwrite(
        STDOUT,
        "Verified PHAR source integrity: {$result['file_count']} app PHP files match source.\n",
    );
}
