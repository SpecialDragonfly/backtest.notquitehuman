<?php
namespace App\Backtest;

class TickerUniverse
{
    /**
     * Convert an LSE-style ticker to its Yahoo Finance symbol: a trailing "."
     * is dropped ("BP." -> "BP.L"), a mid-ticker "." (share class) becomes
     * "-" ("BT.A" -> "BT-A.L").
     */
    public static function toYahooSymbol(string $lseTicker): string
    {
        $t = rtrim($lseTicker, '.');
        $t = str_replace('.', '-', $t);
        return $t . '.L';
    }
}
