<?php
namespace App\Utils;

class DocNumber {
    public static function generate(string $prefix, int $sequence): string {
        $year = date('Y');
        $padded = str_pad((string)$sequence, 4, '0', STR_PAD_LEFT);
        return "{$prefix}-{$year}-{$padded}";
    }
}
