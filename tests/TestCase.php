<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    public function actingAs(\Illuminate\Contracts\Auth\Authenticatable $user, $guard = null)
    {
        $this->withSession(['auth_version' => $user->fresh()->session_version]);

        return parent::actingAs($user, $guard);
    }
}
