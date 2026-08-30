<?php

declare(strict_types=1);

namespace ModernPhpGuidelines\Rule;

enum ApplicabilityStatus: string
{
    case Applicable = 'applicable';
    case ForbiddenAboveFeatureCeiling = 'forbidden_above_feature_ceiling';
    case NotInRange = 'not_in_range';
    case DeprecatedInRange = 'deprecated_in_range';
    case DeprecatedAcrossRange = 'deprecated_across_range';
    case RemovedInRange = 'removed_in_range';
    case RemovedAcrossRange = 'removed_across_range';
}
