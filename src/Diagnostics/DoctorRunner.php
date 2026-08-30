<?php

declare(strict_types=1);

namespace ModernPhpGuidelines\Diagnostics;

use ModernPhpGuidelines\ApplicationFactory;
use ModernPhpGuidelines\Command\ExitCode;
use ModernPhpGuidelines\Exception\InputException;
use ModernPhpGuidelines\Exception\RuleDataException;
use ModernPhpGuidelines\Exception\UnresolvablePolicyException;
use ModernPhpGuidelines\Php\MinorRangeCalculator;
use ModernPhpGuidelines\Policy\ComposerInputReader;
use ModernPhpGuidelines\Policy\CoverageStatus;
use ModernPhpGuidelines\Policy\PolicyRequest;
use ModernPhpGuidelines\Policy\PolicyResolver;
use ModernPhpGuidelines\Rule\RuleLoader;
use ModernPhpGuidelines\Support\JsonSchemaValidator;
use ModernPhpGuidelines\Support\PackagePaths;

/**
 * Runs the nine bounded, read-only `doctor` checks of WORK-ORDER.md §5.3, in fixed order, and returns
 * a complete `DiagnosticReport`. Produces no output and holds no state between runs; every check's
 * detail-key set and summary template are pinned by §5.4/§5.4a and must be written exactly as given —
 * they are byte-compared by six goldens and, once the agent skill ships, by its worked example.
 *
 * Reads exactly the two target files `resolve` reads (`composer.json`, `composer.lock`), plus this
 * package's own bundled `schemas/` and rules directory (§2.5, ADR-006). It executes nothing, writes
 * nothing under `--project-root`, and reaches no network.
 */
final class DoctorRunner
{
    /** The nine checks of §5.3, in fixed output and exit-code-precedence order. */
    public const CHECK_IDS = [
        'cli.build',
        'project.root',
        'project.composer_json',
        'project.composer_lock',
        'project.php_declarations',
        'policy.resolution',
        'schemas.available',
        'rules.directory',
        'rules.load',
    ];

    /**
     * The fixed detail-key set per check id (§5.4), in order. Used to build a full-but-null detail
     * set for a `skipped` check, so a skipped check always carries its complete key set.
     *
     * @var array<string, list<string>>
     */
    private const DETAIL_KEYS = [
        'cli.build' => ['version', 'distribution'],
        'project.root' => ['path', 'is_directory'],
        'project.composer_json' => ['path', 'present', 'readable', 'json'],
        'project.composer_lock' => ['path', 'present', 'readable', 'json'],
        'project.php_declarations' => [
            'require_php', 'conflict_php', 'config_platform_php', 'platform_overrides_php',
            'input_warnings', 'error',
        ],
        'policy.resolution' => [
            'mode', 'allowed_minors', 'feature_ceiling', 'lifecycle_ceiling', 'platform_override',
            'observed_runtime', 'coverage', 'confidence', 'warnings', 'error',
        ],
        'schemas.available' => ['rule_schema', 'policy_schema'],
        'rules.directory' => ['source', 'file_count'],
        'rules.load' => ['loaded', 'error'],
    ];

