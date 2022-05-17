<?php

namespace App\Services;

use App\Libs\MongoDBLib;
use App\Models\ActionLog;
use App\Models\Campaign;
use App\Models\Filter;
use App\Models\FlowAction;
use Carbon\Carbon;
use Exception;

/**
 * Class ConditionService
 * @package App\Services
 */
class ConditionService
{
    public function handleCondition($actionLogId)
    {
        printLog("----- Lets process action log ----------", 2);
        $action_log = ActionLog::where('id', $actionLogId)->first();
        $campaign = $action_log->campaign;
        $campaignLog = $action_log->campaignLog;
        printLog("Till now we found Campaign. And now about to find flow action.", 2);

        // Create job for paused CampaignLog in paused queue
        if ($campaignLog->is_paused) {
            createPauseJob($actionLogId);
            return;
        }

        $flow = FlowAction::where('campaign_id', $action_log->campaign_id)->where('id', $action_log->flow_action_id)->first();
        if (empty($flow)) {
            printLog("No flow actions found.", 5);
            throw new Exception("No flowaction found.");
        }

        if (empty($this->mongo)) {
            $this->mongo = new MongoDBLib;
        }
        /**
         * Geting the data from mongo
         */
        $data = $this->mongo->collection('flow_action_data')->find([
            'requestId' => $action_log->mongo_id
        ]);

        $obj = new \stdClass();
        // get mongoData
        $md = json_decode(json_encode($data));
        $obj->mongoData = $md[0]->data;

        $conditionId = collect($flow->configurations)->firstWhere('name', 'Condition')->value;
        $countries = 1;
        switch ($conditionId) {
            case $countries: {
                    $obj->data = new \stdClass();
                    $obj->moduleData = $flow->module_data;

                    // get filtered data according to groups and countries
                    $obj = getFilteredData($obj);

                    // initialize body for remaining keys that has no contact in mongoData
                    $obj = getFilteredDatawithRemainingGroups($obj);

                    // create jobs for next actionLogs according to groups
                    collect($obj->data)->map(function ($data, $grpId) use ($obj, $action_log) {
                        $nextFlowAction = FlowAction::where('id', $obj->grpFlowActionMap[$grpId])->first();

                        $reqId = preg_replace('/\s+/', '',  Carbon::now()->timestamp) . '_' . md5(uniqid(rand(), true));
                        $filterdata_mongoID = [
                            'requestId' => $reqId,
                            'data' => $data
                        ];
                        $this->mongo->collection('flow_action_data')->insertOne($filterdata_mongoID);

                        // create actionLog for nextFlowAction
                        $actionLogData = [
                            "campaign_id" => $action_log->campaign_id,
                            "no_of_records" => 0,
                            "response" => "",
                            "status" => "pending",
                            "report_status" => "pending",
                            "flow_action_id" => $nextFlowAction->id,
                            "ref_id" => "",
                            "mongo_id" => $reqId,
                            'campaign_log_id' => $action_log->campaign_log_id
                        ];
                        printLog('Creating new action as per channel id ', 1);
                        $actionLog = $nextFlowAction->actionLog()->create($actionLogData);

                        // adding delay time with job
                        $delayTime = collect($nextFlowAction->configurations)->firstWhere('name', 'delay');
                        if (!empty($actionLog)) {
                            $input = new \stdClass();
                            $input->action_log_id =  $actionLog->id;
                            createNewJob($nextFlowAction->channel_id, $input, $delayTime->value);
                        }
                    });

                    $action_log->status = "Consumed";
                    $action_log->save();
                }
                break;
            default: {
                    //
                }
                break;
        }
    }
}
