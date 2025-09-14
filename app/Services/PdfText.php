<?php

namespace App\Services;

use Smalot\PdfParser\Parser as PdfParser;

class PdfText
{
    /**
     * Extracts plain text from PDF bytes.
     * Returns cleaned text (basic normalization; headings/footers removal is left to caller if needed).
     */
    public function extract(string $pdfBytes): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'pisrs_').'.pdf';
        file_put_contents($tmp, $pdfBytes);

        try {
            $parser = new PdfParser();
            $doc = $parser->parseFile($tmp);
            $text = $doc->getText() ?? '';
        } finally {
            @unlink($tmp);
        }

        // Basic cleanup: de-hyphenate line breaks and normalize spaces
        // (You can tune these for your PDFs as needed.)
        $text = preg_replace("/-\s*\n\s*/u", "", $text);      // join hyphenated words across line breaks
        $text = preg_replace("/\r\n|\r|\n/u", "\n", $text);   // normalize line endings
        $text = preg_replace("/[ \t]+/u", " ", $text);        // collapse spaces
        $text = preg_replace("/\n{2,}/u", "\n\n", $text);     // collapse multiple blank lines

        return trim($text);
    }
}

