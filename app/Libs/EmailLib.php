<?php

namespace App\Libs;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Ixudra\Curl\Facades\Curl;

class EmailLib
{
    public function send($input)
    {
        $operation = 'send';
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

    public function getReports($input)
    {
        $operation = 'https://stage.mailer91.com/api/reports-by-request-id';
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

        if ($operation == 'send') {
            $host = env('EMAIL_HOST_URL');
            $endpoint = $host . $operation;
        } else {
            $endpoint = $operation;
        }

        $jwt = JWTDecode($authorization);

        $res = Curl::to($endpoint)
            ->withHeader('authorization: ' . $authorization)
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
        logTest("email response", $logData);
        return $res;
    }
}