    /** @param string|null $rulesDirOption the raw `--rules-dir` value, or null when it was not given */
    public function run(PolicyRequest $request, ?string $rulesDirOption): DiagnosticReport
    {
        $checks = [];

        $checks[] = $this->checkCliBuild();

        [$rootCheck, $normalizedRoot] = $this->checkProjectRoot($request->projectRoot);
        $checks[] = $rootCheck;
        $rootFailed = $rootCheck->status === CheckStatus::Fail;

        if ($rootFailed || $normalizedRoot === null) {
            $checks[] = $this->skipped('project.composer_json', 'project.root');
            $checks[] = $this->skipped('project.composer_lock', 'project.root');
            $composerJsonFailed = false;
            $composerLockFailed = false;
        } else {
            $composerJsonCheck = $this->checkComposerFile($normalizedRoot, 'composer.json', 'project.composer_json', CheckStatus::Warn);
            $checks[] = $composerJsonCheck;
            $composerJsonFailed = $composerJsonCheck->status === CheckStatus::Fail;

            $composerLockCheck = $this->checkComposerFile($normalizedRoot, 'composer.lock', 'project.composer_lock', CheckStatus::Ok);
            $checks[] = $composerLockCheck;
            $composerLockFailed = $composerLockCheck->status === CheckStatus::Fail;
        }

        $skipCauseForDeclarationsAndPolicy = $rootFailed
            ? 'project.root'
            : ($composerJsonFailed ? 'project.composer_json' : ($composerLockFailed ? 'project.composer_lock' : null));

        $projectInputs = null;
        $declarationsFailed = false;

        if ($skipCauseForDeclarationsAndPolicy !== null) {
            $checks[] = $this->skipped('project.php_declarations', $skipCauseForDeclarationsAndPolicy);
        } else {
            /** @var string $normalizedRoot */
            $reader = new ComposerInputReader(new MinorRangeCalculator());

            try {
                $projectInputs = $reader->read($normalizedRoot);
                $warnings = $projectInputs->warningCodes;
                $status = $warnings === [] ? CheckStatus::Ok : CheckStatus::Warn;
                $summary = $status === CheckStatus::Ok
                    ? 'declared PHP values read, no input warnings'
                    : sprintf('declared PHP values read, %d input warning(s)', count($warnings));

                $checks[] = $this->buildCheck('project.php_declarations', $status, $summary, [
                    'require_php' => $projectInputs->declaredConstraint,
                    'conflict_php' => $projectInputs->conflictConstraint,
                    'config_platform_php' => $projectInputs->platformOverride,
                    'platform_overrides_php' => $projectInputs->lockPlatformOverride,
                    'input_warnings' => $warnings === [] ? null : implode(', ', $warnings),
                    'error' => null,
                ]);
            } catch (InputException $e) {
                $declarationsFailed = true;
                $projectInputs = null;

                $checks[] = $this->buildCheck(
                    'project.php_declarations',
                    CheckStatus::Fail,
                    'declared PHP values could not be read',
                    [
                        'require_php' => null,
                        'conflict_php' => null,
                        'config_platform_php' => null,
                        'platform_overrides_php' => null,
                        'input_warnings' => null,
                        'error' => $e->getMessage(),
                    ],
                    ExitCode::INVALID_INPUT,
                );
            }
        }

        $skipCauseForPolicy = $skipCauseForDeclarationsAndPolicy
            ?? ($declarationsFailed ? 'project.php_declarations' : null);

        if ($skipCauseForPolicy !== null) {
            $checks[] = $this->skipped('policy.resolution', $skipCauseForPolicy);
        } else {
            /** @var \ModernPhpGuidelines\Policy\ProjectInputs $projectInputs */
            $resolver = new PolicyResolver(new ComposerInputReader(new MinorRangeCalculator()), new MinorRangeCalculator());

            try {
                $policy = $resolver->resolveFrom($request, $projectInputs);

                $warningCodes = array_map(
                    static fn(string $warning): string => (string) (strstr($warning, ':', true) ?: $warning),
                    $policy->warnings,
                );
                $coverageRendered = sprintf(
                    '%s (known %s-%s%s)',
                    $policy->coverage->status->value,
                    $policy->coverage->knownMin,
                    $policy->coverage->knownMax,
                    $policy->coverage->openUpperBound ? ', open upper bound' : '',
                );

                $isClean = $policy->coverage->status === CoverageStatus::Complete && $policy->warnings === [];
                $status = $isClean ? CheckStatus::Ok : CheckStatus::Warn;
                $summary = $isClean
                    ? sprintf(
                        '%s: feature %s, lifecycle %s, coverage %s, no warnings',
                        $policy->mode->value,
                        $policy->featureCeiling,
                        $policy->lifecycleCeiling,
                        $coverageRendered,
                    )
                    : sprintf(
                        '%s: feature %s, lifecycle %s, coverage %s, %d warning(s)',
                        $policy->mode->value,
                        $policy->featureCeiling,
                        $policy->lifecycleCeiling,
                        $coverageRendered,
                        count($warningCodes),
                    );

                $checks[] = $this->buildCheck('policy.resolution', $status, $summary, [
                    'mode' => $policy->mode->value,
                    'allowed_minors' => implode(', ', $policy->allowedMinors),
                    'feature_ceiling' => $policy->featureCeiling,
                    'lifecycle_ceiling' => $policy->lifecycleCeiling,
                    'platform_override' => $policy->platformOverride,
                    'observed_runtime' => $policy->observedRuntime,
                    'coverage' => $coverageRendered,
                    'confidence' => $policy->confidence->value,
                    'warnings' => $warningCodes === [] ? null : implode(', ', $warningCodes),
                    'error' => null,
                ]);
            } catch (InputException $e) {
                $checks[] = $this->buildCheck(
                    'policy.resolution',
                    CheckStatus::Fail,
                    'policy could not be resolved',
                    self::emptyPolicyResolutionDetails($e->getMessage()),
                    ExitCode::INVALID_INPUT,
                );
            } catch (UnresolvablePolicyException $e) {
                $checks[] = $this->buildCheck(
                    'policy.resolution',
                    CheckStatus::Fail,
                    'policy could not be resolved',
                    self::emptyPolicyResolutionDetails($e->getMessage()),
                    ExitCode::UNRESOLVABLE_POLICY,
                );
            }
        }

        $schemasCheck = $this->checkSchemasAvailable();
        $checks[] = $schemasCheck;
        $schemasFailed = $schemasCheck->status === CheckStatus::Fail;

        $rulesDir = $rulesDirOption ?? PackagePaths::rulesDirectory();
        $rulesSource = $rulesDirOption !== null ? 'custom' : 'bundled';

        $rulesDirCheck = $this->checkRulesDirectory($rulesDir, $rulesSource);
        $checks[] = $rulesDirCheck;
        $rulesDirFailed = $rulesDirCheck->status === CheckStatus::Fail;

        $skipCauseForRulesLoad = $schemasFailed ? 'schemas.available' : ($rulesDirFailed ? 'rules.directory' : null);

        if ($skipCauseForRulesLoad !== null) {
            $checks[] = $this->skipped('rules.load', $skipCauseForRulesLoad);
        } else {
            try {
                $loader = new RuleLoader(new JsonSchemaValidator(PackagePaths::ruleSchemaPath()));
                $registry = $loader->loadDirectory($rulesDir);
                $loaded = $registry->count();

                $checks[] = $this->buildCheck('rules.load', CheckStatus::Ok, sprintf('%d rule(s) loaded', $loaded), [
                    'loaded' => (string) $loaded,
                    'error' => null,
                ]);
            } catch (RuleDataException $e) {
                $checks[] = $this->buildCheck('rules.load', CheckStatus::Fail, 'rule data is invalid', [
                    'loaded' => null,
                    'error' => $e->getMessage(),
                ], ExitCode::RULE_DATA_INVALID);
            }
        }

        return new DiagnosticReport($checks);
    }

