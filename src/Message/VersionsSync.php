<?php

namespace App\Message;

class VersionsSync
{
    private $repoId;

    public function __construct(int $repoId)
    {
        $this->repoId = $repoId;
    }

    public function getRepoId(): int
    {
        return $this->repoId;
    }
}
