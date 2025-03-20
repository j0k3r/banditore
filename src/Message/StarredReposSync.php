<?php

namespace App\Message;

class StarredReposSync
{
    public function __construct(private readonly int $userId)
    {
    }

    public function getUserId(): int
    {
        return $this->userId;
    }
}
