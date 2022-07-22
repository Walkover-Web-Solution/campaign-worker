<?php

namespace App\Services;

use App\Libs\MongoDBLib;
use App\Models\FlowAction;
use Carbon\Carbon;

/**
 * Class EmailService
 * @package App\Services
 */
class EmailService
{
    protected $mongo;
    public function __construct()
    {
        //
    }

    public function createRequestBody($data)
    {

        $mappedData = collect($data)->map(function ($item) {

            return array(
                "name" => empty($item->name) ? null : $item->name,
                "email" => empty($item->email) ? null : $item->email
            );
        })->toArray();
        return $mappedData;
    }

    public function getRequestBody(FlowAction $flowAction, $obj, $mongo_data, $templateVariables, $attachments, $reply_to)
    {
        $obj->values = [];
        collect($flowAction["configurations"])->map(function ($item) use ($obj) {
            if ($item->name != 'template')
                $obj->values[$item->name] = $item->value;
        });
        $recipients = collect($mongo_data['emails'])->map(function ($md) use ($obj, $templateVariables) {
            $cc = [];
            $bcc = [];

            if (!empty($md['cc'])) {
                $cc = $md['cc'];
            } else if (!empty($obj->values['cc'])) {
                $cc = stringToJson($obj->values['cc']);
            }
            if (!empty($md['bcc'])) {
                $bcc = $md['bcc'];
            } else if (!empty($obj->values['bcc'])) {
                $bcc = stringToJson($obj->values['bcc']);
            }
            $to = $md['to'];
            $obj->count += count($to);
            if (!empty($cc))
                $obj->count += count($cc);
            if (!empty($bcc))
                $obj->count += count($bcc);


            //filter out variables of this flowActions template
            $variables = collect($md['variables'])->map(function ($value, $key) use ($templateVariables) {
                if (in_array($key, $templateVariables)) {
                    return $value;
                }
            });
            $variables = array_filter($variables->toArray());

            $variables = collect($variables)->map(function ($var) {
                if (is_string($var)) {
                    return $var;
                } else {
                    if (empty($var->value)) {
                        return "";
                    } else {

                        return $var->value;
                    }
                }
            })->toArray();
            // $variables = array_intersect($variables, $md['variables']);

            $data = array(
                "to" => $md['to'],
                "cc" => $cc,
                "bcc" => $bcc,
                "variables" => $variables
            );
            return $data;
        })->toArray();

        $domain = empty($obj->values['domain']) ? $obj->values['parent_domain'] : $obj->values['domain'];
        $email = $obj->values['from_email'] . "@" . $domain;
        $from = [
            "name" => $obj->values['from_email_name'],
            "email" => $email
        ];
        $attachments = convertAttachments($attachments);
        return array(
            "recipients" => $recipients,
            "from" => json_decode(collect($from)),
            "template_id" => $flowAction->template->template_id,
            "domain" => $obj->values['parent_domain'],
            "attachments" => $attachments,
            "reply_to" => $reply_to,
            "node_id" => (string)$flowAction['id']
        );
    }

    public function storeReport($res, $actionLog, $collection)
    {
        $obj = new \stdClass();
        $obj->queued = 0;
        $obj->delivered = 0;
        $obj->failed = 0;

        if (!$res->hasError) {
            collect($res->data)->map(function ($item) use ($obj) {
                if ($item->event->title == 'Queued' || $item->event->title == 'Accepted') {
                    $obj->queued++;
                } else if ($item->event->title == 'Delivered' || $item->event->title == 'Opened' || $item->event->title == 'Unsubscribed' || $item->event->title == 'Clicked') {
                    $obj->delivered++;
                } else if ($item->event->title == 'Rejected' || $item->event->title == 'Bounced' || $item->event->title == 'Failed' || $item->event->title == 'Complaints') {
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

        if (empty($this->mongo)) {
            $this->mongo = new MongoDBLib;
        }

        if ($actionLog->report_mongo == null) {
            // generating random key with time stamp for mongo requestId
            $reqId = preg_replace('/\s+/', '_',  Carbon::now()->timestamp) . '_' . md5(uniqid(rand(), true));
            $reportData = [
                "requestId" => $reqId,
                "reportData" => $res
            ];
            $this->mongo->collection($collection)->insertOne($reportData);
            $actionLog->report_mongo = $reqId;
        } else {
            $this->mongo->collection($collection)->update(["requestId" => $actionLog->report_mongo], ["reportData" => $res]);
        }

        $actionLog->save();
    }
}
