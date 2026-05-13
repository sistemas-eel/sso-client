<?php

namespace PortalSistemas\SSOClient\Tests;

use PortalSistemas\SSOClient\Legacy\LegacyScaffoldInstaller;
use PHPUnit\Framework\TestCase;

class LegacyScaffoldInstallerTest extends TestCase
{
    /** @var string */
    private $tempDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDir = sys_get_temp_dir() . '/sso-client-scaffold-' . bin2hex(random_bytes(6));
        mkdir($this->tempDir, 0775, true);
    }

    protected function tearDown(): void
    {
        $this->deleteTree($this->tempDir);

        parent::tearDown();
    }

    public function test_it_generates_legacy_integration_files(): void
    {
        $written = (new LegacyScaffoldInstaller())->install($this->tempDir);

        $this->assertContains('sso/config.php', $written);
        $this->assertContains('sso/bootstrap.php', $written);
        $this->assertContains('sso/login.php', $written);
        $this->assertContains('sso/callback.php', $written);
        $this->assertContains('sso/logout.php', $written);
        $this->assertContains('sso/session-check.php', $written);
        $this->assertContains('api/sso/webhook-logout.php', $written);

        $this->assertFileExists($this->tempDir . '/sso/config.php');
        $this->assertFileExists($this->tempDir . '/api/sso/webhook-logout.php');
        $this->assertStringContainsString('SSO_CLIENT_ID', file_get_contents($this->tempDir . '/sso/config.php'));
        $this->assertStringContainsString('exchangeCodeForToken', file_get_contents($this->tempDir . '/sso/callback.php'));
        $this->assertStringContainsString('validateWebhookSignature', file_get_contents($this->tempDir . '/api/sso/webhook-logout.php'));
        $this->assertStringContainsString('HTTP_X_WEBHOOK_SIGNATURE', file_get_contents($this->tempDir . '/api/sso/webhook-logout.php'));
        $this->assertStringContainsString('HTTP_X_WEBHOOK_TIMESTAMP', file_get_contents($this->tempDir . '/api/sso/webhook-logout.php'));
        $this->assertStringContainsString('HTTP_X_WEBHOOK_NONCE', file_get_contents($this->tempDir . '/api/sso/webhook-logout.php'));
    }

    public function test_it_does_not_overwrite_existing_files_by_default(): void
    {
        $installer = new LegacyScaffoldInstaller();
        $installer->install($this->tempDir);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Arquivo já existe: sso/config.php');

        $installer->install($this->tempDir);
    }

    public function test_it_can_overwrite_existing_files_with_force(): void
    {
        $installer = new LegacyScaffoldInstaller();
        $installer->install($this->tempDir);

        file_put_contents($this->tempDir . '/sso/config.php', 'custom');

        $installer->install($this->tempDir, ['force' => true]);

        $this->assertStringContainsString('SSO_SERVER_URL', file_get_contents($this->tempDir . '/sso/config.php'));
    }

    private function deleteTree(string $path): void
    {
        if (!file_exists($path)) {
            return;
        }

        if (is_file($path) || is_link($path)) {
            unlink($path);
            return;
        }

        $items = scandir($path);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $this->deleteTree($path . DIRECTORY_SEPARATOR . $item);
        }

        rmdir($path);
    }
}
