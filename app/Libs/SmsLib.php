<?php

namespace App\Libs;

use Ixudra\Curl\Facades\Curl;

class SmsLib
{
    public function send($input)
    {
        $operation = 'flow';
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

    public function makeAPICAll($operation, $input = [], $method = 'get')
    {
        $authorization = config('msg91.jwt_token');
        $tempOption = 'TIMEOUT';
        $tempValue = 100;
        if ($method == 'get' && strpos($operation, 'email') === false) {
            $tempOption = 'POSTFIELDS';
            $tempValue = json_encode($input);
        }

        $host = env('SMS_HOST_URL');
        $endpoint = $host . $operation;

        $jwt = JWTDecode($authorization);

         $res = Curl::to($endpoint)
            ->withHeader('authorization: ' . $authorization)
            ->withData($input)
            ->asJson()
            ->post();


        if (isset($res->hasError) && !empty($res->hasError)) {
            $errorMsg = '';
            collect($res->errors)->each(function ($error, $key) use (&$errorMsg) {
                if (is_array($error)) {
                    $error = $error[0];
                }
                if (empty($errorMsg)) {
                    if (empty($key) || is_int($key)) {
                        $errorMsg = $error;
                    } else {
                        $errorMsg = $key . ':' . $error;
                    }
                } else {
                    if (is_int($key)) {
                        $errorMsg = $error;
                    } else {
                        $errorMsg = $errorMsg . ",$key:" . $error;
                    }
                }
            });
            throw new \Exception($errorMsg, 1);
        }


        if (isset($res->type) && $res->type == 'error') {
            if (isset($res->message)) {
                throw new \Exception($res->message);
            }
            if (isset($res->msg)) {
                throw new \Exception($res->msg);
            }
            throw new \Exception(json_encode($res));
        }


        if (isset($res->status) && $res->status == 'fail') {
            $errors = [];
            if (is_object($res->errors)) {
                foreach ($res->errors as $error) {
                    $errors[] = $error[0];
                }
            } else {
                $errors = $res->errors;
            }

            throw new \Exception(implode(',', $errors), 1);
        }

        if (isset($res->msgType) && $res->msgType == 'error') {
            throw new \Exception($res->msg, 1);
        }

        return $res;
    }
}
