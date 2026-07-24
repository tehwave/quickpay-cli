<?php

declare(strict_types=1);
use Illuminate\Container\Container;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\Foundation\Application;

final class PharSourceIntegrityVerifier
{
    private const BOX_RUNTIME_PREFIX = '.box/';

    /**
     * @return array{
     *     issues: array<int, string>,
     *     file_count: int,
     *     categories: array<string, int>
     * }
     */
    public static function verify(string $projectRoot, string $pharPath, string $buildVersion = 'dev'): array
    {
        $projectRoot = realpath($projectRoot) ?: $projectRoot;
        $pharPath = realpath($pharPath) ?: $pharPath;

        if (! is_file($pharPath)) {
            throw new RuntimeException("PHAR does not exist: {$pharPath}");
        }

        $expected = self::expectedContents($projectRoot, $buildVersion);
        $archive = self::pharContents($pharPath);
        $issues = self::compareContents($expected, $archive);

        array_push($issues, ...self::stubIssues($pharPath));

        return [
            'issues' => $issues,
            'file_count' => count($expected),
            'categories' => self::categoryCounts(array_keys($expected)),
        ];
    }

    /**
     * Builds the expected manifest exclusively from the checkout and the Box
     * compiler installed by Composer. No file list or hash is read from the
     * target PHAR.
     *
     * @return array<string, string>
     */
    public static function expectedContents(string $projectRoot, string $buildVersion): array
    {
        $projectRoot = rtrim(str_replace('\\', '/', realpath($projectRoot) ?: $projectRoot), '/');
        $box = self::boxConfiguration($projectRoot);
        self::assertSupportedBoxConfiguration($box);

        $contents = [];

        foreach ($box['directories'] as $directory) {
            foreach (self::directoryContents($projectRoot, $directory) as $path => $content) {
                $contents[$path] = $content;
            }
        }

        foreach (['composer.json', 'composer.lock', 'quickpay'] as $path) {
            $contents[$path] = self::read($projectRoot.'/'.$path);
        }

        $contents['quickpay'] = self::packagedLauncher($contents['quickpay']);
        $contents['config/app.php'] = self::expectedAppConfig($projectRoot, $buildVersion);

        foreach (self::boxRuntimeContents($projectRoot) as $path => $content) {
            $contents[$path] = $content;
        }

        ksort($contents);

        return $contents;
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
            $mode = self::comparisonMode($path);

            if ($mode === 'php'
                && self::semanticTokenStream($source[$path]) !== self::semanticTokenStream($archive[$path])) {
                $issues[] = "Semantic PHP mismatch: {$path}";
            } elseif ($mode === 'json' && ! self::jsonMatches($source[$path], $archive[$path])) {
                $issues[] = "Semantic JSON mismatch: {$path}";
            } elseif ($mode === 'exact' && $source[$path] !== $archive[$path]) {
                $issues[] = "Byte mismatch: {$path}";
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

    /** @return array<string, mixed> */
    private static function boxConfiguration(string $projectRoot): array
    {
        $decoded = json_decode(self::read($projectRoot.'/box.json'), true, 512, JSON_THROW_ON_ERROR);

        if (! is_array($decoded)) {
            throw new RuntimeException('box.json must contain a JSON object.');
        }

        return $decoded;
    }

    /** @param array<string, mixed> $box */
    private static function assertSupportedBoxConfiguration(array $box): void
    {
        $expected = [
            'chmod' => '0755',
            'directories' => ['app', 'bootstrap', 'config', 'vendor'],
            'files' => ['composer.json'],
            'exclude-composer-files' => false,
            'dump-autoload' => false,
            'exclude-dev-files' => false,
            'check-requirements' => true,
            'output' => 'quickpay.phar',
            'compression' => 'GZ',
            'compactors' => [
                'KevinGH\\Box\\Compactor\\Php',
                'KevinGH\\Box\\Compactor\\Json',
            ],
        ];

        foreach ($expected as $key => $value) {
            if (! array_key_exists($key, $box) || $box[$key] !== $value) {
                throw new RuntimeException("Unsupported box.json setting: {$key}");
            }
        }

        $unsupported = array_diff(array_keys($box), array_keys($expected));

        if ($unsupported !== []) {
            throw new RuntimeException('Unsupported box.json keys: '.implode(', ', $unsupported));
        }
    }

    /** @return array<string, string> */
    private static function directoryContents(string $projectRoot, string $directory): array
    {
        $directoryPath = $projectRoot.'/'.$directory;

        if (! is_dir($directoryPath)) {
            throw new RuntimeException("Configured source directory does not exist: {$directoryPath}");
        }

        $contents = [];
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directoryPath, RecursiveDirectoryIterator::SKIP_DOTS),
        );

        /** @var SplFileInfo $file */
        foreach ($files as $file) {
            if (! $file->isFile()) {
                continue;
            }

            $path = str_replace('\\', '/', $file->getPathname());
            $relativePath = substr($path, strlen($projectRoot) + 1);

            if (self::hasHiddenPathSegment($relativePath)) {
                continue;
            }

            $contents[$relativePath] = self::read($path);
        }

        ksort($contents);

        return $contents;
    }

