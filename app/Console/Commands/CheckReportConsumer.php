<?php

namespace App\Console\Commands;

use App\Jobs\RabbitMQJob;
use App\Libs\ReportLib;
use App\Models\ActionLog;
use App\Models\Campaign;
use App\Models\FlowAction;
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
        $actionlogs = ActionLog::where('status', 'pending')->get();
        $actionlogs->map(function ($actionLog) {
            $queue = 'getReports';
            $input = ["action_log_id" => $actionLog->id];
            RabbitMQJob::dispatch($input)->onQueue($queue);
            // create token
            // $campaign = Campaign::where('id', $actionLog->campaign_id)->first();
            // $input['company'] = $campaign->company()->first();
            // config(['msg91.jwt_token' => createJWTToken($input)]);

            // $lib = new ReportLib();

            // $channelId = FlowAction::where('id', $actionLog->flow_action_id)->pluck('channel_id')->first();

            // switch ($channelId) {
            //     case 1: {
            //             $data = ["unique_id" => $actionLog->ref_id];
            //             $lib->getEmailReport($data);
            //         }
            //         break;
            //     case 2: {
            //         }
            //         break;
            //     case 3: {
            //         }
            //         break;
            //     case 4: {
            //         }
            //         break;
            //     case 5: {
            //         }
            //         break;
            // }
        });
    }
}
