<?php

namespace App\Enums;

/**
 * Formats d'export (section 59).
 *
 * Les deux ecrivent EN FLUX, et c'est le critere qui a decide de la
 * bibliotheque : `openspout` plutot que PhpSpreadsheet, qui garde toute la
 * feuille en memoire. La section 72 annonce des millions de billets ; un export
 * ne doit pas dependre de la taille de ce qu'il exporte.
 */
enum ExportFormat: string
{
    case Csv = 'csv';
    case Xlsx = 'xlsx';

    public function label(): string
    {
        return match ($this) {
            self::Csv => 'CSV',
            self::Xlsx => 'Excel',
        };
    }

    public function extension(): string
    {
        return $this->value;
    }

    public function mimeType(): string
    {
        return match ($this) {
            self::Csv => 'text/csv; charset=UTF-8',
            self::Xlsx => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $case) => $case->value, self::cases());
    }
}
