<?php

namespace App\Services;

use App\Libs\MongoDBLib;
use App\Models\FlowAction;
use Carbon\Carbon;

/**
 * Class SmsService
 * @package App\Services
 */
class SmsService
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
        collect($mongo_data)->map(function ($data) use ($obj, $template, $flowAction) {
            $commonVariables = empty($data->variables) ? [] : $data->variables;
            collect($data->to)->map(function ($contact) use ($obj, $template, $commonVariables, $flowAction) {
                if (!empty($contact->mobiles)) {
                    $contactVariables = empty($contact->variables) ? [] : $contact->variables;
                    $smsVariables = getChannelVariables($template->variables, $contactVariables, $commonVariables, $flowAction->channel_id);
                    array_push($obj->mobiles, [
                        "mobiles" => $contact->mobiles,
                        "variables" => $smsVariables
                    ]);
                }
            });
        });

        return [
            "flow_id" => $template->template_id,
            'recipients' => $obj->mobiles,
            "short_url" => true,
            "node_id" => (string)$flowAction['id']
        ];
    }

    public function storeReport($res, $actionLog, $collection)
    {
        $obj = new \stdClass();
        $obj->queued = 0;
        $obj->delivered = 0;
        $obj->failed = 0;
        $obj->total = 0;

        if ($res->type == 'success') {
            collect($res->reports)->map(function ($item) use ($obj) {
                $message = $item->desc;
                if ($message == 'Pending' || $message == 'Report Pending' || $message == 'Submitted' || $message == 'Sent') {
                    $obj->queued++;
                } else if ($message == 'Delivered' || $message == 'Opened' || $message == 'Unsubscribed' || $message == 'Clicked') {
                    $obj->delivered++;
                } else if (
                    $message == 'Rejected by Kannel or Provider' || $message == 'NDNC Number' || $message == 'Rejected By Provider'
                    || $message == 'Number under blocked circle' || $message == 'Blocked Number' || $message == 'Bounced'
                    || $message == 'Failed' || $message == 'Auto Failed'
                ) {
                    $obj->failed++;
                }
            });
        }
        $obj->total = $obj->queued + $obj->delivered + $obj->failed;

        $actionLogReportData = [
            'total' => $obj->total,
            'delivered' => $obj->delivered,
            'failed' => $obj->failed,
            'pending' => $obj->queued,
            'additional_fields' => []
        ];
        if (empty($actionLog->actionLogReports()->get()->toArray()))
            $actionLog->actionLogReports()->create($actionLogReportData);
        else
            $actionLog->actionLogReports()->update($actionLogReportData);

        if ($obj->queued == 0) {
            $actionLog->report_status = 'done';
        }

        if ($actionLog->report_mongo == null) {
            // generating random key with time stamp for mongo requestId
            $reqId = preg_replace('/\s+/', '_',  Carbon::now()->timestamp) . '_' . md5(uniqid(rand(), true));
            $reportData = [
                "requestId" => $reqId,
                "reportData" => $res
            ];
            //inserting data into mongo
            if (empty($this->mongo)) {
                $this->mongo = new MongoDBLib;
            }
            $this->mongo->collection($collection)->insertOne($reportData);
            $actionLog->report_mongo = $reqId;
        } else {
            $this->mongo->collection($collection)->update(["requestId" => $actionLog->report_mongo], ["reportData" => $res]);
        }

        $actionLog->save();
    }
}
