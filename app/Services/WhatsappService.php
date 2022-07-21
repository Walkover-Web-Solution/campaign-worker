<?php

namespace App\Services;

use App\Libs\MongoDBLib;
use App\Models\FlowAction;

/**
 * Class WhatsappService
 * @package App\Services
 */
class WhatsappService
{
    protected $mongo;
    public function __construct()
    {
        //
    }


    public function getRequestBody(FlowAction $flowAction, $obj, $mongo_data)
    {
        $template = $flowAction->template;

        $obj->mobiles = [];
        $obj->invalid_json = false;
        collect($mongo_data)->map(function ($data) use ($obj, $template, $flowAction) {
            $commonVariables = empty($data->variables) ? [] : $data->variables;
            $obj->commonMobiles = [];
            collect($data->to)->map(function ($contact) use ($obj, $template, $commonVariables, $flowAction) {
                if (!empty($contact->mobiles)) {
                    $contactVariables = empty($contact->variables) ? [] : $contact->variables;
                    $whatsappVariables = getChannelVariables($template->variables, $contactVariables, $commonVariables, $flowAction->channel_id);

                    // In case of invalid json variables
                    if ($whatsappVariables == 'invalid_json') {
                        $obj->invalid_json = true;
                    }

                    // In case of Empty contact variables
                    if (empty($contactVariables)) {
                        array_push($obj->commonMobiles, $contact->mobiles);
                    } else {
                        array_push($obj->mobiles, [
                            "to" => [$contact->mobiles],
                            "components" => $whatsappVariables
                        ]);
                    }
                }
            });
            if (!empty($obj->commonMobiles)) {
                array_push($obj->mobiles, [
                    "to" => $obj->commonMobiles,
                    "components" => $commonVariables
                ]);
            }
        });

        if ($obj->invalid_json) {
            return;
        }

        $configurations = collect($flowAction->configurations);
        $template = $configurations->firstWhere('name', 'template');
        $integratedNo = $configurations->firstWhere('name', 'integrated_number');

        $body = [
            "integrated_number" => $integratedNo->value,
            "content_type" => "template",
            "payload" => [
                "type" => "template",
                "template" => [
                    "name" => $template->template->template_id,
                    "language" => $template->template->language,
                    "namespace" => $template->template->namespace,
                    "to_and_components" => $obj->mobiles
                ]
            ],
            "node_id" => (string)$flowAction['id']
        ];
        return $body;
    }

    public function storeReport($res, $actionLog, $collection)
    {
        //
    }
}
