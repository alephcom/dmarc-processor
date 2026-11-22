<?php

namespace App\Console\Commands;

use App\Alerts\AlertEvaluator;
use Illuminate\Console\Command;

class EvaluateAlerts extends Command
{
    protected $signature = 'dmarc:evaluate-alerts';

    protected $description = 'Run the periodic alert checks (new senders, missing reports, ingest failures, expiry)';

    public function handle(AlertEvaluator $evaluator): int
    {
        $evaluator->hourly();

        return self::SUCCESS;
    }
}