    private static function hasHiddenPathSegment(string $path): bool
    {
        foreach (explode('/', $path) as $segment) {
            if (str_starts_with($segment, '.')) {
                return true;
            }
        }

        return false;
    }

    private static function packagedLauncher(string $source): string
    {
        return preg_replace('/^#![^\r\n]*(?:\r\n|\r|\n)/', '', $source, 1) ?? $source;
    }

    private static function expectedAppConfig(string $projectRoot, string $buildVersion): string
    {
        if ($buildVersion === '' || preg_match('/[\x00-\x1F\x7F]/', $buildVersion) === 1) {
            throw new RuntimeException('The expected build version must be a non-empty single-line string.');
        }

        require_once $projectRoot.'/vendor/autoload.php';

        $container = Container::getInstance();

        if (! $container instanceof Application
            || ! $container->bound('git.version')) {
            $app = require $projectRoot.'/bootstrap/app.php';
            $kernel = $app->make(Kernel::class);
            $kernel->bootstrap();
        }

        $config = (static fn (string $path): mixed => include $path)($projectRoot.'/config/app.php');

        if (! is_array($config)) {
            throw new RuntimeException('config/app.php must return an array.');
        }

        $config['env'] = 'production';
        $config['version'] = $buildVersion;

        return '<?php return '.var_export($config, true).';'.PHP_EOL;
    }

    /** @return array<string, string> */
    private static function boxRuntimeContents(string $projectRoot): array
    {
        static $cache = [];

        $boxPath = realpath($projectRoot.'/vendor/laravel-zero/framework/bin/box');

        if ($boxPath === false || ! is_file($boxPath)) {
            throw new RuntimeException('The Laravel Zero Box compiler is missing.');
        }

        $cacheKey = hash_file('sha256', $boxPath);

        if ($cacheKey === false) {
            throw new RuntimeException('Unable to hash the Laravel Zero Box compiler.');
        }

        if (isset($cache[$cacheKey])) {
            return $cache[$cacheKey];
        }

        $alias = 'quickpay-box-source-'.substr($cacheKey, 0, 16).'.phar';
        Phar::loadPhar($boxPath, $alias);
        $resourceRoot = "phar://{$alias}/res/requirement-checker";
        $contents = [];
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($resourceRoot, RecursiveDirectoryIterator::SKIP_DOTS),
        );

        /** @var SplFileInfo $file */
        foreach ($files as $file) {
            if (! $file->isFile()) {
                continue;
            }

            $path = str_replace('\\', '/', $file->getPathname());
            $relativePath = substr($path, strlen($resourceRoot) + 1);
            $contents[self::BOX_RUNTIME_PREFIX.$relativePath] = self::read($path);
        }

        $dumper = self::read("phar://{$alias}/src/RequirementChecker/RequirementsDumper.php");
        $namespace = preg_replace('/\\\\KevinGH\\\\Box\\\\RequirementChecker$/', '', self::phpNamespace($dumper));

        if (! is_string($namespace) || $namespace === '') {
            throw new RuntimeException('Unable to determine the Box compiler namespace.');
        }

        $contents['.box/.requirements.php'] = self::expectedRequirementsPhp($projectRoot, $namespace);
        ksort($contents);

