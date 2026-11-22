<?php

namespace App\Http\Controllers;

use App\Team\Impersonation;
use Illuminate\Http\RedirectResponse;

class ImpersonationController
{
    public function leave(Impersonation $impersonation): RedirectResponse
    {
        return $impersonation->stop() ? redirect('/admin/users') : redirect('/app');
    }
}
