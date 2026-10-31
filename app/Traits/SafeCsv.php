<?php

namespace App\Traits;

trait SafeCsv
{
    protected function writeCsvRow($stream, array $cells): void
    {
        fputcsv($stream, array_map(function ($cell): string {
            $value = (string) ($cell ?? '');

            return preg_match('/^[\x00-\x20]*[=+\-@]/', $value) ? "'".$value : $value;
        }, $cells));
    }
}
