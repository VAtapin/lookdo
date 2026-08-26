<?php

namespace Tests\Unit;

use App\Support\LegalContentSanitizer;
use PHPUnit\Framework\TestCase;

class LegalContentSanitizerTest extends TestCase
{
    public function test_it_removes_public_notices_and_dispute_resolution_content(): void
    {
        $html = <<<'HTML'
<p class="legal-notice"><strong>Hinweis:</strong> Diese Angaben müssen ergänzt werden.</p>
<h2>Hinweis zur Streitbeilegung</h2>
<p>Wir nehmen nicht an einem Verfahren vor einer Verbraucherschlichtungsstelle teil. <a href="https://ec.europa.eu/consumers/odr/">OS-Plattform</a></p>
<p>{{dispute_statement}}</p>
<h2>Haftung für Links</h2><p>Dieser alte Text wird entfernt.</p>
<h2>Kontakt</h2><p>Dieser Inhalt bleibt sichtbar.</p>
HTML;

        $clean = LegalContentSanitizer::clean($html);

        $this->assertStringNotContainsString('legal-notice', $clean);
        $this->assertStringNotContainsString('Streitbeilegung', $clean);
        $this->assertStringNotContainsString('Verbraucherschlichtungsstelle', $clean);
        $this->assertStringNotContainsString('ec.europa.eu', $clean);
        $this->assertStringNotContainsString('dispute_statement', $clean);
        $this->assertStringNotContainsString('Haftung für Links', $clean);
        $this->assertStringContainsString('<h2>Kontakt</h2>', $clean);
        $this->assertStringContainsString('Dieser Inhalt bleibt sichtbar.', $clean);
    }
}
