<?php

namespace App\Support;

/**
 * Petit moteur PDF (une page A4, polices standard Helvetica) : texte, traits, rectangles et QR code.
 * Suffisant pour un certificat de validation sobre, sans dépendre d'un moteur HTML vers PDF.
 */
final class PdfDocument
{
    public const WIDTH = 595;

    public const HEIGHT = 842;

    private string $content = '';

    public function text(float $x, float $y, string $text, float $size = 10, bool $bold = false, array $rgb = [0.09, 0.11, 0.16]): self
    {
        $font = $bold ? 'F2' : 'F1';
        $this->content .= sprintf("%.3F %.3F %.3F rg BT /%s %.1F Tf %.2F %.2F Td (%s) Tj ET\n", ...[...$rgb, $font, $size, $x, $y, self::escape($text)]);

        return $this;
    }

    /** Texte sur plusieurs lignes ; retourne l'ordonnée sous le dernier ligne écrite. */
    public function paragraph(float $x, float $y, string $text, float $width, float $size = 10, bool $bold = false, array $rgb = [0.09, 0.11, 0.16], float $leading = 1.35): float
    {
        $maxChars = max(10, (int) floor($width / ($size * 0.5)));
        foreach (explode("\n", wordwrap($text, $maxChars, "\n", true)) as $line) {
            $this->text($x, $y, $line, $size, $bold, $rgb);
            $y -= $size * $leading;
        }

        return $y;
    }

    public function line(float $x1, float $y1, float $x2, float $y2, float $width = 0.6, array $rgb = [0.82, 0.84, 0.88]): self
    {
        $this->content .= sprintf("%.3F %.3F %.3F RG %.2F w %.2F %.2F m %.2F %.2F l S\n", ...[...$rgb, $width, $x1, $y1, $x2, $y2]);

        return $this;
    }

    public function rect(float $x, float $y, float $w, float $h, array $rgb): self
    {
        $this->content .= sprintf("%.3F %.3F %.3F rg %.2F %.2F %.2F %.2F re f\n", ...[...$rgb, $x, $y, $w, $h]);

        return $this;
    }

    /**
     * Dessine une matrice de QR code (true = module noir), coin inférieur gauche en (x, y).
     *
     * @param  array<int, array<int, bool>>  $matrix
     */
    public function qr(float $x, float $y, array $matrix, float $size): self
    {
        $n = count($matrix);
        $module = $size / $n;
        $this->content .= "0 0 0 rg\n";
        foreach ($matrix as $row => $cells) {
            foreach ($cells as $col => $dark) {
                if ($dark) {
                    $this->content .= sprintf("%.3F %.3F %.3F %.3F re\n", $x + $col * $module, $y + ($n - 1 - $row) * $module, $module, $module);
                }
            }
        }
        $this->content .= "f\n";

        return $this;
    }

    public function render(string $title = 'Document'): string
    {
        $objects = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 '.self::WIDTH.' '.self::HEIGHT.'] /Contents 4 0 R /Resources << /Font << /F1 5 0 R /F2 6 0 R >> >> >>',
            '<< /Length '.strlen($this->content)." >>\nstream\n{$this->content}endstream",
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>',
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>',
            '<< /Title ('.self::escape($title).') /Producer (GedFlow) >>',
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

        return $pdf.'trailer'."\n<< /Size ".(count($objects) + 1)." /Root 1 0 R /Info 7 0 R >>\nstartxref\n{$xref}\n%%EOF\n";
    }

    private static function escape(string $text): string
    {
        $text = iconv('UTF-8', 'Windows-1252//TRANSLIT', $text) ?: $text;

        return str_replace(['\\', '(', ')', "\r", "\n"], ['\\\\', '\\(', '\\)', '', ' '], $text);
    }
}
