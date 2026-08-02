<?php
namespace App\Backtest;

class TickerUniverse
{
    /**
     * LSE-style tickers as they appear in the Freetrade shares list: a
     * representative shortlist of blue-chip FTSE100 names plus major UK/global
     * index trackers (the full list is ~90 blue chips + a dozen near-duplicate
     * trackers, too many to fetch/read as one table).
     *
     * @return string[]
     */
    public static function blueChipsAndIndexTrackers(): array
    {
        return [
            // Note: CRH excluded — it moved its primary listing to the NYSE in
            // 2023 and left the LSE, so "CRH.L" no longer resolves on Yahoo.
            'VOD', 'BP.', 'HSBA', 'GSK', 'ULVR', 'AZN', 'RIO', 'BARC', 'LLOY', 'SHEL',
            'STAN', 'NWG', 'PRU', 'LGEN', 'TSCO', 'NXT', 'DGE', 'BATS', 'RKT', 'RR.',
            'BA.', 'NG.', 'SSE', 'UU.', 'HLN', 'BT.A', 'WPP', 'LAND', 'GLEN',
            'VUSA', 'VWRL', 'VUKE', 'VMID',
        ];
    }

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
