<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Response;
use App\Models\Payment;
use RuntimeException;

final class PaymentController
{
    public static function record(): void
    {
        $user = Auth::user();
        if (!in_array($user['role'] ?? '', ['ADMIN', 'FINANCE_OFFICER'], true)) {
            Response::json(['error' => 'Only ADMIN or FINANCE_OFFICER can record payments'], 403);
        }

        $body = json_decode(file_get_contents('php://input'), true);
        $orderId = filter_var($body['order_id'] ?? null, FILTER_VALIDATE_INT);
        $amount = filter_var($body['amount'] ?? null, FILTER_VALIDATE_FLOAT);
        $status = strtoupper((string)($body['status'] ?? 'PAID'));
        if (!$orderId || $amount === false || $amount <= 0 || !in_array($status, ['PENDING', 'PAID', 'REFUSED'], true)) {
            Response::json(['error' => 'order_id, amount and valid status are required'], 400);
        }

        try {
            Response::json(['data' => Payment::record((int)$orderId, (float)$amount, $status, (int)$user['sub'])], 201);
        } catch (RuntimeException $exception) {
            Response::json(['error' => $exception->getMessage()], 404);
        }
    }

    public static function index(): void
    {
        $user = Auth::user();
        if (!in_array($user['role'] ?? '', ['ADMIN', 'FINANCE_OFFICER'], true)) {
            Response::json(['error' => 'Only ADMIN or FINANCE_OFFICER can list payments'], 403);
        }
        Response::json(['data' => Payment::all()]);
    }
}
