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
    }

    public function createRequestBody($data)
    {
        unset($data->variables);
        $obj = new \stdClass();
        $obj->arr = [];
        $obj->mob = [];
        collect($data)->map(function ($item) use ($obj) {
            $mob = collect($item)->map(function ($value) use ($obj) {

                $mobile = collect($value)->only('mobiles', 'variables')->toArray();
                if (empty($mobile["variables"])) {
                    array_push($obj->mob, $mobile["mobiles"]);
                } else {
                    return $mobile;
                }
                
            })->filter()->toArray();

            $obj->arr = array_merge($obj->arr, $mob);
        });
        if (!empty($obj->mob))
            array_push($obj->arr, ["mobiles" => $obj->mob]);
        return $obj->arr;
    }

    public function getRequestBody(FlowAction $flowAction, $mongo_data)
    {
        $data = collect($mongo_data["mobiles"])->map(function ($item) {
            $mob = $item["mobiles"];
            unset($item["mobiles"]);
            $arr = [
                "to" => is_string($mob) ? [$mob] : $mob,
                "components" => $item
            ];
            return $arr;
        })->toArray();

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
                    "to_and_components" => $data
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
