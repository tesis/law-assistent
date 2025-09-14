<?php

namespace App\Support;

final class TextSanitizer
{
    /** Convert HTML-ish content to readable plain text with line breaks. */
    public static function toPlain(string $html): string
    {
        // 1) remove script/style
        $h = preg_replace('~<(script|style)[^>]*>.*?</\1>~is', ' ', (string) $html);
        // 2) normalize common block breaks to \n
        $h = preg_replace('~\s*(</p>|<br\s*/?>|</li>|</div>|</section>)\s*~i', "\n", $h);
        // 3) drop all tags
        $h = strip_tags($h);
        // 4) decode entities, collapse whitespace, replace NBSP
        $h = html_entity_decode($h, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $h = str_replace("\xC2\xA0", ' ', $h); // NBSP
        $h = preg_replace('~[ \t]+~u', ' ', $h);
        $h = preg_replace('~\n{3,}~u', "\n\n", $h);
        return trim($h);
    }

    public static function toSafeHtml(string $html): string
    {
        $h = (string)$html;
        // remove script/style
        $h = preg_replace('~<(script|style)[^>]*>.*?</\1>~is', ' ', $h);
        // decode entities (if DB has &lt;p&gt;)
        $h = html_entity_decode($h, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        // keep only a whitelist of tags
        $allowed = '<p><br><ul><ol><li><strong><em><b><i><u><h1><h2><h3><h4>';
        $h = strip_tags($h, $allowed);
        // normalize whitespace
        $h = str_replace("\xC2\xA0", ' ', $h);
        $h = preg_replace('~[ \t]+~u', ' ', $h);
        return trim($h);
    }

}

