<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('dmarc:prune')->dailyAt('03:17')->onOneServer()->withoutOverlapping();
Schedule::command('dmarc:check-dns --pending')->everyFifteenMinutes()->onOneServer()->withoutOverlapping();
Schedule::command('dmarc:check-dns')->dailyAt('04:43')->onOneServer()->withoutOverlapping();
Schedule::command('horizon:snapshot')->everyFiveMinutes();