        return $cache[$cacheKey] = $contents;
    }

    private static function phpNamespace(string $php): string
    {
        if (preg_match('/\bnamespace\s+([^;]+);/', $php, $matches) !== 1) {
            throw new RuntimeException('Unable to determine the Box runtime namespace.');
        }

        return trim($matches[1]);
    }

    private static function expectedRequirementsPhp(string $projectRoot, string $namespace): string
    {
        $composer = self::decodeJsonObject($projectRoot.'/composer.json');
        $lock = self::decodeJsonObject($projectRoot.'/composer.lock');
        $requirements = self::composerRequirements($composer, $lock);

        return "<?php\n\nnamespace {$namespace};\n\nreturn ".var_export($requirements, true).';';
    }

    /**
     * @param  array<string, mixed>  $composer
     * @param  array<string, mixed>  $lock
     * @return array<int, array{type: string, condition: string, source: ?string, message: string, helpMessage: string}>
     */
    private static function composerRequirements(array $composer, array $lock): array
    {
        $requirements = [];
        $required = ['zlib' => [null]];
        $provided = [];
        $conflicting = [];
        $php = $lock['platform']['php'] ?? $composer['require']['php'] ?? null;

        if (is_string($php)) {
            $requirements[] = self::requirement('php', $php, null);
        }

        self::collectExtensions($lock['platform'] ?? [], null, $required);

        foreach ($lock['packages'] ?? [] as $package) {
            if (! is_array($package) || ! is_string($package['name'] ?? null)) {
                continue;
            }

            $name = $package['name'];
            self::collectExtensions($package['require'] ?? [], $name, $required);

            if (array_key_exists('provide', $package) && is_array($package['provide'])) {
                self::collectExtensions($package['provide'], $name, $provided);
            } elseif (($extension = self::polyfilledExtension($name)) !== null) {
                $provided[$extension][] = $name;
            }

            self::collectExtensions($package['conflict'] ?? [], $name, $conflicting);
        }

        foreach ($composer['require'] ?? [] as $name => $constraint) {
            if (! is_string($name)) {
                continue;
            }

            if (($extension = self::polyfilledExtension($name)) !== null) {
                $provided[$extension][] = null;
            } else {
                self::collectExtensions([$name => $constraint], null, $required);
            }
        }

        self::collectExtensions($composer['conflict'] ?? [], null, $conflicting);

        foreach (array_keys($provided) as $extension) {
            unset($required[$extension]);
        }

        uksort($required, 'strnatcmp');
        uksort($conflicting, 'strnatcmp');

        foreach ($required as $extension => $sources) {
            foreach (self::sortedDistinctSources($sources) as $source) {
                $requirements[] = self::requirement('extension', $extension, $source);
            }
        }

        foreach ($conflicting as $extension => $sources) {
            foreach (self::sortedDistinctSources($sources) as $source) {
                $requirements[] = self::requirement('extension-conflict', $extension, $source);
            }
        }

        return $requirements;
    }

    /**
     * @param  array<string, mixed>  $constraints
     * @param  array<string, array<int, ?string>>  $target
     */
    private static function collectExtensions(array $constraints, ?string $source, array &$target): void
    {
        foreach (array_keys($constraints) as $package) {
            if (! is_string($package) || ! str_starts_with($package, 'ext-')) {
                continue;
            }

            $extension = substr($package, 4);
            $extension = $extension === 'zend-opcache' ? 'zend opcache' : $extension;
            $target[$extension][] = $source;
        }
    }

    private static function polyfilledExtension(string $package): ?string
    {
        $known = [
            'paragonie/sodium_compat' => 'libsodium',
            'phpseclib/mcrypt_compat' => 'mcrypt',
        ];

        if (isset($known[$package])) {
            return $known[$package];
        }

        if (preg_match('#^symfony/polyfill-(.+)$#', $package, $matches) !== 1
            || str_starts_with($matches[1], 'php')) {
            return null;
        }

        return $matches[1];
    }

    /**
     * @param  array<int, ?string>  $sources
     * @return array<int, ?string>
     */
    private static function sortedDistinctSources(array $sources): array
    {
        $sources = array_values(array_unique($sources, SORT_REGULAR));
        natsort($sources);

        return array_values($sources);
    }

    /** @return array{type: string, condition: string, source: ?string, message: string, helpMessage: string} */
    private static function requirement(string $type, string $condition, ?string $source): array
    {
        if ($type === 'php') {
            $message = $source === null
                ? "This application requires a PHP version matching \"{$condition}\"."
                : "The package \"{$source}\" requires a PHP version matching \"{$condition}\".";

            return compact('type', 'condition', 'source', 'message') + ['helpMessage' => $message];
        }

        if ($type === 'extension') {
            $message = $source === null
                ? "This application requires the extension \"{$condition}\"."
                : "The package \"{$source}\" requires the extension \"{$condition}\".";
            $helpMessage = $message.' You either need to enable it or request the application to be shipped with a polyfill for this extension.';

            return compact('type', 'condition', 'source', 'message', 'helpMessage');
        }

        $message = $source === null
            ? "This application conflicts with the extension \"{$condition}\"."
            : "The package \"{$source}\" conflicts with the extension \"{$condition}\".";
        $helpMessage = $message.' You need to disable it in order to run this application.';

        return compact('type', 'condition', 'source', 'message', 'helpMessage');
    }

    /** @return array<string, mixed> */
    private static function decodeJsonObject(string $path): array
    {
        $decoded = json_decode(self::read($path), true, 512, JSON_THROW_ON_ERROR);

        if (! is_array($decoded)) {
            throw new RuntimeException("Expected a JSON object: {$path}");
        }

        return $decoded;
    }

    /** @return array<string, string> */
    private static function pharContents(string $pharPath): array
    {
        $hash = hash_file('sha256', $pharPath);

        if ($hash === false) {
            throw new RuntimeException("Unable to hash PHAR: {$pharPath}");
        }

        $alias = 'quickpay-source-integrity-'.substr($hash, 0, 16).'.phar';
        Phar::loadPhar($pharPath, $alias);
        $prefix = "phar://{$alias}/";
        $contents = [];
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($prefix, RecursiveDirectoryIterator::SKIP_DOTS),
        );

        /** @var SplFileInfo $file */
        foreach ($files as $file) {
            if (! $file->isFile()) {
                continue;
            }

            $path = str_replace('\\', '/', $file->getPathname());
            $relativePath = substr($path, strlen($prefix));
            $contents[$relativePath] = self::read($path);
        }

        ksort($contents);

        return $contents;
    }

    /** @return array<int, string> */
    private static function stubIssues(string $pharPath): array
    {
        $binary = self::read($pharPath);
        $halt = '__HALT_COMPILER(); ?>';
        $end = strpos($binary, $halt);

        if ($end === false) {
            return ['Invalid PHAR stub: __HALT_COMPILER marker is missing.'];
        }

        $stub = substr($binary, 0, $end + strlen($halt));

        if (preg_match("/Phar::mapPhar\\('([^']+)'\\);/", $stub, $matches) !== 1) {
            return ['Invalid PHAR stub: generated alias is missing.'];
        }

        $alias = $matches[1];

        if (preg_match('/^box-auto-generated-alias-[a-f0-9]{12}\\.phar$/', $alias) !== 1) {
            return ['Invalid PHAR stub: generated alias has an unexpected format.'];
        }

        $normalized = str_replace($alias, '{generated-alias}', $stub);
        $expected = <<<'PHP'
#!/usr/bin/env php
<?php
Phar::mapPhar('{generated-alias}');
require 'phar://{generated-alias}/.box/bin/check-requirements.php';
$_SERVER['SCRIPT_FILENAME'] = 'phar://{generated-alias}/quickpay';
require 'phar://{generated-alias}/quickpay';
__HALT_COMPILER(); ?>
PHP;

        return self::semanticTokenStream($normalized) === self::semanticTokenStream($expected)
            ? []
            : ['Semantic PHP mismatch: @phar/stub.php'];
    }

    private static function comparisonMode(string $path): string
    {
        if ($path === 'quickpay' || str_ends_with($path, '.php')) {
            return 'php';
        }

        if (basename($path) === 'composer.lock' || str_ends_with($path, '.json')) {
            return 'json';
        }

        return 'exact';
    }

    private static function jsonMatches(string $expected, string $actual): bool
    {
        try {
            $expectedValue = json_decode($expected, true, 512, JSON_THROW_ON_ERROR);
            $actualValue = json_decode($actual, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return false;
        }

        return self::canonicalJsonValue($expectedValue) === self::canonicalJsonValue($actualValue);
    }

    private static function canonicalJsonValue(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (! array_is_list($value)) {
            ksort($value);
        }

        foreach ($value as $key => $item) {
            $value[$key] = self::canonicalJsonValue($item);
        }

        return $value;
    }

    /**
     * @param  array<int, string>  $paths
     * @return array<string, int>
     */
    private static function categoryCounts(array $paths): array
    {
        $counts = [
            'app' => 0,
            'bootstrap' => 0,
            'config' => 0,
            'launcher' => 1,
            'composer' => 0,
            'vendor' => 0,
            'box-runtime' => 0,
        ];

        foreach ($paths as $path) {
            if (str_starts_with($path, 'app/')) {
                $counts['app']++;
            } elseif (str_starts_with($path, 'bootstrap/')) {
                $counts['bootstrap']++;
            } elseif (str_starts_with($path, 'config/')) {
                $counts['config']++;
            } elseif ($path === 'quickpay') {
                $counts['launcher']++;
            } elseif ($path === 'composer.json' || $path === 'composer.lock') {
                $counts['composer']++;
            } elseif (str_starts_with($path, 'vendor/')) {
                $counts['vendor']++;
            } elseif (str_starts_with($path, self::BOX_RUNTIME_PREFIX)) {
                $counts['box-runtime']++;
            }
        }

        return $counts;
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
    $buildVersion = $argv[2] ?? 'dev';

    try {
        $result = PharSourceIntegrityVerifier::verify($projectRoot, $pharPath, $buildVersion);
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

    $categories = [];

    foreach ($result['categories'] as $category => $count) {
        $categories[] = "{$category}={$count}";
    }

    fwrite(
        STDOUT,
        "Verified complete PHAR integrity: {$result['file_count']} files plus stub match source (".implode(', ', $categories).").\n",
    );
}
