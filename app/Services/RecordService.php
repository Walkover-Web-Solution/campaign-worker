<?php

namespace App\Services;

use App\Libs\MongoDBLib;
use App\Models\Campaign;
use App\Models\CampaignLog;
use App\Models\FlowAction;
use Carbon\Carbon;
use Exception;

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
            printLog("xxxxxxxxxxxxxxxx Campaign not found for campaign log id xxxxxxxxxxxxxxxxxx", 7);
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

        printLog("Now fetching for mongo data.", 2);
        $data = $this->mongo->collection('run_campaign_data')->find([
            'requestId' => $camplog['mongo_uid']
        ]);
        $md = json_decode(json_encode($data));
        printLog("Found mongo data.", 2);
        collect($md[0]->data->sendTo)->map(function ($item) use ($camplog, $flow, $camp) {
            $reqId = preg_replace('/\s+/', '',  Carbon::now()->timestamp) . '_' . md5(uniqid(rand(), true));
            $data = [
                'requestId' => $reqId,
                'data' => $item
            ];
            $mongoId = $this->mongo->collection('flow_action_data')->insertOne($data);
            $actionLogData = [
                "no_of_records" => 0,
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
                $delayValue = $delayTime->value;
            }
            if (!empty($actionLog)) {
                $input = new \stdClass();
                $input->action_log_id =  $actionLog->id;
                printLog("Now creating new job for next flow action.", 2);
                createNewJob($flow->channel_id, $input, $delayValue);
            }
        });
    }
}
