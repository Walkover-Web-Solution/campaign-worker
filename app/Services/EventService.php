<?php

namespace App\Services;

use App\Libs\MongoDBLib;
use App\Models\ActionLog;
use App\Models\ActionLogRefIdRelation;
use App\Models\ChannelType;
use App\Models\FlowAction;
use Carbon\Carbon;

/**
 * Class EventService
 * @package App\Services
 */
class EventService
{
    protected $mongo;
    public function __construct()
    {
        //
    }

    public function processEvent($eventMongoId, $requestBody = [], $noMongo = false)
    {
        if (empty($this->mongo)) {
            $this->mongo = new MongoDBLib();
        }
        if (!$noMongo) {
            $requestBody = $this->mongo->collection('event_action_data')->find([
                'request_id' => $eventMongoId
            ]);

            $requestBody = json_decode(json_encode($requestBody))[0]->data;
            $requestBody = (object)["data" => $requestBody];
            // In case of RCS
            // $campaign_id_split = explode('_', $requestBody->campaign_id);
            // $actionLogId = $campaign_id_split[0];

            $ref_id = ActionLogRefIdRelation::where('ref_id', $eventMongoId)->first();
            $action_log = null;
            if (!empty($ref_id)) {
                $action_log = $ref_id->actionLog;
            }
        } else {
            // In case of email eventMongoId will be action_log model from queue : 'email_to_campaign_logs'
            $action_log = $eventMongoId;
        }
        if (empty($action_log)) {
            throw new \Exception('Action Log not found!');
        }

        $campaignLog = $action_log->campaignLog;
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

        $channel_id = $action_log->flowAction()->first()->channel_id;
        // Fetch data from mongo
        $mongo_data = $this->mongo->collection('flow_action_data')->find([
            'requestId' => $action_log->mongo_id
        ]);
        $mongo_data = json_decode(json_encode($mongo_data))[0];

        // Filter data according to events
        $filteredData = $this->getEventFilteredData($requestBody->data, $channel_id, $mongo_data, $action_log);
        logTest("email webhook filtered body", ["data" => $filteredData], "event");
        $obj = new \stdClass();
        $obj->noActionFoundFlag = true;
        $obj->loop = false;
        collect($action_log->flowAction->module_data)->map(function ($flowActionId, $key) use ($filteredData, $action_log, $obj, $mongo_data) {
            if ($obj->loop) {
                return;
            }
            if (\Str::startsWith($key, 'op_')) {
                $keySplit = explode('_', $key);
                if (count($keySplit) == 2) {
                    if (!empty($flowActionId)) {
                        $flowAction = FlowAction::where('id', $flowActionId)->first();
                        // get delay count
                        $delayTime = collect($flowAction->configurations)->firstWhere('name', 'delay');
                        $delayValue = getSeconds($delayTime->unit, $delayTime->value);
                        // create next action_log
                        $type = '$push';
                        if (empty($action_log->action_id[$keySplit[1]])) {
                            $next_action_log = $this->createNextActionLog($flowAction, ucfirst($keySplit[1]), $action_log, $filteredData[$keySplit[1]], $mongo_data);
                            if ($action_log->action_id == []) {
                                $action_log->action_id = [$keySplit[1] => $next_action_log->id];
                            } else {
                                $action_log->action_id = array_merge($action_log->action_id, [$keySplit[1] => $next_action_log->id]);
                            }
                            $action_log->save();
                            $type = '$set';
                        }
                        $next_action_log = ActionLog::where('id', $action_log->action_id[$keySplit[1]])->first();
                        if ($type == '$push') {
                            if (empty($filteredData[$keySplit[1]])) {
                                return;
                            }
                            $filteredData[$keySplit[1]] = $filteredData[$keySplit[1]][0];
                        }
                        $data = $this->mongo->collection('flow_action_data')->append(["requestId" => $next_action_log->mongo_id], ['data.sendTo' => $filteredData[$keySplit[1]]], $type);

                        if (!empty($next_action_log)) {
                            // If loop detected next_action_log's status will be Stopped
                            if ($next_action_log->status == 'Stopped') {
                                $campaignLog = $next_action_log->campaignLog;
                                $campaignLog->status = 'Stopped';
                                $campaignLog->save();

                                $slack = new SlackService();
                                $error = array(
                                    'Action_log_id' => $next_action_log->id
                                );
                                $slack->sendLoopErrorToSlack((object)$error);
                                $obj->noActionFoundFlag = false;
                                $obj->loop = true;
                                return;
                            }
                            $input = new \stdClass();
                            $input->action_log_id =  $next_action_log->id;
                            // create job for next_action_log
                            $queue = getQueue($flowAction->channel_id);
                            createNewJob($input, $queue, $delayValue);
                        }
                    }
                }
            }
        });
        if ($obj->noActionFoundFlag) {
            // Call cron to set campaignLog Complete
            updateCampaignLogStatus($action_log->campaignLog()->first());
        }
    }

