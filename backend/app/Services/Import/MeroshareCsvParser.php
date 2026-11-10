<?php

declare(strict_types=1);

namespace App\Services\Import;

use App\Support\Numeric;
use RuntimeException;

/**
 * Parses the two CSV exports MeroShare produces.
 *
 * 1. "My Portfolio" — what the demat account holds right now:
 *    S.N, Scrip, Current Balance, Last Closing Price, Value as of Last Closing
 *    Price, Last Transaction Price (LTP), Value as of LTP
 *    Ends with a "Total :" summary row that is not a holding.
 *
 * 2. "WACC" — the weighted average cost of every scrip the account has ever
 *    held, including ones since sold in full:
 *    S.N, Demat, Scrip Name, WACC Calculated Quantity, WACC Rate,
 *    Total Cost of Capital, Last Modification Date
 *
 * Columns are located by header name rather than position, because MeroShare
 * has changed the column order between releases and a positional parser would
 * silently import the wrong numbers rather than fail.
 */
final class MeroshareCsvParser
{
    /** Rows whose first cell looks like this are footers, not data. */
    private const FOOTER_MARKERS = ['total', 'total :', 'grand total', 's.n'];

    /**
     * Work out which of the two exports a file is, from its header row.
     *
     * @return 'holdings'|'wacc'
     */
    public function detectType(string $contents): string
    {
        $header = $this->normalizeHeaders($this->firstRow($contents));

        if ($this->findColumn($header, ['wacc rate', 'wacc calculated quantity']) !== null) {
            return 'wacc';
        }

        if ($this->findColumn($header, ['current balance']) !== null) {
            return 'holdings';
        }

        throw new RuntimeException(
            'This file does not look like a MeroShare export. Expected either a "My Portfolio" '
            .'file (with a "Current Balance" column) or a "WACC" file (with a "WACC Rate" column). '
            .'Columns found: '.implode(', ', $header)
        );
    }

    /**
     * Parse the "My Portfolio" export.
     *
     * @return list<array{symbol: string, quantity: float, last_price: ?float, value: ?float}>
     */
    public function parseHoldings(string $contents): array
    {
        [$header, $records] = $this->read($contents);

        $columns = [
            'symbol' => $this->requireColumn($header, ['scrip', 'scrip name', 'symbol'], 'Scrip'),
            'quantity' => $this->requireColumn($header, ['current balance', 'balance', 'quantity'], 'Current Balance'),
            'last_price' => $this->findColumn($header, ['last transaction price ltp', 'ltp', 'last closing price']),
            'value' => $this->findColumn($header, ['value as of ltp', 'value as of last closing price']),
        ];

        $rows = [];

        foreach ($records as $record) {
            $symbol = $this->symbol($record[$columns['symbol']] ?? null);

            if ($symbol === null) {
                continue;
            }

            $quantity = Numeric::parse($record[$columns['quantity']] ?? null);

            // A zero balance is not a holding; MeroShare omits them, but a
            // hand-edited file might not.
            if ($quantity === null || $quantity <= 0) {
                continue;
            }

            $rows[] = [
                'symbol' => $symbol,
                'quantity' => round($quantity, 2),
                'last_price' => Numeric::parse($record[$columns['last_price']] ?? null),
                'value' => Numeric::parse($record[$columns['value']] ?? null),
            ];
        }

        if ($rows === []) {
            throw new RuntimeException('No holdings were found in that file.');
        }

        return $rows;
    }

    /**
     * Parse the WACC export.
     *
     * @return array{rows: list<array{symbol: string, quantity: float, rate: float, total_cost: ?float, modified_at: ?string}>, demat: ?string}
     */
    public function parseWacc(string $contents): array
    {
        [$header, $records] = $this->read($contents);

        $columns = [
            'symbol' => $this->requireColumn($header, ['scrip name', 'scrip', 'symbol'], 'Scrip Name'),
            'quantity' => $this->requireColumn(
                $header,
                ['wacc calculated quantity', 'quantity', 'balance'],
                'WACC Calculated Quantity',
            ),
            'rate' => $this->requireColumn($header, ['wacc rate', 'rate'], 'WACC Rate'),
            'total_cost' => $this->findColumn($header, ['total cost of capital', 'total cost']),
            'modified_at' => $this->findColumn($header, ['last modification date', 'modification date']),
            'demat' => $this->findColumn($header, ['demat', 'demat account']),
        ];

        $rows = [];
        $demat = null;

        foreach ($records as $record) {
            $symbol = $this->symbol($record[$columns['symbol']] ?? null);

            if ($symbol === null) {
                continue;
            }

            $rate = Numeric::parse($record[$columns['rate']] ?? null);
            $quantity = Numeric::parse($record[$columns['quantity']] ?? null);

            if ($rate === null || $rate <= 0) {
                continue;
            }

            if ($demat === null && $columns['demat'] !== null) {
                $raw = Numeric::text($record[$columns['demat']] ?? null);
                $demat = $raw === '' ? null : $raw;
            }

            $rows[] = [
                'symbol' => $symbol,
                'quantity' => $quantity === null ? 0.0 : round($quantity, 2),
                'rate' => round($rate, 4),
                'total_cost' => Numeric::parse($record[$columns['total_cost']] ?? null),
                'modified_at' => $this->date($record[$columns['modified_at']] ?? null),
            ];
        }

        if ($rows === []) {
            throw new RuntimeException('No WACC entries were found in that file.');
        }

        return ['rows' => $rows, 'demat' => $demat];
    }