    private function checkCliBuild(): DiagnosticCheck
    {
        $version = ApplicationFactory::VERSION;
        // The only \Phar API call permitted anywhere in src/ (§2.5, D-level ADR-006 exception). It
        // performs no I/O on the analysed project: it only reports whether this process itself is
        // running from an archive. composer.json does not require ext-phar, so a source install on
        // a PHP built without it must fold to 'source' instead of throwing.
        $distribution = class_exists(\Phar::class, false) && \Phar::running(false) !== '' ? 'phar' : 'source';

        return $this->buildCheck(
            'cli.build',
            CheckStatus::Ok,
            sprintf('php-modern-guidelines %s, %s distribution', $version, $distribution),
            ['version' => $version, 'distribution' => $distribution],
        );
    }

    /** @return array{0: DiagnosticCheck, 1: string|null} [check, normalisedRoot-or-null] */
    private function checkProjectRoot(string $rawRoot): array
    {
        $real = is_dir($rawRoot) ? realpath($rawRoot) : false;

        if ($real === false) {
            $summary = 'not an existing directory: ' . $rawRoot;

            return [
                $this->buildCheck('project.root', CheckStatus::Fail, $summary, [
                    'path' => $rawRoot,
                    'is_directory' => 'no',
                ], ExitCode::INVALID_INPUT),
                null,
            ];
        }

        $normalized = rtrim($real, '/');
        if ($normalized === '') {
            // rtrim() on the filesystem root ("/") strips everything; restore the root itself.
            $normalized = '/';
        }

        return [
            $this->buildCheck('project.root', CheckStatus::Ok, $normalized, [
                'path' => $normalized,
                'is_directory' => 'yes',
            ]),
            $normalized,
        ];
    }

