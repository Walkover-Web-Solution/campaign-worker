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
        $campaignLog = $action_log->campaignLog;
        $campaign = $action_log->campaign;

        // Return if CampaignLog is paused
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
        $flowAction = FlowAction::where('campaign_id', $action_log->campaign_id)->where('id', $action_log->flow_action_id)->first();


        // generating the JWT token
        $input['company'] = $campaign->company;
        $input['user'] = $campaign->user;
        $input['ip'] = $campaignLog->ip;
        $input['need_validation'] = $flowAction->channel_id == 2 ? false : (bool) $campaignLog->need_validation;
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

        // Get sliced data from mongo
        if (empty($md[0]->sendToCount)) {
            $sendToCount = 0;
        } else {
            $sendToCount = $md[0]->sendToCount;
        }
        $mongo_sliced_data = array_slice($md[0]->data->sendTo, $sendToCount);

        $this->mongo->collection('flow_action_data')
            ->update(["requestId" => $action_log->mongo_id],  ["sendToCount" => count($mongo_sliced_data)]);

        if (empty($mongo_sliced_data)) {
            return;
        }

        printLog("generating the request body data according to flow channel id.", 2);
        $reqBody = $this->getRequestBody($flowAction, $mongo_sliced_data, $action_log, $attachments, $reply_to);

        $invalidJson = false;
        if ($reqBody == 'invalid_json') {
            $invalidJson = true;
            $reqBody = [
                "data" => [],
                "count" => $action_log->no_of_records
            ];
            $reqBody = (object)$reqBody;
        }

        //get unique data only and count duplicate
        $duplicateCount = 0;
        switch ($flowAction->channel_id) {
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

        // Update no_of_records in action_log
        $action_log->no_of_records = $reqBody->count;
        $action_log->save();

        $channel_type = ChannelType::where('id', $flowAction->channel_id)->first();
        $maxCount = 1000;
        if (!empty($channel_type)) {
            $maxCount = $channel_type->capacity;
        }
        $payload = [];
        if (!empty($reqBody->data)) {
            $payload = getRecipients($reqBody->data, $flowAction->channel_id);
        }
        $count = 0;
        $currentCount = $reqBody->count;
        if ($reqBody->count > $maxCount) {
            $temp = [];
            foreach ($payload as $recipients) {
                $recipientCount = getRecipientCount($recipients, $flowAction->channel_id);
                $count += $recipientCount;
                if ($count > $maxCount) {
                    $currentCount = $count - $recipientCount;
                    // Set duplicate count to zero if sending in bunch but give in next calling for this function
                    $statusANDref_id = $this->sendAndUpdateRefId($reqBody, $currentCount, $temp, $flowAction, 0, $action_log, $invalidJson);
                    $this->nextJob($flowAction, $action_log, $statusANDref_id->status, $statusANDref_id->ref_id, $md, $campaignLog, $temp);
                    $count = $recipientCount;
                    $temp = [];
                }
                array_push($temp, $recipients);
            }
            // For last set of bunch - currentCount will the remaining count from above loop
            $currentCount = $count;
            $payload = $temp;
        }
        $statusANDref_id = $this->sendAndUpdateRefId($reqBody, $currentCount, $payload, $flowAction, $duplicateCount, $action_log, $invalidJson);
        $this->nextJob($flowAction, $action_log, $statusANDref_id->status, $statusANDref_id->ref_id, $md, $campaignLog, $payload);

        return;
    }

    public function sendAndUpdateRefId($reqBody, $currentCount, $temp, $flowAction, $duplicateCount, $action_log, $invalidJson = false)
    {
        $sendData = $reqBody->data;
        if (!empty($sendData)) {
            switch ($flowAction->channel_id) {
                case 1: {
                        $sendData->recipients = $temp;
                    }
                    break;
                case 2: {
                        $sendData->recipients = $temp;
                    }
                    break;
                case 3: {
                        $sendData->payload->template->to_and_components = $temp;
                    }
                    break;
                case 4: {
                        return;
                    }
                    break;
                case 5: {
                        $sendData->customer_number_variables = $temp;
                    }
                    break;
            }
        }
        $lib = setLibrary($flowAction->channel_id);
        $res = new \stdClass();
        if ($currentCount == 0) {
            $res->hasError = true;
            $res->message = "No Data Found";
        } else {
            if ($invalidJson) {
                $res->hasError = true;
                $res->message = "Invalid Json!";
            } else {
                printLog("Now sending data to microservice", 1);
                $res = $lib->send($sendData);
                //adding duplicate count to response
                if (!empty($res)) {
                    $res->duplicate = $duplicateCount;
                }
            }
        }

        $val = "";
        $status = "Success";
        if ($flowAction->channel_id == 1 && !empty($res) && !$res->hasError) {
            $val = $res->data->unique_id;
        } else if ($flowAction->channel_id == 2 && !empty($res) && !$res->hasError) {
            $val = $res->data;
        } else if ($flowAction->channel_id == 3 && !empty($res) && !$res->hasError) {
            $val = $res->request_id;
        } else if ($flowAction->channel_id == 5 && !empty($res) && !$res->hasError) {
            // for now generating random ref_id
            $val = preg_replace('/\s+/', '_',  Carbon::now()->timestamp) . '_' . md5(uniqid(rand(), true));
        } else {
            // for now generating random ref_id
            $val = preg_replace('/\s+/', '_',  Carbon::now()->timestamp) . '_' . md5(uniqid(rand(), true));
            $status = "Failed";
            printLog("Microservice api failed.");
        }

        $action_log->ref_id()->create(['ref_id' => $val, 'status' => $status, 'response' => $res, 'no_of_records' => $currentCount]);
        return (object)[
            "status" => $status,
            "ref_id" => $val
        ];
    }

    public function nextJob($flowAction, $action_log, $status, $ref_id, $md, $campaignLog, $recipients)
    {
        printLog('We have successfully send data to: ' . $flowAction->channel_id . ' channel', 1, empty($res) ? (array)['message' => 'NULL RESPONSE'] : (array)$res);

        // Will return create_webhook in case of webhook
        $next_action_log = $this->createNextActionLog($flowAction, $action_log, $status, $md);

        printLog('Got new action log and its id is ' . empty($next_action_log) ? "Action Log NOT FOUND" : $next_action_log->id, 1);
        if (!empty($next_action_log)) {

            if ($next_action_log == 'create_webhook') {
                if ($flowAction->channel_id == 1 || $flowAction->channel_id == 5) {
                    if ($status != 'Failed') {
                        return;
                    }
                }
                $this->createWebhook($recipients, $status, $ref_id, $flowAction->channel_id);
                return;
            }

            // // If loop detected next_action_log's status will be Stopped
            // if ($next_action_log->status == 'Stopped') {
            //     $campaignLog->status = 'Stopped';
            //     $campaignLog->save();

            //     $slack = new SlackService();
            //     $error = array(
            //         'Action_log_id' => $next_action_log->id
            //     );
            //     $slack->sendLoopErrorToSlack((object)$error);
            //     return;
            // }

            // $nextFlowAction = $next_action_log->flowAction;
            // $delayTime = collect($nextFlowAction->configurations)->firstWhere('name', 'delay');
            // if (empty($delayTime)) {
            //     $delayValue = 0;
            // } else {
            //     $delayValue = getSeconds($delayTime->unit, $delayTime->value);
            // }

            // printLog("Now creating new job for action log.", 1);
            // $input = new \stdClass();
            // $input->action_log_id =  $next_action_log->id;
            // $queue = getQueue($nextFlowAction->channel_id);
            // if ($campaignLog->is_paused)
            //     $delayValue = 0;
            // createNewJob($input, $queue, $delayValue);
        } else {
            // Call cron to set campaignLog Complete
            updateCampaignLogStatus($campaignLog);
        }
    }

    public function getRequestBody($flowAction, $md, $action_log, $attachments, $reply_to)
    {
        /**
         * extracting the all the variables from the mongo data
         */
        $obj = new \stdClass();
        $obj->count = 0;

        // get template of this flowAction
        $temp = $flowAction->template;

        $mongo_data = $md;

        $data = [];

        $service = setService($flowAction->channel_id);
        switch ($flowAction->channel_id) {
            case 1: //For Email
                $data = $service->getRequestBody($flowAction, $obj, $mongo_data, $temp->variables, $attachments, $reply_to);
                printLog("GET REQUEST BODY", 1, $data);
                break;
            case 2: //For SMS
                $data = $service->getRequestBody($flowAction, $obj, $mongo_data);
                $obj->count = count($data['recipients']);
                break;
            case 3:
                $data = $service->getRequestBody($flowAction, $obj, $mongo_data);
                if ($obj->invalid_json) {
                    return 'invalid_json';
                }

                // Count total mobiles
                $mobiles = collect($data['payload']['template']['to_and_components'])->pluck('to');
                $mobiles->map(function ($mobile) use ($obj) {
                    $obj->count += count($mobile);
                });
                break;
            case 5: //for rcs
                $data = $service->getRequestBody($flowAction, $obj, $action_log, $mongo_data, $temp->variables, "template");
                $obj->count = count($data['customer_number_variables']);
                break;
        }

        $obj->data = json_decode(collect($data));
        return $obj;
    }

    public function createNextActionLog($flowAction, $action_log, $status, $md)
    {
        printLog("We are here to create new action log as per module data", 1);

        $events = ChannelType::where('id', $flowAction->channel_id)->first()->events()->pluck('name')->toArray(); //generating an array of all the events belong to flow channel id
        $campaign = Campaign::find($action_log->campaign_id);

        $next_flow_id = null;
        if ($status == 'Success') {
            $event_id = 1;
            $next_flow_id = isset($flowAction->module_data->op_success) ? $flowAction->module_data->op_success : null;
        } else {
            $event_id = 2;
            $next_flow_id = isset($flowAction->module_data->op_failed) ? $flowAction->module_data->op_failed : null;
        }
        // Loop will be handled from EventService
        return 'create_webhook';

        // Check for loop and increase count
        // $path = $md[0]->node_count;
        // $path_key = $flowAction->id . '.' . $event_id;
        // $loop_detected = false;
        // if (empty($path->$path_key)) {
        //     $path->$path_key = $next_flow_id + 0.1;
        // } else {
        //     // In case next_flow_action gets changed, so reinitialize it
        //     if ((int)$path->$path_key != $next_flow_id) {
        //         $path->$path_key = $next_flow_id + 0.1;
        //     } else {
        //         $path->$path_key += 0.1;
        //         $count = ($path->$path_key * 10) % 10;
        //         if ($count > 2) {
        //             $loop_detected = true;
        //         }
        //     }
        // }

        // Update path in mongo - REMOVE (comment)
        // $this->mongo->collection('flow_action_data')
        //     ->update(["requestId" => $action_log->mongo_id],  ["node_count" => $path]);
        // if (!$loop_detected) {
        //     return 'create_webhook';
        // }

        // printLog('Get status from microservice ' . $status, 1);
        // printLog("Events are ", 1, $events);
        // if (in_array($status, $events) && !empty($next_flow_id)) {
        //     printLog('Next flow id is ' . $next_flow_id, 1);
        //     $flow = FlowAction::where('campaign_id', $action_log->campaign_id)->where('id', $next_flow_id)->first();

        //     if (!empty($flow)) {
        //         printLog("Found next flow action.");
        //         $actionLogData = [
        //             "campaign_id" => $action_log->campaign_id,
        //             "no_of_records" => $action_log->no_of_records,
        //             "response" => $loop_detected ? ['errors' => 'Loop detected!'] : "",
        //             "status" => $loop_detected ? "Stopped" : "pending",
        //             "report_status" => "pending",
        //             "ref_id" => "",
        //             "flow_action_id" => $next_flow_id,
        //             "mongo_id" => $action_log->mongo_id,
        //             'campaign_log_id' => $action_log->campaign_log_id
        //         ];
        //         printLog('Creating new action as per channel id ', 1);
        //         $actionLog = $campaign->actionLogs()->create($actionLogData);

        //         return $actionLog;
        //     } else {
        //         printLog("Didn't found next flow action.");
        //     }
        // }
        // return;
    }

    public function createWebhook($recipients, $status, $ref_id, $channel_id)
    {
        $data = [];
        switch ($channel_id) {
            case 1: {
                    $data = collect($recipients)->map(function ($item) use ($status) {
                        return [
                            "email" => $item->to[0]->email,
                            "event" => $status
                        ];
                    })->toArray();
                }
                break;
            case 2: {
                    $mobiles = collect($recipients)->pluck('mobiles');
                    $data = collect($mobiles)->map(function ($mobile) use ($status) {
                        return [
                            "mobile" => $mobile,
                            "event" => $status
                        ];
                    })->toArray();
                }
                break;
            case 3: {
                    $obj = (object)[];
                    $obj->mobiles = [];
                    $mobiles = collect($recipients)->pluck('to');
                    $mobiles->map(function ($items) use ($obj) {
                        $obj->mobiles = array_merge($obj->mobiles, $items);
                    });
                    $data = collect($obj->mobiles)->map(function ($mobile) use ($status) {
                        return [
                            "mobile" => $mobile,
                            "event" => $status
                        ];
                    })->toArray();
                }
                break;
            case 4: {
                    return;
                }
                break;
            case 5: {
                    $data = collect($recipients)->map(function ($mobiles) use ($status) {
                        return [
                            "mobile" => $mobiles->customer_number[0],
                            "event" => $status
                        ];
                    })->toArray();
                }
                break;
            default:
                return;
        }
        $webhookData = [
            "request_id" => $ref_id,
            "data" => $data
        ];
        if (empty($this->mongo)) {
            $this->mongo = new MongoDBLib;
        }
        $this->mongo->collection('event_action_data')->insertOne($webhookData);

        $input = (object)[];
        $input->request_id = $ref_id;
        // Create job for webhook
        createNewJob($input, "event_processing");
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
