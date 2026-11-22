<?php

namespace App\Alerts\Delivery;

use App\Filament\Admin\Resources\Alerts\AlertResource as AdminAlertResource;
use App\Filament\App\Resources\Alerts\AlertResource;
use App\Models\Alert;

final class AlertLinks
{
    public static function url(Alert $alert): string
    {
        return $alert->tenant
            ? AlertResource::getUrl('view', ['record' => $alert], panel: 'app', tenant: $alert->tenant)
            : AdminAlertResource::getUrl('view', ['record' => $alert], panel: 'admin');
    }
}
