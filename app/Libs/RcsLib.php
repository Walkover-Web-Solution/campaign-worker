<?php

namespace App\Libs;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Ixudra\Curl\Facades\Curl;

class RcsLib
{
    public function send($input)
    {
        $operation = 'send-rcs-message/bulk/';
        return $this->makeAPICAll($operation, $input, 'post');
    }

    public function getTemplate($templateId = '')
    {
        $operation = 'templates';
        if (!empty($templateId)) {
            $operation = 'templates/' . $templateId;
        }
        $operation = $operation . '?status_id=2'; // only fetch verified one
        return $this->makeAPICAll($operation);
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

        $host = env('RCS_HOST_URL');
        $endpoint = $host . $operation;

        $jwt = JWTDecode($authorization);

        $res = Curl::to($endpoint)
            ->withHeader('Jwt: ' . $authorization)
            ->withHeader('Accept: application/json')
            ->withData($input)
            ->asJson()
            ->asJsonResponse()
            ->$method();

        printLog("before logData");
        $logData = array(
            "endpoint" => $endpoint,
            "authorization" => $authorization,
            "res" => $res,
            "request" => $input,
        );
        printLog("after logData");
        logTest("rcs response", $logData);
        return $res;
    }
}
