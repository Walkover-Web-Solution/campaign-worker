<?php

namespace App\Services;

use App\Libs\MongoDBLib;
use App\Models\ActionLog;
use App\Models\Campaign;
use App\Models\ChannelType;
use App\Models\FlowAction;
use Carbon\Carbon;

/**
 * Class ChannelService
 * @package App\Services
 */
class ChannelService
{
    protected $mongo;
    protected $rabbitmq;
    public function __construct()
    {
        //
    }

    public function sendData($actionLogId)
    {
        printLog("----- Lets process action log ----------", 2);
        $action_log = ActionLog::where('id', $actionLogId)->first();
        /**
         * generating the token
         */
        $campaignLog = $action_log->campaignLog;
        $campaign = $action_log->campaign;

        // Return to dequeue this job if Campaign is paused
        if ($campaignLog->is_paused) {
            return;
        }
        printLog("Checking for the campaign log Stopped or not " . $campaignLog->status);
        if ($campaignLog->status == 'Stopped') {
            printLog("Status changing to Stopped");
            $action_log->status = 'Stopped';
            // In case of CampaignLog Stopped due to loop. update response of action_log also.
            if ($campaignLog->actionLogs()->whereJsonContains('response', ["errors" => "Loop detected!"])->count() > 0) {
                $action_log->response = ['errors' => "Loop detected!"];
            }
            $action_log->save();
            printLog("Status changed to stopped.");
            return;
        }

        printLog("Till now we found Campaign, and created JWT. And now about to find flow action.", 2);
        $flow = FlowAction::where('campaign_id', $action_log->campaign_id)->where('id', $action_log->flow_action_id)->first();

        $input['company'] = $campaign->company;
        $input['user'] = $campaign->user;
        $input['ip'] = $campaignLog->ip;
        $input['need_validation'] = $flow['channel_id'] == 2 ? false : (bool) $campaignLog->need_validation;
        config(['msg91.jwt_token' => createJWTToken($input)]);

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

        // Seperate attachments from mongo data
        $attachments = empty($md[0]->data->attachments) ? [] : $md[0]->data->attachments;

        // Seperate reply_to from mongo data
        $reply_to = empty($md[0]->data->reply_to) ? [] : $md[0]->data->reply_to;

        /**
         * generating the request body data according to flow channel id
         */

        printLog("converting the contact body data to required context.", 2);
        $convertedData = convertBody($md[0]->data->sendTo, $campaign, $flow);
        // printLog("BEFORE GET REQUEST BODY", 1, $convertedData);

        if (!$convertedData) {
            printLog("Initializing the request body data to empty array in case of Invalid json (Whatsapp).", 2);
            $reqBody = [
                "count" => $action_log->no_of_records
            ];
            $reqBody = (object)$reqBody;
        } else {
            printLog("generating the request body data according to flow channel id.", 2);
            $reqBody = $this->getRequestBody($flow, $convertedData, $action_log, $attachments, $reply_to);
        }

        //get unique data only and count duplicate
        $duplicateCount = 0;
        switch ($flow['channel_id']) {
            case  1: {
                    //original count
                    $duplicateCount = count($reqBody->data->recipients);
                    //filter duplicate
                    $reqBody->data->recipients = collect($reqBody->data->recipients)->unique()->toArray();
                    //new count after removing duplicate
                    $count = count($reqBody->data->recipients);
                    //calculating duplicate
                    $duplicateCount -= $count;
                }
                break;
            case 2: {
                    $reqBody->data->recipients = collect($reqBody->data->recipients)->unique()->toArray();
                    //original count
                    $duplicateCount = $reqBody->count;
                    //new count after removing duplicate
                    $reqBody->count = count($reqBody->data->recipients);
                    //calculating duplicate
                    $duplicateCount -= $reqBody->count;
                }
                break;
        }
        /**
         * Geting the libary object according to the flow channel id to send the data to the microservice
         */
        $lib = setLibrary($flow['channel_id']);
        $res = new \stdClass();
        if ($reqBody->count == 0) {
            $res->hasError = true;
            $res->message = "No Data Found";
        } else {
            if (!$convertedData) {
                $res->hasError = true;
                $res->message = "Invalid Json!";
            } else {
                printLog("Now sending data to microservice", 1);
                $res = $lib->send($reqBody->data);
                //adding duplicate count to response
                if (!empty($res)) {
                    $res->duplicate = $duplicateCount;
                }
            }
        }
        /**
         * updating the response comes from the microservice into the ref_id of current flow action
         */
        printLog('We have successfully send data to: ' . $flow['channel_id'] . ' channel', 1, empty($res) ? (array)['message' => 'NULL RESPONSE'] : (array)$res);

        $next_action_log = $this->updateActionLogResponse($flow, $action_log, $res, $reqBody->count, $md);

        if (!empty($next_action_log)) {
            // If loop detected next_action_log's status will be Stopped
            if ($next_action_log->status == 'Stopped') {
                $campaignLog->status = 'Stopped';
                $campaignLog->save();

                $slack = new SlackService();
                $error = array(
                    'Action_log_id' => $next_action_log->id
                );
                $slack->sendLoopErrorToSlack((object)$error);
                return;
            }
        }

        // in case of rcs for webhook and email for queue
        if ($flow->channel_id == 5 || $flow->channel_id == 1) {
            printLog("Job Consumed");
            return;
        }

        printLog('Got new action log and its id is ' . empty($next_action_log) ? "Action Log NOT FOUND" : $next_action_log->id, 1);
        if (!empty($next_action_log)) {

            $nextFlowAction = FlowAction::where('id', $next_action_log->flow_action_id)->first();
            $delayTime = collect($nextFlowAction->configurations)->firstWhere('name', 'delay');
            if (empty($delayTime)) {
                $delayValue = 0;
            } else {
                $delayValue = getSeconds($delayTime->unit, $delayTime->value);
            }

            printLog("Now creating new job for action log.", 1);
            $input = new \stdClass();
            $input->action_log_id =  $next_action_log->id;
            $queue = getQueue($nextFlowAction->channel_id);
            if ($campaignLog->is_paused)
                $delayValue = 0;
            createNewJob($input, $queue, $delayValue);
        } else {
            // Call cron to set campaignLog Complete
            updateCampaignLogStatus($campaignLog);
        }

        return;
    }


