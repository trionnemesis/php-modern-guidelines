<?php

declare(strict_types=1);

namespace ModernPhpGuidelines\Exception;

/**
 * Malformed, unreadable or unparseable input supplied by the caller or the analyzed project's
 * Composer files. Maps to exit code 2.
 */
final class InputException extends \RuntimeException {}
