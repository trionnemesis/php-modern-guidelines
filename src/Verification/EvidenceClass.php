<?php

declare(strict_types=1);

namespace ModernPhpGuidelines\Verification;

enum EvidenceClass: string
{
    case ExternalCompatibility = 'external_compatibility';
    case DeprecationAnnotation = 'deprecation_annotation';
    case ProposedTransformation = 'proposed_transformation';
}
