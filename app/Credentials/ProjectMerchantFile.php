<?php

namespace App\Credentials;

use App\Console\Output\ResponseBodySanitizer;
use App\Credentials\Exceptions\CredentialException;
use JsonException;
use stdClass;

final readonly class ProjectMerchantFile
{
    /** A binding holds one short merchant name, so anything larger is refused unread. */
    private const MAX_BYTES = 4096;

    /** @param ?int $owner Expected binding owner; defaults to the current user. */
    public function __construct(
        private ?string $directory = null,
        private ?int $owner = null,
    ) {}

    public function find(): ?string
    {
        $directory = $this->directory();
        while (true) {
            $path = $directory.'/.quickpay-cli.json';
            if (file_exists($path) || is_link($path)) {
                return $this->read($path);
            }
            $parent = dirname($directory);
            if ($parent === $directory) {
                return null;
            }
            $directory = $parent;
        }
    }

    public function save(string $merchant): void
    {
        MerchantName::validate($merchant);
        $directory = $this->directory();
        $path = $directory.'/.quickpay-cli.json';
        if (file_exists($path) || is_link($path)) {
            $this->read($path);
        }
        $temporary = @tempnam($directory, '..quickpay-cli.json.');
        if ($temporary === false) {
            throw new CredentialException('Unable to create a temporary project merchant binding.');
        }
        try {
            $json = json_encode(['merchant' => $merchant], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;
            if (@file_put_contents($temporary, $json) === false || ! @rename($temporary, $path)) {
                throw new CredentialException('Unable to save the project merchant binding atomically.');
            }
        } finally {
            if (is_file($temporary)) {
                @unlink($temporary);
            }
        }
    }

    /**
     * The current user's ID, which every binding must be owned by.
     *
     * Without the posix extension, a temporary file this process creates is
     * owned by the same user, so its owner stands in. If that cannot be read
     * either, no binding is trusted.
     */
    public static function currentUser(): int
    {
        return function_exists('posix_geteuid') ? posix_geteuid() : self::temporaryFileOwner();
    }

    public static function temporaryFileOwner(): int
    {
        $file = @tmpfile();
        $stat = $file === false ? false : @fstat($file);
        if ($file !== false) {
            fclose($file);
        }
        if ($stat === false) {
            throw new CredentialException('Unable to check who owns project merchant bindings.');
        }

        return $stat['uid'];
    }

    private function directory(): string
    {
        $directory = $this->directory ?? getcwd();
        $resolved = is_string($directory) ? realpath($directory) : false;
        if ($resolved === false || ! is_dir($resolved)) {
            throw new CredentialException('Unable to locate the current project directory.');
        }

        return $resolved;
    }

    private function read(string $path): string
    {
        $location = ResponseBodySanitizer::terminalLine($path, '');
        if (! is_file($path)) {
            throw new CredentialException("Project merchant binding {$location} cannot be read.");
        }
        // Upward discovery reaches shared directories such as /tmp. Another
        // user's binding must not steer which saved merchant this user runs,
        // so its owner is checked before anything is read. fileowner() follows
        // symlinks, so a link another user planted to one of this user's
        // bindings is checked by its own owner as well.
        $owner = $this->owner ?? self::currentUser();
        $link = is_link($path) ? @lstat($path) : null;
        if (@fileowner($path) !== $owner || ($link !== null && ($link === false || $link['uid'] !== $owner))) {
            throw new CredentialException("Project merchant binding {$location} is not owned by the current user.");
        }
        $contents = @file_get_contents($path, false, null, 0, self::MAX_BYTES + 1);
        if ($contents === false) {
            throw new CredentialException("Project merchant binding {$location} cannot be read.");
        }
        if (strlen($contents) > self::MAX_BYTES) {
            throw new CredentialException("Project merchant binding {$location} is too large to hold only a merchant name.");
        }
        try {
            $config = MerchantConfigurationJson::decode($contents);
        } catch (JsonException) {
            throw new CredentialException("Project merchant binding {$location} is invalid JSON or repeats a property.");
        }
        if (! $config instanceof stdClass || count(get_object_vars($config)) !== 1 || ! is_string($config->merchant ?? null)) {
            throw new CredentialException("Project merchant binding {$location} must contain only a merchant name.");
        }

        try {
            return MerchantName::validate($config->merchant);
        } catch (CredentialException $exception) {
            throw new CredentialException("Project merchant binding {$location}: {$exception->getMessage()}");
        }
    }
}
