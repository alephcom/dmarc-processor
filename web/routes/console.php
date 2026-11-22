<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('dmarc:prune')->dailyAt('03:17')->onOneServer()->withoutOverlapping();
Schedule::command('horizon:snapshot')->everyFiveMinutes();
