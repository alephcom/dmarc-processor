<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('dmarc:prune')->dailyAt('03:17')->onOneServer()->withoutOverlapping();
Schedule::command('dmarc:check-dns --pending')->everyFifteenMinutes()->onOneServer()->withoutOverlapping();
Schedule::command('dmarc:check-dns')->dailyAt('04:43')->onOneServer()->withoutOverlapping();
Schedule::command('horizon:snapshot')->everyFiveMinutes();
Schedule::command('dmarc:update-ip-db')->weeklyOn(0, '05:21')->onOneServer();
Schedule::command('dmarc:update-ip-db --if-missing')->hourly()->onOneServer();
Schedule::command('dmarc:evaluate-alerts')->hourlyAt(7)->onOneServer()->withoutOverlapping();
Schedule::command('dmarc:send-digests')->dailyAt('07:13')->onOneServer()->withoutOverlapping();
