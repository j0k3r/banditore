<?php

namespace App\Message;

class VersionsSync
{
    public function __construct(private readonly int $repoId)
    {
    }

    public function getRepoId(): int
    {
        return $this->repoId;
    }
}
