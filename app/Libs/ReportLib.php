<?php

namespace App\Libs;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Support\Facades\Log;
use Ixudra\Curl\Facades\Curl;

class ReportLib
{
    public function getEmailReport($input)
    {
        $endpoint = 'https://test.mailer91.com/api/reports-by-request-id';
        // $endpoint = 'https://www.mailer91.com/docs/#reports-GETapi-reports-by-request-id';
        return $this->makeAPICAll($endpoint, $input, 'get');
    }

    public function getSmsReport($input)
    {
        $endpoint = '';
        return $this->makeAPICAll($endpoint, $input, 'get');
    }

    public function makeAPICAll($endpoint, $input = [], $method = 'get')
    {
        $authorization = config('msg91.jwt_token');
        $jwt = JWTDecode($authorization);
        Log::info('hello');
        $res = Curl::to($endpoint)
            ->withHeader('authorization: ' . $authorization)
            ->withData($input)
            ->asJson()
            ->asJsonResponse()
            ->get();
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
