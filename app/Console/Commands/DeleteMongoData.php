<?php

namespace App\Console\Commands;

use App\Libs\MongoDBLib;
use App\Models\CampaignLog;
use Carbon\Carbon;
use Illuminate\Console\Command;

class DeleteMongoData extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'delete:mongodata';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Delete the all mongodata of completed campaign a week ago';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    protected $mongo;
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
        $completeCampaignLog = CampaignLog::where('status', 'complete')->where('updated_at', '<', Carbon::now()->subDays(7)->format('Y-m-d H:i:s'))->get();
        if (empty($this->mongo)) {
            $this->mongo = new MongoDBLib;
        }
        $completeCampaignLog->map(function ($campaignLog) {

            $filter = array("requestId" => $campaignLog->mongo_uid);
            $this->mongo->collection('run_campaign_data')->delete($filter);

            $actionLogs = $campaignLog->actionLogs()->get();
            if (empty($actionLogs)) {
                printLog("No actionLogs found for campaignLog id : " . $campaignLog->id);
                return;
            }

            $actionLogs->map(function ($actionLog) {
                $filter = array("requestId" => $actionLog->mongo_id);
                $this->mongo->collection('flow_action_data')->delete($filter);
            });
        });
    }
}