    public function getRequestBody($flow, $convertedData, $action_log, $attachments, $reply_to)
    {
        /**
         * extracting the all the variables from the mongo data
         */
        $obj = new \stdClass();
        $obj->count = 0;

        // get template of this flowAction
        $temp = $flow->template;

        $data = [];
        $mongo_data = $convertedData;

        $service = setService($flow['channel_id']);
        switch ($flow['channel_id']) {
            case 1: //For Email
                $data = $service->getRequestBody($flow, $obj, $mongo_data, $temp->variables, $attachments, $reply_to);
                printLog("GET REQUEST BODY", 1, $data);
                break;
            case 2: //For SMS
                $data = $service->getRequestBody($flow, $obj, $mongo_data);

                $obj->count = count($mongo_data['mobiles']);
                break;
            case 3:
                $data = $service->getRequestBody($flow, $mongo_data);
                // Count total mobiles
                collect($mongo_data['mobiles'])->pluck('mobiles')->each(function ($item) use ($obj) {
                    if (is_string($item)) {
                        $obj->count++;
                    } else {
                        $obj->count += count($item);
                    }
                });
                break;
            case 5: //for rcs
                $data = $service->getRequestBody($flow, $action_log, $mongo_data, $temp->variables, "template");
                $obj->count = count($mongo_data['mobiles']);
                break;
        }

        $obj->data = json_decode(collect($data));
        return $obj;
    }

