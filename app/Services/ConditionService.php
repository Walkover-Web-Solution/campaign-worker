<?php

namespace App\Services;

use App\Jobs\RabbitMQJob;
use App\Libs\MongoDBLib;
use App\Libs\RabbitMQLib;
use App\Models\ActionLog;
use App\Models\Campaign;
use App\Models\FlowAction;
use Carbon\Carbon;
use Exception;
use libphonenumber\PhoneNumberUtil;

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
        $md = json_decode(json_encode($data));

        collect($flow->module_data)->map(function ($nextFlowId, $filter) use ($md, $action_log, $campaign) {

            if (!empty($nextFlowId)) {
                $filter = substr($filter, 3);
                $newFlowAction = FlowAction::where('id', $nextFlowId)->first();

                /**
                 * function that will make a filter according to the coountry
                 */
                $data = $this->generateFilterData($md[0]->data, $filter);
                $reqId = preg_replace('/\s+/', '',  Carbon::now()->timestamp) . '_' . md5(uniqid(rand(), true));
                $filterdata_mongoID = [
                    'requestId' => $reqId,
                    'data' => $data
                ];

                $mongoId = $this->mongo->collection('flow_action_data')->insertOne($filterdata_mongoID);
                $actionLogData = [
                    "campaign_id" => $action_log->campaign_id,
                    "no_of_records" => 0,
                    "response" => "",
                    "status" => "pending",
                    "report_status" => "pending",
                    "ref_id" => "",
                    "flow_action_id" => $newFlowAction->id,
                    "mongo_id" => $reqId,
                    'campaign_log_id' => $action_log->campaign_log_id
                ];
                printLog('Creating new action as per channel id ', 1);
                $actionLog = $campaign->actionLogs()->create($actionLogData);
                $delayTime = collect($newFlowAction->configurations)->firstWhere('name', 'delay');
                if (empty($delayTime)) {
                    $delayValue = 0;
                } else {
                    $delayValue = $delayTime->value;
                }
                if (!empty($actionLog)) {
                    $input = new \stdClass();
                    $input->action_log_id =  $actionLog->id;
                    $this->createNewJob($newFlowAction->channel_id, $input, $delayValue);
                }
            }
        });
    }

    public function generateFilterData($mongoData, $filter)
    {
        $data = collect($mongoData)->map(function ($item, $key) use ($filter) {
            if ($key != 'variables') {
                $filtered = collect($item)->reject(function ($value, $key) use ($filter) {

                    $valid = validPhoneNumber($value->mobiles, $filter);
                    if (!$valid)
                        return $value;
                });
                return $filtered;
            }
        });

        $data = json_decode($data);

        $data->variables = $mongoData->variables;
        $data = collect($data)->filter();
        return json_decode($data);
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
