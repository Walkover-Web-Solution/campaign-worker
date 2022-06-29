<?php

namespace App\Services;

use App\Libs\MongoDBLib;
use App\Models\ActionLog;
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
                'requestId' => $eventMongoId
            ]);
            $requestBody = json_decode(json_encode($requestBody))[0]->data;

            $campaign_id_split = explode('_', $requestBody->campaign_id);
            $actionLogId = $campaign_id_split[0];

            $action_log = ActionLog::where('id', (int)$actionLogId)->first();
        } else {
            // In case of email eventMongoId will be action_log model from queue : 'email_to_campaign_logs'
            $action_log = $eventMongoId;
        }
        if (empty($action_log)) {
            throw new \Exception('Action Log not found!');
        }
        $channel_id = $action_log->flowAction()->first()->channel_id;

        // Fetch data from mongo
        $mongo_data = $this->mongo->collection('flow_action_data')->find([
            'requestId' => $action_log->mongo_id
        ]);
        $mongo_data = json_decode(json_encode($mongo_data))[0];

        // Filter data according to events
        $filteredData = $this->getEventFilteredData($requestBody->data, $channel_id, $mongo_data);

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
                        $next_action_log = $this->createNextActionLog($flowAction, ucfirst($keySplit[1]), $action_log, $filteredData[$keySplit[1]], $mongo_data);

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
    public function getEventFilteredData($requestData, $channel_id, $mongo_data)
    {
        $obj = new \stdClass();
        $obj->data = [];
        $obj->data['success'] = [];
        $obj->data['failed'] = [];
        $obj->data['read'] = [];
        $obj->data['unread'] = [];

        collect($requestData)->map(function ($item) use ($mongo_data, $obj, $channel_id) {
            $item = (object)$item;
            collect($mongo_data->data->sendTo)->map(function ($sendToItem, $key) use ($obj, $item, $channel_id) {
                collect($sendToItem)->map(function ($contacts, $field) use ($obj, $item, $channel_id, $key) {
                    if ($field != 'variables') {
                        if ($channel_id == 1) {
                            $contact = (array)collect($contacts)->firstWhere('email', $item->email);
                        } else {
                            $contact = (array)collect($contacts)->firstWhere('mobiles', $item->mobile);
                        }
                        // Add all events in condition which are recieved from microservices
                        if (!empty($contact)) {
                            $event = strtolower($item->event);
                            $event = getEvent($event, $channel_id); // get synnonym of respected microservice's event which are available in events table
                            if ($event == 'success') {
                                if (empty($obj->data['success'][$key][$field]))
                                    $obj->data['success'][$key][$field] = [];
                                array_push($obj->data['success'][$key][$field], $contact);
                            } else if ($event == 'failed') {
                                if (empty($obj->data['failed'][$key][$field]))
                                    $obj->data['failed'][$key][$field] = [];
                                array_push($obj->data['failed'][$key][$field], $contact);
                            } else if ($event == 'read') {
                                if (empty($obj->data['read'][$key][$field]))
                                    $obj->data['read'][$key][$field] = [];
                                array_push($obj->data['read'][$key][$field], $contact);
                            } else if ($event == 'unread') {
                                if (empty($obj->data['unread'][$key][$field]))
                                    $obj->data['unread'][$key][$field] = [];
                                array_push($obj->data['unread'][$key][$field], $contact);
                            }
                        }
                    } else if ($field == 'variables') {
                        $event = strtolower($item->event);
                        if (empty($obj->data[$event])) {
                            return;
                        }
                        if (empty($obj->data[$event][$key][$field])) {
                            $obj->data[$event][$key][$field] = [];
                        }
                        $obj->data[$event][$key][$field] = array_merge($obj->data[$event][$key][$field], (array)$contacts);
                    }
                });
            });
        });
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
                return $actionLog;
            } else {
                printLog("Didn't found next flow action.");
            }
        }
        return;
    }
}
