<?php

namespace App\Services;

use App\Jobs\RabbitMQJob;
use App\Libs\MongoDBLib;
use App\Libs\RabbitMQLib;
use App\Models\ActionLog;
use App\Models\Campaign;
use App\Models\Filter;
use App\Models\FlowAction;
use Carbon\Carbon;
use Exception;
use Illuminate\Support\Facades\Cache;
use libphonenumber\PhoneNumberUtil;
use stdClass;

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
        $campaign = Campaign::find($action_log->campaign_id);
        printLog("Till now we found Campaign. And now about to find flow action.", 2);
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
                    // sort module_data according to groups with groupId as key
                    $obj->data = new \stdClass();
                    $obj->moduleData = $flow->module_data;

                    // get filtered data according to groups and countries
                    $obj = getFilteredData($obj);

                    // initialize body for remaining keys that has no contact in mongoData
                    $obj = getFilteredDatawithRemainingGroups($obj);
                    dd($obj->data);

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
                            $this->createNewJob($nextFlowAction->channel_id, $input, $delayTime->value);
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

    public function createNewJob($channel_id, $input, $delayTime)
    {
        //selecting the queue name as per the flow channel id
        switch ($channel_id) {
            case 1:
                $queue = 'run_email_campaigns';
                break;
            case 2:
                $queue = 'run_sms_campaigns';
                break;
            case 3:
                $queue = 'run_whastapp_campaigns';
                break;
            case 4:
                $queue = 'run_voice_campaigns';
                break;
            case 5:
                $queue = 'condition_queue';
                break;
            case 6:
                $queue = 'run_rcs_campaigns';
                break;
        }
        printLog("About to create job for " . $queue, 1);
        if (empty($this->rabbitmq)) {
            $this->rabbitmq = new RabbitMQLib;
        }
        // $this->rabbitmq->enqueue($queue, $input);
        RabbitMQJob::dispatch($input)->delay(Carbon::now()->addSeconds($delayTime))->onQueue($queue); //dispatching the job
        printLog("'================= Created Job in " . $queue . " =============", 1);
    }
}
