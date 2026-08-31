<?php

declare(strict_types=1);

namespace ModernPhpGuidelines\Verification;

/** Distinguishes tool/capability probes from policy-partitioned analysis work. */
enum InvocationPurpose: string
{
    case ToolProbe = 'tool_probe';
    case Analysis = 'analysis';
}