    /**
     * Filters data from webhook, according to event
     */
    public function getEventFilteredData($requestData, $channel_id, $mongo_data, $action_log)
    {
        $obj = new \stdClass();
        $obj->data = [];
        $obj->count = 0;
        $obj->contactCount = 0;
        $obj->sendTo = $mongo_data->data->sendTo;
        $obj->data['success'] = [];
        $obj->data['failed'] = [];
        $obj->data['read'] = [];
        $obj->data['unread'] = [];

        switch ($channel_id) {
            case 1: {
                    collect($requestData)->map(function ($item) use ($mongo_data, $obj, $channel_id) {
                        $item = (object)$item;
                        $event = strtolower($item->event);
                        $event = strtolower(getEvent($event, $channel_id)); // get synnonym of respected microservice's event which are available in events table
                        if ($event != 'queued') {
                            $obj->count = 0;
                            collect($mongo_data->data->sendTo)->map(function ($sendToItem) use ($obj, $item, $event) {
                                $contact = [];
                                if (!empty($sendToItem->to[0]->email)) {
                                    if ($sendToItem->to[0]->email == $item->email && empty($obj->sendTo[$obj->count]->event_received)) {
                                        $contact = (array)$sendToItem;
                                        if (!empty($sendToItem->to)) {
                                            $obj->contactCount += collect($sendToItem->to)->whereNotNull('email')->count();
                                        }
                                        if (!empty($sendToItem->cc)) {
                                            $obj->contactCount += collect($sendToItem->cc)->whereNotNull('email')->count();
                                        }
                                        if (!empty($sendToItem->bcc)) {
                                            $obj->contactCount += collect($sendToItem->bcc)->whereNotNull('email')->count();
                                        }
                                        $obj->sendTo[$obj->count]->event_received = true;
                                        if (!empty($contact)) {
                                            //Genreating body on basis of event for further operations
                                            if ($event == 'success') {
                                                array_push($obj->data['success'], $contact);
                                            } else if ($event == 'failed') {
                                                array_push($obj->data['failed'], $contact);
                                            } else if ($event == 'read') {
                                                array_push($obj->data['read'], $contact);
                                            } else if ($event == 'unread') {
                                                array_push($obj->data['unread'], $contact);
                                            }
                                        }
                                    }
                                    $obj->count++;
                                }
                            })->toArray();
                        }
                    })->toArray();
                }
                break;
            default: {
                    //In case of SMS , whatsapp , RCS 
                    collect($requestData)->map(function ($item) use ($mongo_data, $obj, $channel_id) {
                        $item = (object)$item;
                        $event = strtolower($item->event);
                        $event = strtolower(getEvent($event, $channel_id)); // get synnonym of respected microservice's event which are available in events table
                        if ($event != 'queued') {
                            $obj->count = 0;

                            collect($mongo_data->data->sendTo)->map(function ($sendToItem) use ($obj, $item, $event) {
                                $contact = [];
                                if (!empty($sendToItem->to[0]->mobiles)) {
                                    if ($sendToItem->to[0]->mobiles == $item->mobile && empty($obj->sendTo[$obj->count]->event_received)) {
                                        $contact = (array)$sendToItem;
                                        if (!empty($sendToItem->to)) {
                                            $obj->contactCount += collect($sendToItem->to)->whereNotNull('mobiles')->count();
                                        }
                                        $obj->sendTo[$obj->count]->event_received = true;
                                        if (!empty($contact)) {
                                            //Genreating body on basis of event for further operations
                                            if ($event == 'success') {
                                                array_push($obj->data['success'], $contact);
                                            } else if ($event == 'failed') {
                                                array_push($obj->data['failed'], $contact);
                                            } else if ($event == 'read') {
                                                array_push($obj->data['read'], $contact);
                                            } else if ($event == 'unread') {
                                                array_push($obj->data['unread'], $contact);
                                            }
                                        }
                                    }
                                    $obj->count++;
                                }
                            })->toArray();
                        }
                    })->toArray();
                }
        }

        //Updating the changed body with event_recived key in MongoDB 
        $this->mongo->collection('flow_action_data')->update(["requestId" => $mongo_data->requestId], ["data.sendTo" => $obj->sendTo]);

        //Updating the count of the records received events inn action log
        $value = $action_log->event_recieved;
        $action_log->event_recieved = $value + $obj->contactCount;
        if ($action_log->event_recieved >= $action_log->no_of_records)
            $action_log->status = "Completed";
        $action_log->save();
        return $obj->data;
    }

