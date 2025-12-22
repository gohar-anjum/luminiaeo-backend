<?php

namespace App\Exceptions;

use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Validation\ValidationException;
use Illuminate\Auth\AuthenticationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

class Handler extends ExceptionHandler
{
    protected $dontFlash = [
        'current_password',
        'password',
        'password_confirmation',
    ];

    public function register(): void
    {
        $this->renderable(function (Throwable $e, $request) {
            return $this->formatApiError($e, $request);
        });
    }

    protected function formatApiError(Throwable $e, $request)
    {
        if (! $request->is('api/*')) {
            return parent::render($request, $e);
        }

        $status = 500;
        $message = 'There was some error';
        $payload = [
            'status' => 500,
            'message' => $message,
            'response' => null,
        ];

        if ($e instanceof ValidationException) {
            $status = 422;
            $payload['status'] = 422;
            $payload['message'] = 'Validation Failed';
            $payload['response'] = ['errors' => $e->errors()];
        }

        elseif ($e instanceof AuthenticationException) {
            $status = 401;
            $payload['status'] = 401;
            $payload['message'] = 'Unauthenticated';
        }

        elseif ($e instanceof NotFoundHttpException) {
            $status = 404;
            $payload['status'] = 404;
            $payload['message'] = 'Resource not found';
        }

        elseif ($e instanceof \App\Exceptions\DataForSEOException ||
            $e instanceof \App\Exceptions\SerpException) {

            $status = $e->getStatusCode();
            $payload['status'] = $status;
            $payload['message'] = $e->getMessage();
        }
        elseif ($e instanceof \App\Exceptions\PbnDetectorException) {
            $status = $e->getCode() ?: 400;
            $payload['status'] = $status;
            $payload['message'] = $e->getMessage();
        }

        else {
            \Log::error('API Error (500)', [
                'exception' => get_class($e),
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
                'request_url' => $request->fullUrl(),
                'request_method' => $request->method(),
                'request_data' => $request->all(),
            ]);

            $payload['status'] = 500;
            $payload['message'] = 'There was some error';
            $payload['response'] = null;
        }

        return response()->json($payload, $status);
    }
}
