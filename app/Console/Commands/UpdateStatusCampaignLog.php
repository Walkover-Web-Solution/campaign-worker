<?php

namespace App\Console\Commands;

use App\Models\CampaignLog;
use Carbon\Carbon;
use Illuminate\Console\Command;

class UpdateStatusCampaignLog extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'updateStatus:campaignLog';

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
        printLog("CampaignLogs fetching with status Running");
        $campaignLogs = CampaignLog::where('status', 'Running')->where('created_at', '<', Carbon::parse('-1 hours'))->get();

        printLog("CampaignLogs found");
        $campaignLogs->map(function ($campaignLog) {
            updateCampaignLogStatus($campaignLog);
        });
        printLog("No more campaignLogs");
    }
}
