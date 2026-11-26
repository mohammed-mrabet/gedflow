<?php

namespace App\Services;

use Smalot\PdfParser\Parser;
use Throwable;
use ZipArchive;

/** Extrait le texte des fichiers déposés pour la recherche plein texte (PDF, Word, texte brut). */
class TextExtractor
{
    public const MAX_LENGTH = 200_000;

    public function extract(string $path, string $mimeType): ?string
    {
        try {
            $text = match (true) {
                $mimeType === 'application/pdf' => (new Parser)->parseFile($path)->getText(),
                $mimeType === 'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => $this->docx($path),
                str_starts_with($mimeType, 'text/') => file_get_contents($path),
                default => null, // images : pas de reconnaissance de caractères (OCR) dans cette version
            };
        } catch (Throwable) {
            return null; // fichier protégé ou corrompu : le document reste consultable, seulement non indexé
        }

        if ($text === null) {
            return null;
        }
        $text = trim(preg_replace('/\s+/u', ' ', mb_convert_encoding($text, 'UTF-8', 'UTF-8')));

        return $text === '' ? null : mb_substr($text, 0, self::MAX_LENGTH);
    }

    /** Un fichier .docx est une archive ZIP : le texte est dans word/document.xml. */
    private function docx(string $path): ?string
    {
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            return null;
        }
        $xml = $zip->getFromName('word/document.xml');
        $zip->close();
        if ($xml === false) {
            return null;
        }
        $xml = str_replace(['</w:p>', '<w:tab/>', '<w:br/>'], ["\n", ' ', "\n"], $xml);

        return html_entity_decode(strip_tags($xml), ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
}
