<?php

namespace App\Exceptions;

use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Validation\ValidationException;
use Illuminate\Auth\AuthenticationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

class Handler extends ExceptionHandler
{
    /**
     * Inputs never flashed for validation exceptions.
     *
     * @var array<int, string>
     */
    protected $dontFlash = [
        'current_password',
        'password',
        'password_confirmation',
    ];

    /**
     * Register the exception handling callbacks for the application.
     */
    public function register(): void
    {
        // API Exception Renderer
        $this->renderable(function (Throwable $e, $request) {
            return $this->formatApiError($e, $request);
        });
    }

    /**
     * Format all API errors into a consistent structure.
     */
    protected function formatApiError(Throwable $e, $request)
    {
        // Only format API routes
        if (! $request->is('api/*')) {
            return parent::render($request, $e);
        }

        $status = 500;
        $payload = [
            'success' => false,
            'status'  => 500,
            'message' => 'Internal Server Error',
        ];

        // Validation Errors
        if ($e instanceof ValidationException) {
            $status = 422;
            $payload['status']  = 422;
            $payload['message'] = 'Validation Failed';
            $payload['errors']  = $e->errors();
        }

        // Unauthenticated
        elseif ($e instanceof AuthenticationException) {
            $status = 401;
            $payload['status']  = 401;
            $payload['message'] = 'Unauthenticated.';
        }

        // 404
        elseif ($e instanceof NotFoundHttpException) {
            $status = 404;
            $payload['status']  = 404;
            $payload['message'] = 'Resource not found.';
        }

        // Custom Domain Exceptions (your own classes)
        elseif ($e instanceof \App\Exceptions\DataForSEOException ||
            $e instanceof \App\Exceptions\PbnDetectorException) {

            $status = $e->status ?? 400;
            $payload['status']  = $status;
            $payload['message'] = $e->getMessage();
        }

        // System / Unknown Exception
        else {
            // Log it for internal tracing
            \Log::error($e);
        }

        // Debug info (only when APP_DEBUG=true)
        if (config('app.debug')) {
            $payload['debug'] = [
                'exception' => get_class($e),
                'file'      => $e->getFile(),
                'line'      => $e->getLine(),
                'trace'     => collect($e->getTrace())->take(5),
            ];
        }

        return response()->json($payload, $status);
    }
}