    /**
     * Keep only the last four digits of the demat account number.
     */
    public function maskDemat(?string $demat): ?string
    {
        $digits = preg_replace('/\D/', '', (string) $demat) ?? '';

        if (strlen($digits) < 4) {
            return null;
        }

        return '****'.substr($digits, -4);
    }

    // ------------------------------------------------------------------
    // CSV mechanics
    // ------------------------------------------------------------------

    /**
     * @return array{0: list<string>, 1: list<array<string, string>>}
     */
    private function read(string $contents): array
    {
        $lines = $this->rows($contents);

        if ($lines === []) {
            throw new RuntimeException('The file is empty.');
        }

        $header = $this->normalizeHeaders(array_shift($lines));
        $records = [];

        foreach ($lines as $line) {
            if ($this->isFooter($line)) {
                continue;
            }

            // Pad or trim so array_combine cannot fail on a ragged row.
            $line = array_slice(array_pad($line, count($header), ''), 0, count($header));

            $records[] = array_combine($header, $line);
        }

        return [$header, $records];
    }

    /**
     * @return list<list<string>>
     */
    private function rows(string $contents): array
    {
        // Strip a UTF-8 BOM, which Excel adds and which would otherwise become
        // part of the first header name.
        $contents = preg_replace('/^\xEF\xBB\xBF/', '', $contents) ?? $contents;
        $contents = str_replace(["\r\n", "\r"], "\n", $contents);

        $handle = fopen('php://temp', 'r+');

        if ($handle === false) {
            throw new RuntimeException('Could not open a temporary stream to read the CSV.');
        }

        fwrite($handle, $contents);
        rewind($handle);

        $rows = [];

        while (($row = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
            // fgetcsv yields [null] for a blank line.
            if ($row === [null] || $row === []) {
                continue;
            }

            $rows[] = array_map(static fn ($cell): string => Numeric::text((string) $cell), $row);
        }

        fclose($handle);

        return $rows;
    }

    /**
     * @return list<string>
     */
    private function firstRow(string $contents): array
    {
        $rows = $this->rows($contents);

        return $rows[0] ?? [];
    }

    /**
     * @param  list<string>  $row
     * @return list<string>
     */
    private function normalizeHeaders(array $row): array
    {
        return array_map(
            fn (string $cell): string => $this->normalize($cell),
            $row,
        );
    }

    private function normalize(string $value): string
    {
        $clean = strtolower(Numeric::text($value));

        return trim(preg_replace('/[^a-z0-9]+/', ' ', $clean) ?? '');
    }

    /**
     * @param  list<string>  $header
     * @param  list<string>  $aliases
     */
    private function findColumn(array $header, array $aliases): ?string
    {
        foreach ($aliases as $alias) {
            $needle = $this->normalize($alias);

            foreach ($header as $column) {
                if ($column === $needle) {
                    return $column;
                }
            }
        }

        // Substring fallback: "scrip name" should still match "scrip".
        foreach ($aliases as $alias) {
            $needle = $this->normalize($alias);

            foreach ($header as $column) {
                if ($needle !== '' && $column !== '' && str_contains($column, $needle)) {
                    return $column;
                }
            }
        }

        return null;
    }

    /**
     * @param  list<string>  $header
     * @param  list<string>  $aliases
     */
    private function requireColumn(array $header, array $aliases, string $label): string
    {
        $column = $this->findColumn($header, $aliases);

        if ($column === null) {
            throw new RuntimeException(
                "The file is missing a \"{$label}\" column. Columns found: ".implode(', ', array_filter($header))
            );
        }

        return $column;
    }

    /**
     * @param  list<string>  $row
     */
    private function isFooter(array $row): bool
    {
        $first = $this->normalize($row[0] ?? '');

        if ($first === '') {
            // A row whose every cell is blank or a dash.
            $meaningful = array_filter($row, static fn (string $cell): bool => trim($cell, " -\t") !== '');

            return $meaningful === [];
        }

        foreach (self::FOOTER_MARKERS as $marker) {
            if (str_starts_with($first, $this->normalize($marker))) {
                return true;
            }
        }

        return false;
    }

    private function symbol(?string $value): ?string
    {
        $clean = strtoupper(Numeric::text($value));
        $clean = preg_replace('/[^A-Z0-9\-]/', '', $clean) ?? '';

        // Guard against the footer's "TOTAL" landing in the symbol column.
        if ($clean === '' || $clean === 'TOTAL' || strlen($clean) > 24) {
            return null;
        }

        return $clean;
    }

    private function date(?string $value): ?string
    {
        $clean = Numeric::text($value);

        if ($clean === '') {
            return null;
        }

        // MeroShare writes "2026-05-21 13:55:52".
        if (preg_match('/(\d{4}-\d{2}-\d{2})/', $clean, $matches)) {
            return $matches[1];
        }

        return null;
    }
}
