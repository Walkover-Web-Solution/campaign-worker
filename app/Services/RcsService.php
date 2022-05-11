<?php

namespace App\Services;

use App\Libs\MongoDBLib;
use App\Models\CampaignLog;
use App\Models\FlowAction;

/**
 * Class RcsService
 * @package App\Services
 */
class RcsService
{
    public function __construct()
    {
        //
    }

    public function createRequestBody($data)
    {
        unset($data->variables);
        $obj = new \stdClass();
        $obj->arr = [];
        collect($data)->map(function ($item) use ($obj) {

            $mob = collect($item)->map(function ($value) {
                return collect($value)->only('mobiles')->toArray();
            })->toArray();

            $obj->arr = array_merge($obj->arr, $mob);
        });

        return $obj->arr;
    }

    public function getRequestBody(FlowAction $flowAction, $action_log, $mongo_data, $variables, $function)
    {
        $campLog = CampaignLog::where('id', $action_log->campaign_log_id)->first();
        // make template funciton and call using switch with $function
        $template = $flowAction->configurations[0]->template;
        $data = [
            "customer_numbers" => $mongo_data['mobiles']->pluck('mobiles')->toArray(),
            "project_id" => $template->project_id,
            "function_name" => $function,
            "name" => $template->name,
            "namespace" => $template->template_id,
            "variables" => $variables,
            "campaign_id " => $campLog->mongo_uid
        ];
        return $data;
    }
}
