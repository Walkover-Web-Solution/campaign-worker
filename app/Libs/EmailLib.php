<?php

namespace App\Libs;
use Ixudra\Curl\Facades\Curl;

class EmailLib
{
    public function send($input)
    {
        $operation = 'email/send';
        return $this->makeAPICAll($operation,$input,'post');
    }

    public function getTemplate($templateId = '')
    {
        $operation = 'email/templates';
        if (!empty($templateId)) {
            $operation = 'email/templates/' . $templateId;
        }
        $operation = $operation . '?status_id=2'; // only fetch verified one
        // return $this->makeAPICAll($operation);
    }

    public function makeAPICAll($operation, $input = [], $method = 'get')
    {
        $company = request()->company;
        $jwt = array(
            'company' => array(
                'id' => $company->ref_id,
                'username' => $company->name,
                'email' => $company->email,
                ''
            ),
            'need_validation' => true
        );
        if (!empty(request()->user)) {
            $user = request()->user;
            $jwt['user'] = array(
                'id' => $user->ref_id,
                'username' => $user->name,
                'email' => $user->email
            );
            $jwt['need_validation'] = false;
        }
        if (request()->header('token')) {
            $jwt['need_validation'] = false; // run case handle,
        }
        $jwt['ip'] = request()->ip;

        $authorization = JWTEncode($jwt);

        $tempOption = 'TIMEOUT';
        $tempValue = 100;
        if ($method == 'get' && strpos($operation, 'email') === false) {
            $tempOption = 'POSTFIELDS';
            $tempValue = json_encode($input);
        }

        $host = env('EMAIL_HOST_URL');
        $endpoint = $host . $operation;

        $logData = array(
            'action' => 'api-call',
            'endpoint' => $endpoint,
            'payload' => json_encode($input),
            'method' => $method,
            'authorization' => $authorization,
            'decoded_authorization' => $jwt
        );

        $res = Curl::to($endpoint)
            ->withHeader('authorization: ' . $authorization)
            ->withOption($tempOption, $tempValue)
            ->withData($input)
            ->asJson()
            ->asJsonResponse()
            ->$method();

        $logData['response'] = json_encode($res);
        $logData = (object)$logData;
    }
}
