<?php

namespace Packstub\Agents\Approvals;

readonly class Proposal
{
    /**
     * @param  array<string, mixed>  $arguments
     * @param  array<string, array{from: mixed, to: mixed}>  $proposedChanges
     */
    public function __construct(
        public array $arguments,
        public array $proposedChanges,
        public string $summary,
    ) {}

    public function isEmpty(): bool
    {
        return $this->proposedChanges === [];
    }
}
