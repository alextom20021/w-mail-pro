<?php

declare(strict_types=1);

namespace MailAI\Export;

/**
 * SimplePdfWriter
 *
 * A minimal, dependency-free PDF generator for tabular reports (title +
 * a simple grid of text cells), used by the analytics/platform-health
 * "Export PDF" buttons. Deliberately hand-rolled rather than pulling in
 * a PDF library (FPDF/TCPDF/dompdf) — this project's composer install
 * can't be verified from this sandbox (no outbound network to
 * Packagist), so adding an unverified new dependency to composer.json
 * risked repeating the exact kind of "build breaks on Render, nobody
 * can tell why" problem this project already hit once with
 * firebase/php-jwt. Raw PDF syntax (objects, xref table, content
 * streams with Tj/Td text-positioning operators) has no dependencies to
 * break. Helvetica only, single fixed page size (US Letter), left-
 * aligned text, no images/wrapping — enough for a clean tabular export,
 * not a general-purpose PDF library.
 */
final class SimplePdfWriter
{
    private const PAGE_WIDTH = 612;  // US Letter, points
    private const PAGE_HEIGHT = 792;
    private const MARGIN = 40;
    private const ROW_HEIGHT = 16;
    private const ROWS_PER_PAGE = 44;

    /**
     * @param string[] $headers
     * @param array<int, array<int, string>> $rows
     */
    public static function render(string $title, array $headers, array $rows): string
    {
        $pagesContent = self::buildPageStreams($title, $headers, $rows);

        $objects = [];
        $objects[] = "<< /Type /Catalog /Pages 2 0 R >>"; // 1: catalog

        $kidRefs = [];
        $pageObjIndexStart = 4; // objects 3.. reserved for font, then pages/content pairs from 4
        // We'll assign: 2 = Pages, 3 = Font, then for each page: N = Page, N+1 = Contents
        $nextObjNum = 4;
        $pageObjNums = [];
        foreach ($pagesContent as $i => $content) {
            $pageObjNums[$i] = $nextObjNum;
            $nextObjNum += 2; // page + its content stream
        }

        $pagesKids = implode(' ', array_map(fn($n) => "{$n} 0 R", $pageObjNums));
        $objects[1] = "<< /Type /Pages /Kids [{$pagesKids}] /Count " . count($pagesContent) . " >>"; // object 2
        $objects[2] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>"; // object 3

        foreach ($pagesContent as $i => $content) {
            $pageNum = $pageObjNums[$i];
            $contentNum = $pageNum + 1;
            $objects[$pageNum - 1] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 " . self::PAGE_WIDTH . ' ' . self::PAGE_HEIGHT . "] "
                . "/Resources << /Font << /F1 3 0 R >> >> /Contents {$contentNum} 0 R >>";
            $stream = self::escapeStream($content);
            $objects[$contentNum - 1] = "<< /Length " . strlen($stream) . " >>\nstream\n{$stream}\nendstream";
        }

        ksort($objects);
        return self::assemblePdf($objects);
    }

    /** @return string[] one content stream (page body) per page */
    private static function buildPageStreams(string $title, array $headers, array $rows): array
    {
        $colCount = max(1, count($headers));
        $colWidth = (int) ((self::PAGE_WIDTH - 2 * self::MARGIN) / $colCount);

        $chunks = array_chunk($rows, self::ROWS_PER_PAGE);
        if ($chunks === []) {
            $chunks = [[]];
        }

        $pages = [];
        foreach ($chunks as $pageIndex => $pageRows) {
            $y = self::PAGE_HEIGHT - self::MARGIN;
            $lines = [];

            if ($pageIndex === 0) {
                $lines[] = self::textOp(self::MARGIN, $y, 14, self::pdfEscape($title));
                $y -= 24;
            }

            $lines[] = self::rowOp(self::MARGIN, $y, $colWidth, $headers, bold: true);
            $y -= self::ROW_HEIGHT;

            foreach ($pageRows as $row) {
                $lines[] = self::rowOp(self::MARGIN, $y, $colWidth, $row, bold: false);
                $y -= self::ROW_HEIGHT;
            }

            $pages[] = implode("\n", $lines);
        }

        return $pages;
    }

    private static function rowOp(int $x, int $y, int $colWidth, array $cells, bool $bold): string
    {
        $ops = [];
        $size = $bold ? 10 : 9;
        foreach (array_values($cells) as $i => $cell) {
            $cellText = mb_substr((string) $cell, 0, (int) ($colWidth / 5)); // crude width-based truncation
            $ops[] = self::textOp($x + $i * $colWidth, $y, $size, self::pdfEscape($cellText));
        }

        return implode("\n", $ops);
    }

    private static function textOp(int $x, int $y, int $size, string $escapedText): string
    {
        return "BT /F1 {$size} Tf {$x} {$y} Td ({$escapedText}) Tj ET";
    }

    private static function pdfEscape(string $text): string
    {
        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $text);
    }

    private static function escapeStream(string $content): string
    {
        return $content; // content streams here are plain ASCII text operators, nothing to escape at the stream level
    }

    private static function assemblePdf(array $objects): string
    {
        $out = "%PDF-1.4\n";
        $offsets = [0];

        foreach ($objects as $i => $body) {
            $offsets[] = strlen($out);
            $num = $i + 1;
            $out .= "{$num} 0 obj\n{$body}\nendobj\n";
        }

        $xrefStart = strlen($out);
        $count = count($objects) + 1;
        $out .= "xref\n0 {$count}\n0000000000 65535 f \n";
        for ($i = 1; $i < $count; $i++) {
            $out .= sprintf("%010d 00000 n \n", $offsets[$i]);
        }

        $out .= "trailer\n<< /Size {$count} /Root 1 0 R >>\nstartxref\n{$xrefStart}\n%%EOF";

        return $out;
    }
}
