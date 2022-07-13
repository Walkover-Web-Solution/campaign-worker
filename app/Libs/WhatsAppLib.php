<?php

namespace App\Libs;

use Ixudra\Curl\Facades\Curl;

class WhatsAppLib
{
    public function send($input)
    {
        $operation = 'whatsapp-outbound-message/bulk/';
        return $this->makeAPICAll($operation, $input, 'post');
    }

    public function getTemplate($type, $templateId)
    {
        $input = array(
            "service" => "campaign",
            'type' => $type,
            'channel' => 'whatsapp',
            'id' => $templateId
        );
        $operation = 'campaign/getTemplateDetails';

        return $this->makeAPICAll($operation, $input);
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

        $host = env('WHATSAPP_HOST_URL');
        $endpoint = $host . $operation;

        $jwt = JWTDecode($authorization);

        $res = Curl::to($endpoint)
            ->withHeader('Jwt: ' . $authorization)
            ->withData($input)
            ->asJson()
            ->asJsonResponse()
            ->$method();

        $logData = array(
            "endpoint" => $endpoint,
            "authorization" => $authorization,
            "res" => $res,
        );
        logTest("Whatsapp response", $logData);

        return $res;
    }
}
