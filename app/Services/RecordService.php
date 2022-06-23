<?php

namespace App\Services;

use App\Libs\MongoDBLib;
use App\Models\Campaign;
use App\Models\CampaignLog;
use App\Models\FlowAction;
use Carbon\Carbon;
use Exception;
use Illuminate\Support\Arr;

/**
 * Class RecordService
 * @package App\Services
 */
class RecordService
{
    protected $mongo;
    public function __construct()
    {
    }

    /**
     * This function will fetch start point (flowaction) of any campaign using campaign log id
     * Then from bulk data breakdown data as per flowaction.
     * Then generate action log for that flowaction and create job for same
     */
    public function executeFlowAction($campLogId)
    {
        printLog("We are here to find flow action and to execute it", 2);
        $camplog = CampaignLog::where('id', $campLogId)->first();
        $camp = Campaign::where('id', $camplog->campaign_id)->first();
        // In case if campaign deleted by user
        if (empty($camp)) {
            printLog("============= Campaign not found for campaign log id xxxxxxxxxxxxxxxxxx", 7);
            throw new Exception("No campaign found.");
        }

        printLog("----- We found Campaign here -----", 2);
        printLog("Now moving forward to get flowactions for campaign", 2);
        $allFlow = FlowAction::select('channel_id')->where('campaign_id', $camp->id)->get();
        $flow = FlowAction::where('id', $camp->module_data['op_start'])->first();
        // In case of flowaction deleted by user
        if (empty($flow)) {
            printLog("No flow actions found.", 5);
            throw new Exception("No flowaction found.");
        }

        if (empty($this->mongo)) {
            $this->mongo = new MongoDBLib;
        }

        printLog("Now fetching for mongo data for.", 2, ["requestId" => $camplog['mongo_uid']]);
        $data = $this->mongo->collection('run_campaign_data')->find([
            'requestId' => $camplog['mongo_uid']
        ]);
        $md = json_decode(json_encode($data));
        printLog("Found mongo data.", 2);
        printLog("DATA FROM MONGO IS : ", 1, (array)$md);
        $reqId = preg_replace('/\s+/', '',  Carbon::now()->timestamp) . '_' . md5(uniqid(rand(), true));
        $data = [
            'requestId' => $reqId,
            'data' => $md[0]->data
        ];
        $mongoId = $this->mongo->collection('flow_action_data')->insertOne($data);
        printLog("data stiored in mongo and now creating action log", 2);
        $actionLogData = [
            "no_of_records" => $camplog->no_of_contacts,
            "status" => "pending",
            "report_status" => "pending",
            "ref_id" => "",
            "response" => null,
            "flow_action_id" => $flow->id,
            "mongo_id" => $reqId,
            'campaign_log_id' => $camplog->id
        ];
        $actionLog = $camp->actionLogs()->create($actionLogData);
        $delayTime = collect($flow->configurations)->firstWhere('name', 'delay');
        if (empty($delayTime)) {
            $delayValue = 0;
        } else {
            $delayValue = getSeconds($delayTime->unit, $delayTime->value);
        }
        if (!empty($actionLog)) {
            $input = new \stdClass();
            $input->action_log_id =  $actionLog->id;
            if ($camplog->is_paused)
                $delayValue = 0;
            printLog("Now creating new job for next flow action.", 2);
            $queue = getQueue($flow->channel_id);
            createNewJob($input, $queue, $delayValue);
        }
    }
}
