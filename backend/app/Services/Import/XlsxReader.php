<?php

declare(strict_types=1);

namespace App\Services\Import;

use RuntimeException;
use SimpleXMLElement;
use ZipArchive;

/**
 * A minimal, dependency-free reader for the one thing this project needs out
 * of an `.xlsx`: the cell text of a worksheet, addressed by row number and
 * column letter.
 *
 * ## Why this exists rather than PhpSpreadsheet
 *
 * PhpSpreadsheet is not installed and adding it is not on the table. That is
 * survivable because an `.xlsx` is a zip of XML and a broker statement uses
 * none of the format's hard parts: no formulas, no charts, no pivot caches,
 * and — in the file this was built against — not a single natively-typed date
 * or number. Every visible value is either a shared string or a raw numeric
 * literal. So the whole job is: open the zip, resolve `sharedStrings.xml`,
 * walk `sheetData`.
 *
 * ## What it deliberately does *not* do
 *
 * It returns **text, exactly as stored**, and never interprets it. In
 * particular it does not apply number formats, so a cell the spreadsheet
 * *displays* as `7/17/2000` because of a date style would come back as the
 * serial `36723`, not as a date. That is a real limitation and it is the
 * honest one: guessing at styles is where a reader silently moves a
 * transaction by a decade. The NAASA export stores its dates as strings, the
 * parser above asserts the header it found, and a serial leaking through would
 * fail {@see BrokerStatementParser}'s date check loudly rather than quietly.
 *
 * ## Namespace-agnostic on purpose
 *
 * The file this was built against writes every element with an `x:` prefix
 * (`<x:row>`, `<x:c>`) while most writers emit the same elements unprefixed.
 * Both are the same namespace, so every lookup here goes through an XPath
 * bound to the SpreadsheetML namespace URI and never through a literal prefix
 * — matching on `row` alone would read this file as empty.
 *
 * Pure apart from the filesystem: no database, no container, no clock.
 */
final class XlsxReader
{
    /** SpreadsheetML. Bound to a prefix of our own choosing for every XPath. */
    private const NS_MAIN = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';

    /** The relationship namespaces, for resolving a sheet name to its part. */
    private const NS_REL = 'http://schemas.openxmlformats.org/package/2006/relationships';

    private const NS_REL_ATTR = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

    /**
     * A guard, not a tuning knob. A statement is hundreds of rows; a file
     * claiming millions is either not a statement or is trying to exhaust
     * memory, and either way we would rather say so than swap.
     */
    private const MAX_ROWS = 100_000;

    /**
     * Every populated cell of a worksheet.
     *
     * Rows and columns are both sparse — a spreadsheet may skip row 14 and 15
     * entirely, and a row may carry `A`, `E` and `J` with nothing between —
     * so the result is keyed rather than positional and callers must not
     * assume a row number exists.
     *
     * @param  string|null  $sheetName  Which sheet; null takes the first.
     * @return array<int, array<string, string>> row number → column letter → text
     */
    public function read(string $path, ?string $sheetName = null): array
    {
        $zip = $this->open($path);

        try {
            $shared = $this->sharedStrings($zip);
            $sheetPath = $this->sheetPath($zip, $sheetName);
            $xml = $this->part($zip, $sheetPath);
        } finally {
            $zip->close();
        }

        return $this->cells($this->parse($xml, $sheetPath), $shared);
    }

    /**
     * The workbook's sheet names, in workbook order.
     *
     * @return list<string>
     */
    public function sheetNames(string $path): array
    {
        $zip = $this->open($path);

        try {
            $workbook = $this->parse($this->part($zip, 'xl/workbook.xml'), 'xl/workbook.xml');
        } finally {
            $zip->close();
        }

        $names = [];

        foreach ($this->xpath($workbook, '//m:sheets/m:sheet') as $sheet) {
            $names[] = (string) ($sheet['name'] ?? '');
        }

        return $names;
    }

    /**
     * Whether a file is plausibly an `.xlsx` at all.
     *
     * Checked by looking inside rather than at the extension, because the
     * caller's next step is to assert a header row and a wrong answer here
     * produces a far less legible error than "this is not a spreadsheet".
     */
    public function looksLikeXlsx(string $path): bool
    {
        if (! is_file($path) || ! is_readable($path)) {
            return false;
        }

        $zip = new ZipArchive;

        if ($zip->open($path) !== true) {
            return false;
        }

        $found = $zip->locateName('xl/workbook.xml') !== false;
        $zip->close();

        return $found;
    }

