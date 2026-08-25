<?php

namespace App\Services;

use DOMDocument;
use DOMElement;
use DOMXPath;
use RuntimeException;
use ZipArchive;

class TacoSpreadsheetReader
{
    /** @return list<array{reference:string,description:string,group:string,calories:float,protein:float,fat:float,carbs:float,fiber:float}> */
    public function foods(string $path): array
    {
        if (! is_file($path)) throw new RuntimeException("Planilha TACO não encontrada: {$path}");
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) throw new RuntimeException('Não foi possível abrir a planilha TACO.');
        try {
            $strings = $this->sharedStrings($zip);
            $xml = $zip->getFromName('xl/worksheets/sheet1.xml');
            if ($xml === false) throw new RuntimeException('A aba nutricional principal da TACO não foi encontrada.');
            $document = new DOMDocument();
            $document->loadXML($xml);
            $xpath = new DOMXPath($document);
            $xpath->registerNamespace('x', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
            $group = '';
            $foods = [];
            foreach ($xpath->query('//x:sheetData/x:row') as $row) {
                $cells = $this->cells($xpath, $row, $strings);
                $reference = $cells['A'] ?? '';
                $description = trim((string) ($cells['B'] ?? ''));
                if (preg_match('/^\d+$/', $reference) && $description !== '') {
                    $foods[] = ['reference' => $reference, 'description' => $description, 'group' => $group, 'calories' => $this->number($cells['D'] ?? null), 'protein' => $this->number($cells['F'] ?? null), 'fat' => $this->number($cells['G'] ?? null), 'carbs' => $this->number($cells['I'] ?? null), 'fiber' => $this->number($cells['J'] ?? null)];
                } elseif ($reference !== '' && ! preg_match('/^\d+$/', $reference) && $description === '' && ! in_array($reference, ['Número do', 'Alimento'], true)) {
                    $group = trim($reference);
                }
            }

            if (count($foods) !== 597) throw new RuntimeException('A planilha TACO não passou na validação de integridade: esperados 597 alimentos.');

            return $foods;
        } finally { $zip->close(); }
    }

    /** @return array<string,string> */
    private function cells(DOMXPath $xpath, DOMElement $row, array $strings): array
    {
        $cells = [];
        foreach ($xpath->query('./x:c', $row) as $cell) {
            $reference = $cell->getAttribute('r');
            $column = preg_replace('/\d+/', '', $reference);
            $value = $xpath->evaluate('string(x:v)', $cell);
            if ($cell->getAttribute('t') === 's' && $value !== '') $value = $strings[(int) $value] ?? '';
            $cells[$column] = $value;
        }
        return $cells;
    }

    /** @return list<string> */
    private function sharedStrings(ZipArchive $zip): array
    {
        $xml = $zip->getFromName('xl/sharedStrings.xml');
        if ($xml === false) return [];
        $document = new DOMDocument();
        $document->loadXML($xml);
        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('x', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        return array_map(fn ($node) => $node->textContent, iterator_to_array($xpath->query('//x:si')));
    }

    private function number(?string $value): float
    {
        $value = trim((string) $value);
        return $value === '' || in_array(strtolower($value), ['na', 'tr'], true) ? 0.0 : (float) str_replace(',', '.', $value);
    }
}