    public function updateActionLogResponse($flow, $action_log, $res, $reqDataCount, $md)
    {
        $val = "";
        $status = "Success";
        if ($flow->channel_id == 1 && !empty($res) && !$res->hasError) {
            $val = $res->data->unique_id;
        } else if ($flow->channel_id == 2 && !empty($res) && !$res->hasError) {
            $val = $res->data;
        } else if ($flow->channel_id == 3 && !empty($res) && !$res->hasError) {
            $val = $res->request_id;
        } else if ($flow->channel_id == 5 && !empty($res) && !$res->hasError) {
            // for now generating random ref_id
            $val = preg_replace('/\s+/', '_',  Carbon::now()->timestamp) . '_' . md5(uniqid(rand(), true));
        } else {
            $status = "Failed";
            printLog("Microservice api failed.");
        }

        $action = ActionLog::where('id', $action_log->id)->first();
        $action->update(['status' => $status, "no_of_records" => $reqDataCount, 'ref_id' => $val, 'response' => $res]);

        if ($status != 'Failed') {
            // in case of rcs for webhook and email for queue
            if ($flow->channel_id == 5 || $flow->channel_id == 1)
                return;
        }

        printLog("We are here to create new action log as per module data", 1);

        $events = ChannelType::where('id', $flow->channel_id)->first()->events()->pluck('name')->toArray(); //generating an array of all the events belong to flow channel id
        $campaign = Campaign::find($action_log->campaign_id);

        $next_flow_id = null;
        if ($status == 'Success') {
            $event_id = 1;
            $next_flow_id = isset($flow->module_data->op_success) ? $flow->module_data->op_success : null;
        } else {
            $event_id = 2;
            $next_flow_id = isset($flow->module_data->op_failed) ? $flow->module_data->op_failed : null;
        }

        // Check for loop and increase count
        $path = $md[0]->node_count;
        $path_key = $flow->id . '.' . $event_id;
        $loop_detected = false;
        if (empty($path->$path_key)) {
            $path->$path_key = $next_flow_id + 0.1;
        } else {
            // In case next_flow_action gets changed, so reinitialize it
            if ((int)$path->$path_key != $next_flow_id) {
                $path->$path_key = $next_flow_id + 0.1;
            } else {
                $path->$path_key += 0.1;
                $count = ($path->$path_key * 10) % 10;
                if ($count > 2) {
                    $loop_detected = true;
                }
            }
        }

        // Update path in mongo
        $this->mongo->collection('flow_action_data')
            ->update(["requestId" => $action_log->mongo_id],  ["node_count" => $path]);

        printLog('Get status from microservice ' . $status, 1);
        printLog("Events are ", 1, $events);
        if (in_array($status, $events) && !empty($next_flow_id)) {
            printLog('Next flow id is ' . $next_flow_id, 1);
            $flow = FlowAction::where('campaign_id', $action_log->campaign_id)->where('id', $next_flow_id)->first();

            if (!empty($flow)) {
                printLog("Found next flow action.");
                $actionLogData = [
                    "campaign_id" => $action_log->campaign_id,
                    "no_of_records" => $action_log->no_of_records,
                    "response" => $loop_detected ? ['errors' => 'Loop detected!'] : "",
                    "status" => $loop_detected ? "Stopped" : "pending",
                    "report_status" => "pending",
                    "ref_id" => "",
                    "flow_action_id" => $next_flow_id,
                    "mongo_id" => $action_log->mongo_id,
                    'campaign_log_id' => $action_log->campaign_log_id
                ];
                printLog('Creating new action as per channel id ', 1);
                $actionLog = $campaign->actionLogs()->create($actionLogData);

                return $actionLog;
            } else {
                printLog("Didn't found next flow action.");
            }
        }
        return;
    }

    public function getReports($actionLogId)
    {
        $actionLog = ActionLog::where('id', $actionLogId)->first();

        // create token
        $campaign = Campaign::where('id', $actionLog->campaign_id)->first();
        $input['company'] = $campaign->company()->first();
        config(['msg91.jwt_token' => createJWTToken($input)]);

        $channelId = FlowAction::where('id', $actionLog->flow_action_id)->pluck('channel_id')->first();

        $lib = setLibrary($channelId);

        $data = [];
        $collection = '';
        switch ($channelId) {
            case 1: {
                    $collection = 'email_report_data';
                    $data = ["unique_id" => $actionLog->ref_id];
                }
                break;
            case 2: {
                    $collection = 'msg91_report_data';
                    $data = $actionLog->ref_id;
                }
                break;
            case 3: {
                }
                break;
            case 4: {
                }
                break;
        }
        $res = $lib->getReports($data);

        $service = setService($channelId);

        $service->storeReport($res, $actionLog, $collection);
    }
}
