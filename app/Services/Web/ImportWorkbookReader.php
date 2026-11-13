<?php
namespace App\Services\Web;

use InvalidArgumentException;
use SimpleXMLElement;
use ZipArchive;

/** Narrow, bounded XLSX reader for our value-only Data sheet. Never evaluates formulas. */
final class ImportWorkbookReader
{
    public const MAX_ROWS = 5000;
    public const MAX_BYTES = 5242880;

    public function read(string $path, array $headers, array $optionalHeaders = []): array
    {
        if (!is_file($path) || filesize($path) > self::MAX_BYTES) throw new InvalidArgumentException('Choose an XLSX file no larger than 5 MB.');
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) throw new InvalidArgumentException('This is not a readable XLSX workbook.');
        try {
            $size = 0;
            if ($zip->numFiles > 500) throw new InvalidArgumentException('This workbook contains too many files.');
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $entry = $zip->statIndex($i);
                $size += $entry['size'];
                if ($entry['size'] > 16777216 || $size > 33554432) throw new InvalidArgumentException('Workbook expanded size exceeds the import limit.');
                if (preg_match('~(?:vbaProject|externalLinks|embeddings)~i', $entry['name'])) throw new InvalidArgumentException('Remove macros, external links and embedded objects before importing.');
            }
            $workbook = $this->xml($zip, 'xl/workbook.xml');
            if (!isset($workbook->sheets)) throw new InvalidArgumentException('Workbook is missing its worksheet list. Save a fresh XLSX copy.');
            $relationId = null;
            foreach ($workbook->sheets->sheet as $sheet) {
                if ((string)$sheet->attributes()['name'] === 'Data') {
                    $relationId = (string)$sheet->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'];
                }
            }
            if (!$relationId) throw new InvalidArgumentException('Keep the worksheet named Data, as in the sample.');
            $target = null;
            foreach ($this->xml($zip, 'xl/_rels/workbook.xml.rels') as $relation) {
                if ((string)$relation->attributes()['Id'] === $relationId && (string)$relation->attributes()['TargetMode'] !== 'External') $target = (string)$relation->attributes()['Target'];
            }
            if (!$target || str_contains($target, '..') || str_contains($target, '\\')) throw new InvalidArgumentException('Invalid Data worksheet reference.');
            $target = str_starts_with($target, '/') ? ltrim($target, '/') : 'xl/' . $target;
            $strings = [];
            if ($zip->locateName('xl/sharedStrings.xml') !== false) {
                foreach ($this->xml($zip, 'xl/sharedStrings.xml')->si as $item) $strings[] = $this->text($item);
            }
            $xml = $this->xml($zip, $target);
            if (!isset($xml->sheetData)) throw new InvalidArgumentException('The Data worksheet has no readable rows. Use the sample workbook.');
            $grid = []; $types = []; $cellCount = 0;
            foreach ($xml->sheetData->row as $row) {
                $number = (int)$row->attributes()['r'];
                if ($number < 1 || $number > self::MAX_ROWS + 1 || isset($grid[$number])) throw new InvalidArgumentException('Use at most 5,000 data rows with unique row numbers.');
                $grid[$number] = [];
                foreach ($row->c as $cell) {
                    if (++$cellCount > 120000) throw new InvalidArgumentException('Too many worksheet cells.');
                    $ref = (string)$cell->attributes()['r'];
                    if (!preg_match('/^([A-Z]{1,3})([1-9][0-9]*)$/D', $ref, $match) || (int)$match[2] !== $number) throw new InvalidArgumentException("Row $number: invalid cell reference.");
                    $column = 0;
                    foreach (str_split($match[1]) as $letter) $column = $column * 26 + ord($letter) - 64;
                    if ($column > 40 || isset($grid[$number][$column])) throw new InvalidArgumentException("Row $number: too many or duplicate columns.");
                    if (isset($cell->f)) throw new InvalidArgumentException("Row $number ($ref): formulas are not allowed. Paste values instead.");
                    $type = (string)$cell->attributes()['t'];
                    $value = (string)$cell->v;
                    if ($type === 's') {
                        if (!ctype_digit($value) || !array_key_exists((int)$value, $strings)) throw new InvalidArgumentException("Row $number ($ref): invalid text reference.");
                        $value = $strings[(int)$value];
                    } elseif ($type === 'inlineStr') $value = $this->text($cell->is);
                    elseif (!in_array($type, ['', 'n', 'str'], true)) throw new InvalidArgumentException("Row $number ($ref): use text or numbers, not dates, errors or booleans.");
                    if (mb_strlen($value) > 1000) throw new InvalidArgumentException("Row $number ($ref): cell text is too long.");
                    $grid[$number][$column] = trim($value);
                    $types[$number][$column] = $type;
                }
            }
            $heading = $grid[1] ?? [];
            $map = []; $seen = [];
            foreach ($heading as $column => $label) {
                if ($label === '') continue;
                if (!in_array($label, $headers, true) || isset($seen[$label])) throw new InvalidArgumentException("Row 1: unknown or duplicate header '$label'. Use the sample headers.");
                $map[$column] = $label; $seen[$label] = true;
            }
            $missing = array_diff($headers, array_keys($seen), $optionalHeaders);
            if ($missing) throw new InvalidArgumentException('Row 1: missing headers: ' . implode(', ', $missing) . '. Keep the required sample columns.');
            $rows = []; ksort($grid);
            foreach ($grid as $number => $cells) {
                if ($number === 1 || !array_filter($cells, static fn ($v) => $v !== '')) continue;
                $record = array_fill_keys($headers, '');
                foreach ($cells as $column => $value) {
                    if (!isset($map[$column]) && $value !== '') throw new InvalidArgumentException("Row $number: value appears under an empty header.");
                    if ($value !== '' && in_array($map[$column] ?? '', ['phone', 'barcode', 'sealed_barcode'], true) && !in_array($types[$number][$column], ['s', 'str', 'inlineStr'], true)) throw new InvalidArgumentException("Row $number: format {$map[$column]} as Text and re-enter the value to preserve leading zeros and + signs.");
                    if (isset($map[$column])) $record[$map[$column]] = $value;
                }
                $rows[$number] = $record;
            }
            if (!$rows) throw new InvalidArgumentException('The Data sheet contains no records.');
            return $rows;
        } finally { $zip->close(); }
    }

    private function xml(ZipArchive $zip, string $name): SimpleXMLElement
    {
        $content = $zip->getFromName($name);
        if ($content === false || stripos($content, '<!DOCTYPE') !== false || stripos($content, '<!ENTITY') !== false) throw new InvalidArgumentException('Invalid or unsafe workbook XML.');
        $previous = libxml_use_internal_errors(true);
        try {
            $xml = simplexml_load_string($content, SimpleXMLElement::class, LIBXML_NONET | LIBXML_COMPACT);
            if ($xml === false) throw new InvalidArgumentException('Workbook XML is damaged. Save a fresh XLSX copy.');
            $namespace = $xml->getName() === 'Relationships'
                ? 'http://schemas.openxmlformats.org/package/2006/relationships'
                : 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
            return $xml->children($namespace);
        } finally { libxml_clear_errors(); libxml_use_internal_errors($previous); }
    }

    private function text(SimpleXMLElement $element): string
    {
        $parts = $element->xpath('.//*[local-name()="t"]');
        return implode('', array_map(static fn ($part) => (string)$part, $parts ?: []));
    }
}
