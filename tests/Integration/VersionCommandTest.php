<?php

declare(strict_types=1);

namespace ModernPhpGuidelines\Tests\Integration;

use ModernPhpGuidelines\ApplicationFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\ApplicationTester;

final class VersionCommandTest extends TestCase
{
    public function testVersionCommandStartsAndReportsM0Version(): void
    {
        $application = ApplicationFactory::create();
        $application->setAutoExit(false);
        $tester = new ApplicationTester($application);

        self::assertSame(0, $tester->run(['command' => 'version']));
        self::assertSame('php-modern-guidelines ' . ApplicationFactory::VERSION . "\n", $tester->getDisplay());
    }

    public function testPackagedBinaryReportsTheVersion(): void
    {
        [$exitCode, $stdout, $stderr] = $this->runProcess([
            PHP_BINARY,
            $this->projectRoot() . '/bin/php-modern-guidelines',
            'version',
        ]);

        self::assertSame(0, $exitCode);
        self::assertSame('php-modern-guidelines ' . ApplicationFactory::VERSION . "\n", $stdout);
        self::assertSame('', $stderr);
    }

    public function testPackagedBinaryUsesComposerProvidedAutoloadPath(): void
    {
        $sentinel = tempnam(sys_get_temp_dir(), 'php-modern-guidelines-sentinel-');
        $proxy = tempnam(sys_get_temp_dir(), 'php-modern-guidelines-autoload-');
        if (!is_string($sentinel) || !is_string($proxy)) {
            self::fail('Could not create temporary proxy files.');
        }

        try {
            self::assertNotFalse(file_put_contents(
                $proxy,
                "<?php\nfile_put_contents(" . var_export($sentinel, true) . ", 'used');\nreturn require "
                . var_export($this->projectRoot() . '/vendor/autoload.php', true) . ";\n",
            ));

            $code = '$_composer_autoload_path = ' . var_export($proxy, true) . '; require '
                . var_export($this->projectRoot() . '/bin/php-modern-guidelines', true) . ';';
            [$exitCode, $stdout, $stderr] = $this->runProcess([PHP_BINARY, '-r', $code, 'version']);

            self::assertSame(0, $exitCode);
            self::assertSame('php-modern-guidelines ' . ApplicationFactory::VERSION . "\n", $stdout);
            self::assertSame('', $stderr);
            self::assertSame('used', file_get_contents($sentinel));
        } finally {
            @unlink($sentinel);
            @unlink($proxy);
        }
    }

    /** @param list<string> $command
     * @return array{int, string, string}
     */
    private function runProcess(array $command): array
    {
        /** @var array<int, array{0: string, 1: string}> $descriptorSpec */
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        /** @var array<int, resource> $pipes */
        $pipes = [];
        $process = proc_open($command, $descriptorSpec, $pipes, $this->projectRoot());
        if (!is_resource($process)) {
            self::fail('Could not start PHP subprocess.');
        }

        $stdin = $pipes[0] ?? null;
        $stdoutPipe = $pipes[1] ?? null;
        $stderrPipe = $pipes[2] ?? null;
        if (!is_resource($stdin) || !is_resource($stdoutPipe) || !is_resource($stderrPipe)) {
            self::fail('PHP subprocess pipes were not available.');
        }

        fclose($stdin);
        $stdout = stream_get_contents($stdoutPipe);
        fclose($stdoutPipe);
        $stderr = stream_get_contents($stderrPipe);
        fclose($stderrPipe);
        $exitCode = proc_close($process);

        if (!is_string($stdout) || !is_string($stderr)) {
            self::fail('Could not read PHP subprocess output.');
        }

        return [$exitCode, $stdout, $stderr];
    }

    private function projectRoot(): string
    {
        return dirname(__DIR__, 2);
    }
}
