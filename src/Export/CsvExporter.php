<?php

declare(strict_types=1);

namespace MailAI\Export;

/**
 * CsvExporter
 *
 * Streams an array of associative rows as CSV directly to output —
 * used by the client-facing analytics export and the super-admin
 * platform health export. Keeps column order stable by taking headers
 * from the first row rather than re-deriving them per row.
 */
final class CsvExporter
{
    /** Sends CSV headers + body and exits. Call with nothing else written to output yet. */
    public static function stream(string $filename, array $rows, ?array $headers = null): never
    {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');

        $out = fopen('php://output', 'w');

        if ($rows === []) {
            if ($headers !== null) {
                fputcsv($out, $headers);
            }
            fclose($out);
            exit;
        }

        fputcsv($out, $headers ?? array_keys($rows[0]));
        foreach ($rows as $row) {
            fputcsv($out, $row);
        }

        fclose($out);
        exit;
    }
}
