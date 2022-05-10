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
        // get mongoData
        $md = json_decode(json_encode($data));
        $mongoData = $md[0]->data;

        $conditionId = collect($flow->configurations)->firstWhere('name', 'Condition')->value;
        $countries = 1;
        switch ($conditionId) {
            case $countries: {
                    $obj = new \stdClass();
                    // sort module_data according to groups with groupId as key
                    $obj->modules =  $this->groupModuleData($flow->module_data);

                    $obj->mongoData = $mongoData;
                    $obj->data = new \stdClass();
                    collect($obj->modules)->map(function ($op_filters_group) use ($obj, $action_log) {
                        $op_filters = collect($op_filters_group)->keys()->toArray();
                        $op_value = collect($op_filters_group)->first();
                        if (!empty($op_value)) {
                            $newFlowAction = FlowAction::where('id', $op_value)->first();

                            $obj->data->currentData = new \stdClass();

                            /**
                             * function that will make a filter according to the coountry
                             */
                            $this->generateFilterData($obj, $op_filters);
                            $reqId = preg_replace('/\s+/', '',  Carbon::now()->timestamp) . '_' . md5(uniqid(rand(), true));
                            $filterdata_mongoID = [
                                'requestId' => $reqId,
                                'data' => $obj->data->currentData
                            ];

                            $mongoId = $this->mongo->collection('flow_action_data')->insertOne($filterdata_mongoID);
                            $actionLogData = [
                                "campaign_id" => $action_log->campaign_id,
                                "no_of_records" => 0,
                                "response" => "",
                                "status" => "pending",
                                "report_status" => "pending",
                                "flow_action_id" => $newFlowAction->id,
                                "ref_id" => "",
                                "mongo_id" => $reqId,
                                'campaign_log_id' => $action_log->campaign_log_id
                            ];
                            printLog('Creating new action as per channel id ', 1);
                            $actionLog = $newFlowAction->actionLog()->create($actionLogData);
                            $delayTime = collect($newFlowAction->configurations)->firstWhere('name', 'delay');
                            if (!empty($actionLog)) {
                                $input = new \stdClass();
                                $input->action_log_id =  $actionLog->id;
                                $this->createNewJob($newFlowAction->channel_id, $input, $delayTime->value);
                            }
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

    public function groupModuleData($moduleData)
    {
        // sort module_data according to groups with groupId as key
        $modules = new \stdClass();
        printLog("Sort module_data according to groupId");
        collect($moduleData)->map(function ($item, $key) use ($modules, $moduleData) {
            if (\Str::startsWith($key, 'op_')) {
                $keySplit = explode('_', $key);
                if (count($keySplit) == 2) {
                    $grpKey = $key . '_grp_id';
                    $grpId = $moduleData->$grpKey;
                    if (empty($modules->$grpId)) {
                        $modules->$grpId = new \stdClass();
                    }
                    $key = $keySplit[1];
                    $modules->$grpId->$key = $item;
                }
            }
        });
        return $modules;
    }

    public function generateFilterData($obj, $filterGroups)
    {
        collect($obj->mongoData)->map(function ($contacts, $field) use ($filterGroups, $obj) {
            if (empty($obj->data->currentData->$field)) {
                $obj->data->currentData->$field = [];
            }
            if ($field != 'variables') {
                $filterData = collect($contacts)->reject(function ($contact) use ($filterGroups, $obj, $field) {
                    // check if number's code exists in module data and countries Json both
                    if ($this->validPhoneNumber($contact->mobile, $filterGroups)) {
                        printLog("number is valid : " . $contact->mobile);
                        array_push($obj->data->currentData->$field, $contact);
                        return $contact;
                    }
                })->toArray();
                $obj->mongoData->$field = $filterData;
            } else {
                $obj->data->currentData->variables = $contacts;
            }
        });
    }

    public function validPhoneNumber($mobile, $filterArr)
    {
        $path = Filter::where('name', 'countries')->pluck('source')->first();
        $countriesJson = Cache::get('countriesJson');
        if (empty($countriesJson)) {
            $countriesJson = json_decode(file_get_contents($path));
            Cache::put('countriesJson', $countriesJson, 86400);
        }
        for ($i = 4; $i > 0; $i--) {
            $mobileCode = substr($mobile, 0, $i);

            $codeData = (array)collect($countriesJson)->firstWhere('International dialing', $mobileCode);
            if (!empty($codeData)) {
                $countryCode = $codeData['Country code'];
                return  in_array($countryCode, $filterArr);
            }
        }
    }

    public function generateFilterDatas($mongoData, $filters)
    {

        $data = collect($mongoData)->map(function ($item, $key) use ($filters) {
            if ($key != 'variables') {
                $filteredData = collect($item)->reject(function ($value, $key) use ($filters) {

                    $valid = validPhoneNumber($value->mobiles, $filters);
                    if (!$valid)
                        return $value;
                });
                return $filteredData;
            }
        });
        $data = json_decode($data);
        $data->variables = $mongoData->variables;
        return $data;
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
