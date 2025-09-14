<?php
namespace App\Support;

class TextHash {
    public static function sha(string $text): string {
        $t = preg_replace('/\s+/u', ' ', trim($text));
        return hash('sha256', $t);
    }
}
