<?php

namespace App\Console\Commands;

use App\Models\CampaignLog;
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
        $campaignLogs = CampaignLog::where('status', 'Running')->get();

        $campaignLogs->map(function ($campaignLog) {
            updateCampaignLogStatus($campaignLog);
        });
    }
}
