<?php

namespace App\Services;

class ApiResponseModifier
{

    private $message = "success";
    private mixed $responseData = null;
    private int $responseCode = 200;

    public function setMessage($message)
    {
        $this->message = $message;
        return $this;
    }

    public function setResponseCode(int $code)
    {
        $this->responseCode = $code;
        return $this;
    }

    public function setData($data)
    {
        $this->responseData = $data;
        return $this;
    }

    public function response()
    {
        return response()->json([
            'status' => $this->responseCode,
            'message' => $this->message,
            'response' => $this->responseData
        ], $this->responseCode);
    }
}
