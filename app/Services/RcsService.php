<?php

namespace App\Services;

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

    public function getRequestBody(FlowAction $flowAction, $action_log, $mongo_data, $templateVariables, $function)
    {
        $variables = collect($mongo_data['variables'])->map(function ($value, $key) use ($templateVariables) {
            if (in_array($key, $templateVariables)) {
                return $value;
            }
        });
        $variables = array_filter($variables->toArray());
        $variables = array_values($variables);

        // make template funciton and call using switch with $function
        $confTemplate = $flowAction->configurations[0]->template;
        $customer_variables = collect($mongo_data['mobiles'])->map(function ($item) {
            return [
                "customer_number" => $item['mobiles'],
                "variables" => empty($item['variables']) ? [] : $item['variables']
            ];
        })->toArray();
        $data = [
            "customer_number_variables" => $customer_variables,
            "project_id" => $confTemplate->project_id,
            "function_name" => $function,
            "name" => $confTemplate->name,
            "namespace" => $confTemplate->template_id,
            "variables" => $variables,
            "campaign_id" => $action_log->id . '_' . $action_log->mongo_id,
            "node_id" => (string)$flowAction->id
        ];
        $obj = new \stdClass();
        $obj->customer_number_variables = [];
        $template = $flowAction->template;
        collect($data['customer_number_variables'])->map(function ($item) use ($variables, $obj, $template) {
            // get variables for this contact
            $rcsVariables = getChannelVariables($template->variables, empty($item['variables']) ? [] : (array)$item['variables'], $variables, 5);
            $data = [
                'customer_number' => $item['customer_number'],
                'variables' => array_values($rcsVariables)
            ];
            array_push($obj->customer_number_variables, $data);
        });
        $data['customer_number_variables'] = $obj->customer_number_variables;
        return $data;
    }
}