    private function checkComposerFile(string $projectRoot, string $filename, string $checkId, CheckStatus $absentStatus): DiagnosticCheck
    {
        $path = $projectRoot . '/' . $filename;

        if (!is_file($path)) {
            return $this->buildCheck($checkId, $absentStatus, $filename . ' absent', [
                'path' => $filename,
                'present' => 'no',
                'readable' => 'no',
                'json' => null,
            ]);
        }

        if (!is_readable($path)) {
            return $this->buildCheck($checkId, CheckStatus::Fail, $filename . ' present but unreadable', [
                'path' => $filename,
                'present' => 'yes',
                'readable' => 'no',
                'json' => null,
            ], ExitCode::INVALID_INPUT);
        }

        $raw = @file_get_contents($path);
        if ($raw === false) {
            return $this->buildCheck($checkId, CheckStatus::Fail, $filename . ' present but unreadable', [
                'path' => $filename,
                'present' => 'yes',
                'readable' => 'no',
                'json' => null,
            ], ExitCode::INVALID_INPUT);
        }

        try {
            /** @var mixed $decoded */
            $decoded = json_decode($raw, false, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $decoded = null;
        }

        if (!$decoded instanceof \stdClass) {
            return $this->buildCheck($checkId, CheckStatus::Fail, $filename . ' present but not a JSON object', [
                'path' => $filename,
                'present' => 'yes',
                'readable' => 'yes',
                'json' => 'invalid',
            ], ExitCode::INVALID_INPUT);
        }

        return $this->buildCheck($checkId, CheckStatus::Ok, $filename . ' present, valid JSON object', [
            'path' => $filename,
            'present' => 'yes',
            'readable' => 'yes',
            'json' => 'object',
        ]);
    }

    private function checkSchemasAvailable(): DiagnosticCheck
    {
        $ruleSchemaStatus = $this->schemaFileStatus(PackagePaths::ruleSchemaPath());
        $policySchemaStatus = $this->schemaFileStatus(PackagePaths::policySchemaPath());

        $details = ['rule_schema' => $ruleSchemaStatus, 'policy_schema' => $policySchemaStatus];

        if ($ruleSchemaStatus === 'ok' && $policySchemaStatus === 'ok') {
            return $this->buildCheck('schemas.available', CheckStatus::Ok, 'rule.schema.json ok, policy.schema.json ok', $details);
        }

        $summary = sprintf('rule.schema.json %s, policy.schema.json %s', $ruleSchemaStatus, $policySchemaStatus);

        return $this->buildCheck('schemas.available', CheckStatus::Fail, $summary, $details, ExitCode::RULE_DATA_INVALID);
    }

    /** @return 'ok'|'missing'|'invalid' */
    private function schemaFileStatus(string $path): string
    {
        if (!is_file($path) || !is_readable($path)) {
            return 'missing';
        }

        $raw = @file_get_contents($path);
        if ($raw === false) {
            return 'missing';
        }

        try {
            /** @var mixed $decoded */
            $decoded = json_decode($raw, false, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return 'invalid';
        }

        return $decoded instanceof \stdClass ? 'ok' : 'invalid';
    }

    private function checkRulesDirectory(string $rulesDir, string $source): DiagnosticCheck
    {
        $isAccessible = is_dir($rulesDir) && is_readable($rulesDir);
        $entries = $isAccessible ? @scandir($rulesDir) : false;

        if ($entries === false) {
            return $this->buildCheck('rules.directory', CheckStatus::Fail, sprintf('%s rules directory is missing or unreadable', $source), [
                'source' => $source,
                'file_count' => null,
            ], ExitCode::RULE_DATA_INVALID);
        }

        sort($entries, SORT_STRING);
        $fileCount = count(array_filter($entries, static fn(string $entry): bool => str_ends_with($entry, '.json')));

        if ($fileCount === 0) {
            return $this->buildCheck('rules.directory', CheckStatus::Warn, sprintf('%s rules directory, 0 rule file(s)', $source), [
                'source' => $source,
                'file_count' => '0',
            ]);
        }

        return $this->buildCheck('rules.directory', CheckStatus::Ok, sprintf('%s rules directory, %d rule file(s)', $source, $fileCount), [
            'source' => $source,
            'file_count' => (string) $fileCount,
        ]);
    }

    /** @return array<string, string|null> */
    private static function emptyPolicyResolutionDetails(string $error): array
    {
        return [
            'mode' => null,
            'allowed_minors' => null,
            'feature_ceiling' => null,
            'lifecycle_ceiling' => null,
            'platform_override' => null,
            'observed_runtime' => null,
            'coverage' => null,
            'confidence' => null,
            'warnings' => null,
            'error' => $error,
        ];
    }

    private function skipped(string $checkId, string $causeId): DiagnosticCheck
    {
        $keys = self::DETAIL_KEYS[$checkId];

        /** @var array<string, string|null> $details */
        $details = array_fill_keys($keys, null);

        return $this->buildCheck($checkId, CheckStatus::Skipped, sprintf('skipped after %s failed', $causeId), $details);
    }

    /** @param array<string, string|null> $details */
    private function buildCheck(string $id, CheckStatus $status, string $summary, array $details, int $exitCode = ExitCode::SUCCESS): DiagnosticCheck
    {
        $normalized = [];
        foreach ($details as $key => $value) {
            $normalized[$key] = self::normalizeDetailValue($value);
        }

        return new DiagnosticCheck($id, $status, $summary, $normalized, $exitCode);
    }

    /**
     * D39: no detail value may contain a newline. Rule-schema failure messages really are multi-line;
     * this collapses any run of whitespace containing a line break into a single space.
     */
    private static function normalizeDetailValue(?string $value): ?string
    {
        return $value === null ? null : trim((string) preg_replace('/\s*\R\s*/', ' ', $value));
    }
}
