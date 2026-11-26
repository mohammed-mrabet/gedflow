<?php

namespace App\Support;

/**
 * Générateur minimal de PDF (une page, texte seul), utilisé pour les données de démonstration et les tests.
 * Évite une dépendance supplémentaire tout en produisant de vrais fichiers PDF lisibles et indexables.
 */
final class SimplePdf
{
    /** @param  array<int, string>  $lines */
    public static function make(string $title, array $lines): string
    {
        $content = 'BT /F1 18 Tf 56 780 Td ('.self::escape($title).") Tj ET\n";
        $content .= "0.15 0.25 0.45 RG 56 766 m 539 766 l S\n";
        $y = 740;
        foreach ($lines as $line) {
            foreach (self::wrap($line, 92) as $chunk) {
                $content .= "BT /F1 11 Tf 56 {$y} Td (".self::escape($chunk).") Tj ET\n";
                $y -= 16;
            }
            $y -= 4;
        }

        $objects = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>',
            '<< /Length '.strlen($content)." >>\nstream\n{$content}endstream",
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>',
        ];

        $pdf = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objects as $i => $object) {
            $offsets[] = strlen($pdf);
            $pdf .= ($i + 1)." 0 obj\n{$object}\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= "xref\n0 ".(count($objects) + 1)."\n0000000000 65535 f \n";
        foreach ($offsets as $offset) {
            $pdf .= str_pad((string) $offset, 10, '0', STR_PAD_LEFT)." 00000 n \n";
        }

        return $pdf."trailer\n<< /Size ".(count($objects) + 1)." /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF\n";
    }

    private static function escape(string $text): string
    {
        $text = iconv('UTF-8', 'Windows-1252//TRANSLIT', $text) ?: $text;

        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $text);
    }

    /** @return array<int, string> */
    private static function wrap(string $line, int $width): array
    {
        return $line === '' ? [''] : explode("\n", wordwrap($line, $width, "\n", true));
    }
}
