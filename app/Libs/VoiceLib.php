<?php

namespace App\Libs;

use Ixudra\Curl\Facades\Curl;

class VoiceLib
{
    public function send($input)
    {
        $operation = 'voice/send';
        return $this->makeAPICAll($operation, $input, 'post');
    }

    public function getTemplate($type, $templateId)
    {
        $input = array(
            "service" => "campaign",
            'type' => $type,
            'channel' => 'voice',
            'id' => $templateId
        );
        $operation = 'campaign/getTemplateDetails';

        return $this->makeAPICAll($operation, $input);
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

        // $key=config('services.msg91.jwt_secret');
        // $authorization=JWT::encode($jwt, $key, 'HS256');
        $authorization = JWTEncode($jwt);

        $tempOption = 'TIMEOUT';
        $tempValue = 100;
        if ($method == 'get' && strpos($operation, 'email') === false) {
            $tempOption = 'POSTFIELDS';
            $tempValue = json_encode($input);
        }

        $host = env('VOICE_HOST_URL');
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
