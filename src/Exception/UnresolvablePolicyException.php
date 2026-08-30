<?php

declare(strict_types=1);

namespace ModernPhpGuidelines\Exception;

/**
 * The effective PHP constraint allows no PHP minor known to this tool. Maps to exit code 4.
 */
final class UnresolvablePolicyException extends \RuntimeException {}