    /**
     * Create next Action Log from webhook
     */
    public function createNextActionLog($flowAction, $event, $action_log, $filteredData, $mongo_data)
    {
        // As per change in sendTo to make everyobject into one request, FilterData will be send in sendTo key
        $filteredData = ['sendTo' => array_values($filteredData)];
        // generating random key with time stamp for mongo requestId
        $reqId = preg_replace('/\s+/', '', Carbon::now()->timestamp) . '_' . md5(uniqid(rand(), true));

        switch ($event) {
            case "Success": {
                    $event_id = 1;
                    break;
                }
            case "Failed": {
                    $event_id = 2;
                    break;
                }
            case "Read": {
                    $event_id = 3;
                    break;
                }
            case "Unread": {
                    $event_id = 4;
                    break;
                }
        }

        // Check for loop and increase count
        $thisFlowAction = $action_log->flowAction;
        $next_flow_id = $flowAction->id; // Next flowAction
        $path = (object)$mongo_data->node_count;
        $path_key = $thisFlowAction->id . '.' . $event_id;
        $loop_detected = false;
        if (empty($path->$path_key)) {
            $path->$path_key =  $next_flow_id + 0.1;
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

        // Store data in mongo and get requestId
        $data = [
            'requestId' => $reqId,
            'data' => $filteredData,
            'node_count' => $path
        ];
        $this->mongo->collection('flow_action_data')->insertOne($data);

        //generating an array of all the events belong to flowaction's channel_type
        $events = ChannelType::where('id', $flowAction->channel_id)->first()->events()->pluck('name')->toArray();

        if (in_array($event, $events)) {

            if (!empty($flowAction)) {
                printLog("Found next flow action.");
                // // Count number of records
                // $numberOfRecords = countContacts($filteredData);
                $actionLogData = [
                    "campaign_id" => $action_log->campaign_id,
                    "no_of_records" => $action_log->no_of_records,
                    "response" => $loop_detected ? ['errors' => 'Loop detected!'] : "",
                    "status" => $loop_detected ? "Stopped" : "pending",
                    "report_status" => "pending",
                    "ref_id" => "",
                    "flow_action_id" => $flowAction->id,
                    "mongo_id" => $reqId,
                    'campaign_log_id' => $action_log->campaign_log_id
                ];
                printLog('Creating new action as per channel id ');
                $actionLog = ActionLog::create($actionLogData);
                if ($loop_detected) {
                    $actionLog->ref_id()->create([
                        'ref_id' => "", 'status' => "Stopped",
                        'response' => ['errors' => 'Loop detected!'], 'no_of_records' => $actionLog->no_of_records
                    ]);
                }
                return $actionLog;
            } else {
                printLog("Didn't found next flow action.");
            }
        }
        return;
    }
}
