<?php

namespace Tests\Feature\Services\Fixtures;

use App\Models\User;

class TestNotificationWithActor extends TestNotification
{
    public function __construct(array $data, protected User $actor)
    {
        parent::__construct($data);
    }

    public function getActor(): User
    {
        return $this->actor;
    }
}
