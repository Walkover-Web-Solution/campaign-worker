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

        // Return to dequeue this job if Campaign is paused
        if ($campaignLog->is_paused) {
            return;
        }

        if ($campaignLog->status == 'Stopped') {
            $action_log->status = 'Stopped';
            $action_log->save();
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
        $obj->mongoData = $md[0]->data->sendTo;

        // loop path
        $path = $md[0]->node_count;
        $obj->loop = false;

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
                    collect($obj->data)->map(function ($data, $grpId) use ($obj, $action_log, $campaignLog, $campaign, $path, $flow) {
                        $nextFlowAction = FlowAction::where('id', $obj->grpFlowActionMap[$grpId])->first();

                        // Check for loop and increase count
                        $path_key = $flow->id . '.' . $grpId;
                        $loop_detected = false;
                        if (empty($path->$path_key)) {
                            $path->$path_key = $nextFlowAction->id + 0.1;
                        } else {
                            // In case next_flow_action gets changed, so reinitialize it
                            if ((int)$path->$path_key != $nextFlowAction->id) {
                                $path->$path_key = $nextFlowAction->id + 0.1;
                            } else {
                                $path->$path_key += 0.1;
                                $count = ($path->$path_key * 10) % 10;
                                if ($count > 2) {
                                    $loop_detected = true;
                                }
                            }
                        }

                        $reqId = preg_replace('/\s+/', '',  Carbon::now()->timestamp) . '_' . md5(uniqid(rand(), true));
                        $filterdata_mongoID = [
                            'requestId' => $reqId,
                            'data' => ["sendTo" => $data],
                            'node_count' => $path
                        ];
                        $this->mongo->collection('flow_action_data')->insertOne($filterdata_mongoID);

                        // create actionLog for nextFlowAction
                        $actionLogData = [
                            "campaign_id" => $action_log->campaign_id,
                            "no_of_records" => $action_log->no_of_records,
                            "response" => $loop_detected ? ['errors' => 'Loop detected!'] : "",
                            "status" => $loop_detected ? "Stopped" : "pending",
                            "report_status" => "pending",
                            "flow_action_id" => $nextFlowAction->id,
                            "ref_id" => "",
                            "mongo_id" => $reqId,
                            'campaign_log_id' => $action_log->campaign_log_id
                        ];
                        printLog('Creating new action as per channel id ', 1);
                        $actionLog = $nextFlowAction->actionLog()->create($actionLogData);

                        // If loop detected next_action_log's status will be Stopped
                        if ($actionLog->status == 'Stopped') {
                            $campaignLog->status = 'Stopped';
                            $campaignLog->save();

                            $slack = new SlackService();
                            $error = array(
                                'campaign_id' => $campaign->id,
                                'Campaign_log_id' => $campaignLog->id,
                                'Action_log_id' => $actionLog->id
                            );
                            $obj->loop = true;
                            $slack->sendLoopErrorToSlack((object)$error);
                            return;
                        }
                        if ($obj->loop) {
                            return;
                        }

                        // adding delay time with job
                        $delayTime = collect($nextFlowAction->configurations)->firstWhere('name', 'delay');
                        $delayValue = getSeconds($delayTime->unit, $delayTime->value);
                        if (!empty($actionLog)) {
                            $input = new \stdClass();
                            $input->action_log_id =  $actionLog->id;
                            if ($campaignLog->is_paused)
                                $delayValue = 0;
                            $queue = getQueue($nextFlowAction->channel_id);
                            createNewJob($input, $queue, $delayValue);
                        }
                    });

                    $action_log->response = [
                        "data" => "",
                        "errors" => [],
                        "status" => "Consumed",
                        "hasError" => false
                    ];
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
