<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * D-104 — the official PSU letterhead the Monthly and Yearly reports print
 * (resources/views/reports/partials/letterhead.blade.php).
 *
 * Four outputs carry it: the College Admin's and the Director's Monthly
 * Report, and both Yearly Report PDFs. One assertion keeps the four tests
 * checking exactly the same thing.
 */
trait AssertsPsuLetterhead
{
    private function assertPsuLetterhead(string $html): void
    {
        // strip_tags: read what prints, not the alt text or the CSS.
        $text = strip_tags($html);

        $this->assertStringContainsString('Republic of the Philippines', $text);
        $this->assertStringContainsString('(former Don Honorio Ventura State University)', $text);

        // The university is named ONCE, by the letterhead: the report's own
        // header lost its title-case "Pampanga State University" line, and a
        // multi-year PDF does not repeat the letterhead on each year's page.
        $this->assertSame(1, substr_count($text, 'PAMPANGA STATE UNIVERSITY'));
        $this->assertStringNotContainsString('Pampanga State University', $text);

        // Above the report's own title block.
        $this->assertLessThan(strpos($html, '<div class="head">'), strpos($html, 'PAMPANGA STATE UNIVERSITY'));

        // The seal and Bagong Pilipinas, inlined so dompdf never fetches a URL.
        $this->assertSame(2, substr_count($html, 'src="data:image/png;base64,'));
    }
}
