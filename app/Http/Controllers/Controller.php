<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

abstract class Controller
{
    // Laravel 12's skeleton omits this; controllers here rely on
    // $this->authorize() to apply the record-level policies.
    use AuthorizesRequests;
}
