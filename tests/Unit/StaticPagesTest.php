<?php

declare(strict_types=1);

namespace ModernPhpGuidelines\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class StaticPagesTest extends TestCase
{
    public function testStaticPageHasAccessibleLandmarksAnchorsAndLocalAssets(): void
    {
        $site = dirname(__DIR__, 2) . '/site';
        $html = file_get_contents($site . '/index.html');
        $css = file_get_contents($site . '/styles.css');
        if (!is_string($html) || !is_string($css)) {
            self::fail('Could not read static Pages assets.');
        }

        self::assertStringContainsString('<main id="main">', $html);
        self::assertStringContainsString('<nav ', $html);
        self::assertStringContainsString('aria-label="Primary navigation"', $html);
        self::assertStringContainsString('role="list"', $html);

        preg_match_all('/href="#([^"]+)"/', $html, $anchorMatches);
        foreach ($anchorMatches[1] ?? [] as $anchor) {
            if (!is_string($anchor)) {
                self::fail('Encountered a malformed internal anchor.');
            }

            self::assertStringContainsString('id="' . $anchor . '"', $html);
        }

        self::assertFileExists($site . '/styles.css');
        self::assertStringContainsString('href="styles.css"', $html);
        self::assertStringContainsString(':focus-visible', $css);
        self::assertStringContainsString('@media (max-width:', $css);
        self::assertStringContainsString('prefers-reduced-motion', $css);
    }
}
