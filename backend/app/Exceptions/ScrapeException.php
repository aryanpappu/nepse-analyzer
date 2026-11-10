<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Raised when a source cannot produce usable rows — the page did not load, the
 * table is missing, or the markup no longer matches what the parser expects.
 *
 * The scrape manager catches this to fail over to the next configured source,
 * so it must never be used for programmer errors.
 */
class ScrapeException extends RuntimeException
{
    /** @var array<string, mixed> */
    public array $context = [];

    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(string $message, array $context = [], ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
        $this->context = $context;
    }

    public static function requestFailed(string $source, string $url, string $reason, ?Throwable $previous = null): self
    {
        return new self(
            "[{$source}] request to {$url} failed: {$reason}",
            ['source' => $source, 'url' => $url, 'reason' => $reason],
            $previous,
        );
    }

    public static function markupChanged(string $source, string $detail): self
    {
        return new self(
            "[{$source}] page structure did not match the parser: {$detail}",
            ['source' => $source, 'detail' => $detail],
        );
    }

    public static function tooFewRows(string $source, int $found, int $required): self
    {
        return new self(
            "[{$source}] returned {$found} rows, expected at least {$required} — refusing to persist a likely bad parse",
            ['source' => $source, 'found' => $found, 'required' => $required],
        );
    }
}
