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

    public function getRequestBody(FlowAction $flowAction, $obj, $action_log, $mongo_data, $templateVariables, $function)
    {
        $template = $flowAction->template;

        $obj->customer_variables = [];
        collect($mongo_data)->map(function ($data) use ($obj, $template, $flowAction) {
            $commonVariables = empty($data->variables) ? [] : $data->variables;
            collect($data->to)->map(function ($contact) use ($obj, $template, $commonVariables, $flowAction) {
                if (!empty($contact->mobiles)) {
                    $contactVariables = empty($contact->variables) ? [] : $contact->variables;
                    $rcsVariables = getChannelVariables($template->variables, $contactVariables, $commonVariables, $flowAction->channel_id);
                    array_push($obj->customer_variables, [
                        "customer_number" => [$contact->mobiles],
                        "variables" => $rcsVariables
                    ]);
                }
            });
        });

        // make template funciton and call using switch with $function
        $confTemplate = $flowAction->configurations[0]->template;

        $data = [
            "customer_number_variables" => $obj->customer_variables,
            "project_id" => $confTemplate->project_id,
            "function_name" => $function,
            "name" => $confTemplate->name,
            "namespace" => $confTemplate->template_id,
            "campaign_id" => $action_log->id . '_' . $action_log->mongo_id,
            "node_id" => (string)$flowAction->id
        ];
        return $data;
    }
}
