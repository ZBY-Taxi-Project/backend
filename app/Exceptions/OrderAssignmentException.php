<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class OrderAssignmentException extends Exception
{
    protected string $errorCode;

    public function __construct(string $message, string $errorCode = 'ASSIGNMENT_ERROR', int $code = Response::HTTP_UNPROCESSABLE_ENTITY)
    {
        $this->errorCode = $errorCode;
        parent::__construct($message, $code);
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'error' => $this->errorCode,
            'message' => $this->getMessage(),
        ], $this->getCode() ?: Response::HTTP_UNPROCESSABLE_ENTITY);
    }
}