    // ------------------------------------------------------------------
    // Package mechanics
    // ------------------------------------------------------------------

    private function open(string $path): ZipArchive
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException("Cannot read the spreadsheet at [{$path}].");
        }

        $zip = new ZipArchive;
        $opened = $zip->open($path);

        if ($opened !== true) {
            throw new RuntimeException(
                "That file is not a readable .xlsx workbook (zip error {$opened}). "
                .'An .xlsx is a zip archive; a .xls or a CSV renamed to .xlsx will fail here.'
            );
        }

        return $zip;
    }

    private function part(ZipArchive $zip, string $name): string
    {
        $contents = $zip->getFromName($name);

        if ($contents === false) {
            throw new RuntimeException("The workbook is missing [{$name}] and cannot be read.");
        }

        return $contents;
    }

    /**
     * Which zip entry holds the requested sheet.
     *
     * Resolved through `workbook.xml` and its relationships rather than by
     * guessing `xl/worksheets/sheet1.xml`: the file this was built against
     * names its only sheet `sheet.xml` with `sheetId="2"`, so both the
     * conventional filename and the "sheet ids are 1-based and dense"
     * assumption are wrong here.
     */
    private function sheetPath(ZipArchive $zip, ?string $sheetName): string
    {
        $workbook = $this->parse($this->part($zip, 'xl/workbook.xml'), 'xl/workbook.xml');

        $relationId = null;
        $available = [];

        foreach ($this->xpath($workbook, '//m:sheets/m:sheet') as $sheet) {
            $name = (string) ($sheet['name'] ?? '');
            $available[] = $name;

            $id = $sheet->attributes(self::NS_REL_ATTR);
            $rid = $id === null ? null : (string) ($id['id'] ?? '');

            if ($sheetName === null || strcasecmp($name, $sheetName) === 0) {
                $relationId = $rid;
                break;
            }
        }

        if ($relationId === null || $relationId === '') {
            throw new RuntimeException(sprintf(
                'The workbook has no sheet named [%s]. It contains: %s.',
                (string) $sheetName,
                $available === [] ? 'no sheets at all' : implode(', ', $available),
            ));
        }

        $rels = $this->parse($this->part($zip, 'xl/_rels/workbook.xml.rels'), 'xl/_rels/workbook.xml.rels');
        $rels->registerXPathNamespace('r', self::NS_REL);

        $found = $rels->xpath(sprintf('//r:Relationship[@Id=%s]', $this->xpathLiteral($relationId)));

        if ($found === false || $found === null || $found === []) {
            throw new RuntimeException("The workbook references a sheet part [{$relationId}] that is not in the package.");
        }

        $target = (string) ($found[0]['Target'] ?? '');

        // Targets are relative to `xl/` and may be written with a leading
        // slash (absolute within the package) or with `./`.
        $target = ltrim($target, '/');
        $target = preg_replace('#^\./#', '', $target) ?? $target;

        return str_starts_with($target, 'xl/') ? $target : 'xl/'.$target;
    }

    /**
     * The shared string table, indexed as the cells reference it.
     *
     * @return list<string>
     */
    private function sharedStrings(ZipArchive $zip): array
    {
        if ($zip->locateName('xl/sharedStrings.xml') === false) {
            // Legal: a workbook whose every cell is inline or numeric.
            return [];
        }

        $xml = $this->parse($this->part($zip, 'xl/sharedStrings.xml'), 'xl/sharedStrings.xml');

        $strings = [];

        foreach ($this->xpath($xml, '//m:si') as $item) {
            // A string split across formatting runs is still one string, so
            // every `<t>` under the item is concatenated — except those under
            // `<rPh>`, which are phonetic guides shown alongside the text
            // rather than part of it, and would otherwise be interleaved into
            // the value.
            $strings[] = $this->textOf($item, './/m:t[not(ancestor::m:rPh)]');
        }

        return $strings;
    }

    // ------------------------------------------------------------------
    // Sheet mechanics
    // ------------------------------------------------------------------

    /**
     * @param  list<string>  $shared
     * @return array<int, array<string, string>>
     */
    private function cells(SimpleXMLElement $sheet, array $shared): array
    {
        $grid = [];
        $seen = 0;

        foreach ($this->xpath($sheet, '//m:sheetData/m:row') as $row) {
            if (++$seen > self::MAX_ROWS) {
                throw new RuntimeException(sprintf(
                    'This worksheet has more than %s rows, which is not a broker statement.',
                    number_format(self::MAX_ROWS),
                ));
            }

            $number = (int) ($row['r'] ?? 0);

            if ($number <= 0) {
                continue;
            }

            foreach ($this->xpath($row, 'm:c') as $cell) {
                $reference = (string) ($cell['r'] ?? '');
                $column = preg_replace('/[^A-Z]/', '', strtoupper($reference)) ?? '';

                if ($column === '') {
                    continue;
                }

                $value = $this->value($cell, $shared);

                // An empty cell is absent, not blank: the caller distinguishes
                // "this row has no Debit" from "this row has a Debit of ''",
                // and only the first is true of a credit row.
                if ($value === '') {
                    continue;
                }

                $grid[$number][$column] = $value;
            }
        }

        ksort($grid);

        return $grid;
    }

    /**
     * One cell's text.
     *
     * @param  list<string>  $shared
     */
    private function value(SimpleXMLElement $cell, array $shared): string
    {
        $type = (string) ($cell['t'] ?? 'n');

        if ($type === 'inlineStr') {
            return $this->textOf($cell, './/m:is//m:t[not(ancestor::m:rPh)]');
        }

        $raw = $this->textOf($cell, 'm:v');

        if ($raw === '') {
            // `<c r="J1" s="0" t="s"/>` — typed but valueless. Real, and in
            // the file this was built against. Reading the missing `<v>` as
            // shared-string index 0 would stamp the broker's name into a
            // random cell.
            return '';
        }

        return match ($type) {
            's' => $shared[(int) $raw] ?? '',
            // A formula's cached error text. Surfaced as-is so a `#REF!` in a
            // money column fails the parser's numeric check rather than
            // silently becoming zero.
            'e' => $raw,
            'b' => $raw === '1' ? 'TRUE' : 'FALSE',
            default => $raw,
        };
    }

    // ------------------------------------------------------------------
    // XML mechanics
    // ------------------------------------------------------------------

    private function parse(string $xml, string $part): SimpleXMLElement
    {
        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();

        // No entity substitution and no network: a workbook is untrusted
        // input, and `LIBXML_NOENT` here would turn a crafted DTD into an
        // XXE read of the server's filesystem.
        $parsed = simplexml_load_string($xml, SimpleXMLElement::class, LIBXML_NONET);

        $errors = libxml_get_errors();
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if ($parsed === false) {
            $first = $errors === [] ? 'malformed XML' : trim($errors[0]->message);

            throw new RuntimeException("The workbook's [{$part}] could not be parsed: {$first}");
        }

        $parsed->registerXPathNamespace('m', self::NS_MAIN);

        return $parsed;
    }

    /**
     * XPath against the SpreadsheetML namespace, with the prefix re-bound on
     * the node itself — SimpleXML does not inherit registrations onto the
     * elements a query returns, so a nested query would silently match
     * nothing without this.
     *
     * @return list<SimpleXMLElement>
     */
    private function xpath(SimpleXMLElement $node, string $query): array
    {
        $node->registerXPathNamespace('m', self::NS_MAIN);

        $found = $node->xpath($query);

        if ($found === false || $found === null) {
            return [];
        }

        foreach ($found as $child) {
            $child->registerXPathNamespace('m', self::NS_MAIN);
        }

        return array_values($found);
    }

    private function textOf(SimpleXMLElement $node, string $query): string
    {
        $text = '';

        foreach ($this->xpath($node, $query) as $part) {
            $text .= (string) $part;
        }

        // Non-breaking and zero-width spaces are routine in exported cells and
        // would defeat every `trim()` and every numeric check downstream.
        $text = str_replace(["\xc2\xa0", "\xe2\x80\x8b", "\xef\xbb\xbf"], ' ', $text);

        return trim($text);
    }

    /**
     * A safely quoted XPath string literal.
     *
     * Relationship ids are `rId2` in practice, but they come out of the file
     * and are therefore attacker-controlled; concatenating one into a query
     * unquoted is an XPath injection.
     */
    private function xpathLiteral(string $value): string
    {
        if (! str_contains($value, "'")) {
            return "'".$value."'";
        }

        if (! str_contains($value, '"')) {
            return '"'.$value.'"';
        }

        return 'concat('.implode(", \"'\", ", array_map(
            static fn (string $part): string => "'".$part."'",
            explode("'", $value),
        )).')';
    }
}
