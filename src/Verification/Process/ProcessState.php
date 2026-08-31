<?php

declare(strict_types=1);

namespace ModernPhpGuidelines\Verification\Process;

/** Internal subprocess lifecycle; analyzer exit-code semantics belong to each adapter. */
enum ProcessState: string
{
    case Exited = 'exited';
    case Signaled = 'signaled';
    case TimedOut = 'timed_out';
    case OutputLimitExceeded = 'output_limit_exceeded';
    case StartFailed = 'start_failed';
}
