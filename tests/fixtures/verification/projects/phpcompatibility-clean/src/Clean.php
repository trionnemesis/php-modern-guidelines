<?php

declare(strict_types=1);

final class Clean
{
    public function __construct(private readonly string $label) {}

    public function label(): string
    {
        return $this->label;
    }
}
