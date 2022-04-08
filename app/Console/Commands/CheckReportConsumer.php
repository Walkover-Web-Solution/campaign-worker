<?php

namespace App\Console\Commands;

use App\Jobs\RabbitMQJob;
use App\Libs\ReportLib;
use App\Models\ActionLog;
use App\Models\Campaign;
use App\Models\FlowAction;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class CheckReportConsumer extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'check:report';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $actionlogs = ActionLog::where('status', 'pending')->where('created_at', '<', Carbon::parse('-6 hours'))->get();
        $actionlogs->map(function ($actionLog) {
            $queue = 'getReports';
            $input = ["action_log_id" => $actionLog->id];
            RabbitMQJob::dispatch($input)->onQueue($queue);
        });
    }
}
