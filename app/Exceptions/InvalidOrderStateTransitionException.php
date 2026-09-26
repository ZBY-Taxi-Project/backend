<?php

namespace App\Exceptions;

use App\Enums\OrderStatus;
use Exception;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class InvalidOrderStateTransitionException extends Exception
{
    public function __construct(OrderStatus $from, OrderStatus $to, string $message = '')
    {
        $defaultMessage = "Illegal order state transition from '{$from->value}' to '{$to->value}'.";
        parent::__construct($message ?: $defaultMessage);
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'error' => 'INVALID_STATE_TRANSITION',
            'message' => $this->getMessage(),
        ], Response::HTTP_UNPROCESSABLE_ENTITY);
    }
}
