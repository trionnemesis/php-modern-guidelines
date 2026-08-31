<?php

declare(strict_types=1);

namespace ModernPhpGuidelines\Tests\Unit\Verification;

use ModernPhpGuidelines\Verification\AdapterOutcome;
use ModernPhpGuidelines\Verification\AdapterResult;
use ModernPhpGuidelines\Verification\EvidenceClass;
use ModernPhpGuidelines\Verification\InvocationPurpose;
use ModernPhpGuidelines\Verification\MappingStatus;
use ModernPhpGuidelines\Verification\Process\ProcessState;
use ModernPhpGuidelines\Verification\ProjectionStatus;
use ModernPhpGuidelines\Verification\VerificationFinding;
use ModernPhpGuidelines\Verification\VerificationInvocation;
use ModernPhpGuidelines\Verification\VerificationReason;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AdapterResultTest extends TestCase
{
    #[DataProvider('processFailureCases')]
    public function testCapabilityUnavailableCannotHideAProcessFailure(
        ProcessState $state,
        ?int $signal,
    ): void {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('normally exited');

        new AdapterResult(
            AdapterOutcome::Unavailable,
            ProjectionStatus::Supported,
            null,
            [$this->invocation($state, null, $signal, InvocationPurpose::ToolProbe, [])],
            [],
            new VerificationReason(
                VerificationReason::CAPABILITY_UNAVAILABLE,
                'The required adapter capability is unavailable.',
            ),
        );
    }

    /** @return iterable<string, array{ProcessState, int|null}> */
    public static function processFailureCases(): iterable
    {
        yield 'start failure' => [ProcessState::StartFailed, null];
        yield 'timeout' => [ProcessState::TimedOut, null];
        yield 'signal' => [ProcessState::Signaled, 15];
        yield 'output limit' => [ProcessState::OutputLimitExceeded, null];
    }

    public function testInvocationIdsAreUniqueByIdRatherThanWholeObject(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('ids must be unique');

        new AdapterResult(
            AdapterOutcome::Failed,
            ProjectionStatus::Supported,
            null,
            [
                $this->invocation(ProcessState::Exited, 2),
                new VerificationInvocation(
                    'verification-1',
                    ['8.2'],
                    'phpcs',
                    ['--different'],
                    ProcessState::Exited,
                    2,
                ),
            ],
            [],
            new VerificationReason(VerificationReason::PROCESS_EXIT_FAILED, 'The analyzer exited non-zero.'),
        );
    }

    #[DataProvider('invalidFindingReferenceCases')]
    public function testFindingsReferenceAttemptedAnalysisInvocations(
        InvocationPurpose $purpose,
        string $findingInvocationId,
        string $errorFragment,
    ): void {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage($errorFragment);

        new AdapterResult(
            AdapterOutcome::Completed,
            ProjectionStatus::Supported,
            '1.0.0',
            [$this->invocation(
                ProcessState::Exited,
                0,
                null,
                $purpose,
                $purpose === InvocationPurpose::ToolProbe ? [] : ['8.2'],
            )],
            [$this->finding($findingInvocationId)],
            null,
        );
    }

    /** @return iterable<string, array{InvocationPurpose, string, string}> */
    public static function invalidFindingReferenceCases(): iterable
    {
        yield 'unknown invocation' => [
            InvocationPurpose::Analysis,
            'verification-999',
            'references unknown invocation',
        ];
        yield 'tool probe' => [
            InvocationPurpose::ToolProbe,
            'verification-1',
            'cannot reference tool-probe invocation',
        ];
    }

    /** @param list<string> $policyMinors */
    private function invocation(
        ProcessState $state,
        ?int $exitCode,
        ?int $signal = null,
        InvocationPurpose $purpose = InvocationPurpose::Analysis,
        array $policyMinors = ['8.2'],
    ): VerificationInvocation {
        return new VerificationInvocation(
            'verification-1',
            $policyMinors,
            'phpcs',
            ['--report=json'],
            $state,
            $exitCode,
            $signal,
            $purpose,
        );
    }

    private function finding(string $invocationId): VerificationFinding
    {
        return new VerificationFinding(
            EvidenceClass::ExternalCompatibility,
            [$invocationId],
            'Example.Sniff',
            'ERROR',
            'error',
            'Example finding.',
            'src/Example.php',
            1,
            1,
            null,
            MappingStatus::Unmapped,
            [],
        );
    }
}
