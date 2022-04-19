<?php

namespace App\Libs;

use Ixudra\Curl\Facades\Curl;

class SmsLib
{
    public function send($input)
    {
        $operation = 'campaign/send?action=sendsms';
        return $this->makeAPICAll($operation, $input, 'post');
    }

    public function getTemplate($type, $templateId)
    {
        $input = array(
            "service" => "campaign",
            'type' => $type,
            'channel' => 'sms',
            'id' => $templateId
        );
        $operation = 'campaign/getTemplateDetails';

        return $this->makeAPICAll($operation, $input);
    }

    public function getReports($input)
    {
        $operation = 'test.msg91.com/api/getDlrReport.php?reqId=' . $input;
        return $this->makeAPICAll($operation, $input, 'get');
    }

    public function makeAPICAll($operation, $input = [], $method = 'get')
    {
        $authorization = config('msg91.jwt_token');
        $tempOption = 'TIMEOUT';
        $tempValue = 100;
        if ($method == 'get' && strpos($operation, 'email') === false) {
            $tempOption = 'POSTFIELDS';
            $tempValue = json_encode($input);
        }

        if ($operation == 'campaign/send?action=sendsms') {
            $host = env('SMS_HOST_URL');
            $endpoint = $host . $operation;
        } else {
            $endpoint = $operation;
        }

        $jwt = JWTDecode($authorization);

        $res = Curl::to($endpoint)
            ->withHeader('authorization: ' . $authorization)
            ->withData($input)
            ->asJson()
            ->asJsonResponse()
            ->post();

        $logData = array(
            "endpoint" => $endpoint,
            "authorization" => $authorization,
            "res" => $res,
        );
        logTest("sms response", $logData);
        return $res;
    }
}
