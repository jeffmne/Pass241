<?php

namespace App\Services\Export;

use App\Enums\ExportFormat;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\CSV\Options as CsvOptions;
use OpenSpout\Writer\CSV\Writer as CsvWriter;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Ecrit un export et le renvoie au navigateur (section 59).
 *
 * EN FLUX, TOUJOURS. Le fichier n'est jamais assemble en memoire ni pose sur
 * disque : chaque ligne part vers le navigateur des qu'elle est produite. Un
 * export de cent mille billets coute donc la meme memoire qu'un export de dix.
 *
 * RIEN N'EST CONSERVE. Aucun fichier d'export ne s'accumule sur le serveur, il
 * n'y a donc ni purge a organiser, ni duree de conservation a decider, ni
 * fichier oublie contenant les coordonnees de mille participants (meme
 * raisonnement que la decision D-021 pour les billets).
 */
class ExportWriter
{
    public function stream(Exportable $export, ExportFormat $format): StreamedResponse
    {
        $filename = $export->filename().'.'.$format->extension();

        return new StreamedResponse(
            fn () => $this->write($export, $format),
            200,
            [
                'Content-Type' => $format->mimeType(),
                'Content-Disposition' => 'attachment; filename="'.$filename.'"',
                // Un proxy qui mettrait un export en cache le servirait au
                // visiteur suivant : ces fichiers contiennent des donnees
                // personnelles (section 52).
                'Cache-Control' => 'no-store, no-cache, must-revalidate',
                'X-Accel-Buffering' => 'no',
            ],
        );
    }

    private function write(Exportable $export, ExportFormat $format): void
    {
        $writer = $format === ExportFormat::Csv
            ? new CsvWriter($this->csvOptions())
            : new XlsxWriter;

        $writer->openToFile('php://output');

        $writer->addRow(Row::fromValues($export->headings()));

        foreach ($export->rows() as $row) {
            $writer->addRow(Row::fromValues($row));
        }

        $writer->close();
    }

    /**
     * Options du CSV.
     *
     * Le BOM UTF-8 est conserve : sans lui, Excel sous Windows affiche « Nzeng »
     * en caracteres accentues casses. L'organisateur ouvre ce fichier dans
     * Excel, pas dans un editeur de texte — c'est son usage qui decide, pas la
     * purete du format.
     *
     * Le point-virgule comme separateur, pour la meme raison : un Excel
     * configure en francais lit une virgule comme un separateur decimal et
     * empile toute la ligne dans une seule colonne.
     *
     * Les options sont en lecture seule chez openspout : elles se passent au
     * constructeur, jamais par affectation.
     */
    private function csvOptions(): CsvOptions
    {
        return new CsvOptions(
            FIELD_DELIMITER: ';',
            SHOULD_ADD_BOM: true,
        );
    }
}
