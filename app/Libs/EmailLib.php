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
        $operation = 'https://test.mailer91.com/api/reports-by-request-id';
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
            ->withData($input)
            ->asJson()
            ->asJsonResponse()
            ->$method();

        $logData = array(
            "endpoint" => $endpoint,
            "authorization" => $authorization,
            "res" => $res,
            "request"=>$input,
        );
        logTest("email response", $logData);

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
