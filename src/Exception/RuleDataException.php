<?php

declare(strict_types=1);

namespace ModernPhpGuidelines\Exception;

/**
 * Invalid rule file, duplicate id, filename/id mismatch, or missing rules directory. Maps to exit
 * code 5.
 */
final class RuleDataException extends \RuntimeException {}
