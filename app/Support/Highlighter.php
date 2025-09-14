<?php

namespace App\Support;

final class Highlighter
{
    public static function html(string $html, string $needle): string
    {
        $needle = trim($needle);
        if ($needle === '') {
            return $html;
        }

        $dom = new \DOMDocument('1.0', 'UTF-8');
        // Load as fragment, preserve existing markup
        @$dom->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_HTML_NODEFDTD | LIBXML_HTML_NOIMPLIED);

        $xpath = new \DOMXPath($dom);
        foreach ($xpath->query('//text()') as $textNode) {
            $text = $textNode->nodeValue;
            $new = preg_replace('/('.preg_quote($needle, '/').')/iu', '<mark>$1</mark>', $text);
            if ($new !== $text) {
                $frag = $dom->createDocumentFragment();
                $frag->appendXML($new);
                $textNode->parentNode->replaceChild($frag, $textNode);
            }
        }
        return $dom->saveHTML();
    }
}

