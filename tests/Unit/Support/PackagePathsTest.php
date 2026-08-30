<?php

declare(strict_types=1);

namespace ModernPhpGuidelines\Tests\Unit\Support;

use ModernPhpGuidelines\Support\PackagePaths;
use PHPUnit\Framework\TestCase;

final class PackagePathsTest extends TestCase
{
    public function testRulesDirectoryExists(): void
    {
        self::assertDirectoryExists(PackagePaths::rulesDirectory());
    }

    public function testRuleSchemaPathExists(): void
    {
        self::assertFileExists(PackagePaths::ruleSchemaPath());
    }

    public function testPolicySchemaPathExists(): void
    {
        self::assertFileExists(PackagePaths::policySchemaPath());
    }

    public function testSchemasDirectoryExists(): void
    {
        self::assertDirectoryExists(PackagePaths::schemasDirectory());
    }
}
