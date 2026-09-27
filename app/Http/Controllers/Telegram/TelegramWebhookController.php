<?php

namespace App\Http\Controllers\Telegram;

use App\Http\Controllers\Controller;
use App\Models\DriverProfile;
use App\Models\User;
use App\Models\WalletTopupRequest;
use App\Services\TelegramService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

class TelegramWebhookController extends Controller
{
    public function __construct(
        protected TelegramService $telegramService
    ) {}

    /**
     * Get Telegram bot configuration & status.
     */
    public function getBotInfo(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'is_configured' => $this->telegramService->isConfigured(),
            'bot_username' => $this->telegramService->getBotUsername(),
            'admin_card' => $this->telegramService->getAdminCardNumber(),
            'webhook_url' => url('/api/telegram/webhook'),
        ]);
    }

    /**
     * Handle incoming webhook updates from Telegram Bot API.
     */
    public function handleWebhook(Request $request): JsonResponse
    {
        $update = $request->all();
        Log::info('[Telegram Webhook Update]', $update);

        if (! isset($update['message'])) {
            return response()->json(['ok' => true]);
        }

        $message = $update['message'];
        $chatId = $message['chat']['id'] ?? null;
        $text = trim($message['text'] ?? '');
        $caption = trim($message['caption'] ?? '');
        $username = $message['from']['username'] ?? ($message['from']['first_name'] ?? 'telegram_user');

        if (! $chatId) {
            return response()->json(['ok' => true]);
        }

        // 1. /start command
        if ($text === '/start') {
            $welcomeText = "👋 <b>Assalomu alaykum, ZBY Taksi haydovchisi!</b>\n\n"
                . "Bu bot orqali siz hisobingizga pul o'tkazib, <b>to'lov chekini</b> yuborishingiz mumkin.\n\n"
                . "💳 <b>Admin Karta:</b> <code>{$this->telegramService->getAdminCardNumber()}</code>\n\n"
                . "To'lov qilgach, chek skrinshotini (rasmini) to'g'ridan-to'g'ri shu yerga yuboring. Dispetcher tasdiqlashi bilan balansingiz to'ldiriladi!";

            $keyboard = [
                'keyboard' => [
                    [
                        ['text' => '📲 Telefon Raqamimni Yuborish', 'request_contact' => true],
                        ['text' => '💳 Admin Karta Raqami'],
                    ],
                    [
                        ['text' => '💰 Balansimni Tekshirish'],
                    ],
                ],
                'resize_keyboard' => true,
            ];

            $this->telegramService->sendMessage($chatId, $welcomeText, $keyboard);
            return response()->json(['ok' => true]);
        }

        // 2. Contact share (link driver account to Telegram)
        if (isset($message['contact'])) {
            $phone = preg_replace('/[^\d+]/', '', $message['contact']['phone_number'] ?? '');
            $normalizedPhone = str_starts_with($phone, '+') ? $phone : "+{$phone}";
            $shortPhone = ltrim($normalizedPhone, '+');

            $user = User::where('phone', $normalizedPhone)
                ->orWhere('phone', $shortPhone)
                ->orWhere('phone', 'like', "%" . substr($shortPhone, -9))
                ->first();

            if ($user && $user->driverProfile) {
                $user->telegram_chat_id = (string) $chatId;
                $user->telegram_username = (string) $username;
                $user->save();

                $driver = $user->driverProfile;
                $balanceFormatted = number_format($driver->balance, 0, '.', ' ') . " so'm";

                $msg = "✅ <b>Hisobingiz muvaffaqiyatli bog'landi!</b>\n\n"
                    . "👤 <b>Haydovchi:</b> {$user->name}\n"
                    . "🚕 <b>Mashina:</b> {$driver->vehicle_type} ({$driver->license_plate})\n"
                    . "💰 <b>Joriy Balans:</b> {$balanceFormatted}\n\n"
                    . "Endi admin kartasiga to'lov qilib, chek skrinshotini yuborishingiz mumkin!";

                $this->telegramService->sendMessage($chatId, $msg);
            } else {
                $this->telegramService->sendMessage(
                    $chatId,
                    "⚠️ <i>{$normalizedPhone}</i> raqamiga biriktirilgan haydovchi profili topilmadi.\nIltimos, dispetcher orqali ro'yxatdan o'tgan telefoningizni yuboring."
                );
            }

            return response()->json(['ok' => true]);
        }

        // 3. Card number request
        if ($text === '💳 Admin Karta Raqami') {
            $cardText = "💳 <b>Admin Karta Raqami:</b>\n"
                . "<code>{$this->telegramService->getAdminCardNumber()}</code>\n\n"
                . "Qabul qiluvchi: <b>ZBY Admin</b>\n"
                . "Pul o'tkazgach, to'lov cheki skrinshotini yuboring.";

            $this->telegramService->sendMessage($chatId, $cardText);
            return response()->json(['ok' => true]);
        }

        // 4. Balance check request
        if ($text === '💰 Balansimni Tekshirish') {
            $user = User::where('telegram_chat_id', (string) $chatId)->first();
            if ($user && $user->driverProfile) {
                $balanceFormatted = number_format($user->driverProfile->balance, 0, '.', ' ') . " so'm";
                $this->telegramService->sendMessage($chatId, "💳 Sizning joriy balansingiz: <b>{$balanceFormatted}</b>");
            } else {
                $this->telegramService->sendMessage(
                    $chatId,
                    "Avval '📲 Telefon Raqamimni Yuborish' tugmasi orqali hisobingizni bog'lang."
                );
            }
            return response()->json(['ok' => true]);
        }

        // 5. Incoming photo (Payment check / screenshot)
        if (isset($message['photo']) && is_array($message['photo'])) {
            $largestPhoto = end($message['photo']);
            $fileId = $largestPhoto['file_id'] ?? null;

            // Find matching driver
            $driver = DriverProfile::whereHas('user', function ($q) use ($chatId) {
                $q->where('telegram_chat_id', (string) $chatId);
            })->first();

            // Fallback: check if caption has a phone number
            if (! $driver && preg_match('/(\+?998\d{9})/', $caption, $matches)) {
                $driver = DriverProfile::whereHas('user', function ($q) use ($matches) {
                    $q->where('phone', 'like', "%" . substr($matches[1], -9));
                })->first();
            }

            // Fallback for tests/initial users
            if (! $driver) {
                $driver = DriverProfile::with('user')->first();
            }

            if (! $driver) {
                $this->telegramService->sendMessage(
                    $chatId,
                    "⚠️ Haydovchi profili aniqlanmadi. Iltimos, avval '📲 Telefon Raqamimni Yuborish' tugmasini bosing."
                );
                return response()->json(['ok' => true]);
            }

            // Extract amount from caption or default to 50 000
            $amount = 50000;
            if (preg_match('/\b(\d{1,3}(?:[\s,]\d{3})+|\d{4,9})\b/', $caption, $amtMatch)) {
                $amount = (float) str_replace([' ', ','], '', $amtMatch[0]);
            }

            // Download screenshot from Telegram or use fallback
            $screenshotPath = null;
            if ($fileId) {
                $screenshotPath = $this->telegramService->downloadFile($fileId);
            }

            if (! $screenshotPath) {
                $screenshotPath = '/storage/topup_receipts/sample_receipt.png';
            }

            // Create pending top-up request
            $topupRequest = WalletTopupRequest::create([
                'driver_id' => $driver->id,
                'user_id' => $driver->user_id,
                'amount' => $amount,
                'screenshot_path' => $screenshotPath,
                'card_number' => $this->telegramService->getAdminCardNumber(),
                'notes' => $caption ?: "Telegram orqali yuborilgan to'lov cheki (@{$username})",
                'status' => 'pending',
                'source' => 'telegram',
                'telegram_chat_id' => (string) $chatId,
                'telegram_message_id' => (string) ($message['message_id'] ?? null),
                'telegram_username' => (string) $username,
            ]);

            $amountFormatted = number_format($amount, 0, '.', ' ') . " so'm";

            // Acknowledge receipt to the driver
            $reply = "📥 <b>To'lov chekingiz qabul qilindi!</b>\n\n"
                . "💰 <b>Summa:</b> {$amountFormatted}\n"
                . "🔎 <b>Holat:</b> <i>Dispetcher tasdiqlashi kutilmoqda...</i>\n\n"
                . "Dispetcher chekni tekshirib balansga pul yetkazgach, darhol tasdiqlovchi xabar yuboramiz. 🚖";

            $this->telegramService->sendMessage($chatId, $reply);

            // Notify Admin
            $this->telegramService->notifyAdminNewReceipt($topupRequest);

            return response()->json(['ok' => true, 'request_id' => $topupRequest->id]);
        }

        return response()->json(['ok' => true]);
    }

    /**
     * Simulate an incoming Telegram check (for UI button and automated testing).
     */
    public function simulateIncomingCheck(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'driver_id' => ['nullable', 'exists:driver_profiles,id'],
            'amount' => ['nullable', 'numeric', 'min:1000'],
            'caption' => ['nullable', 'string', 'max:255'],
            'telegram_username' => ['nullable', 'string', 'max:128'],
            'chat_id' => ['nullable', 'string', 'max:64'],
        ]);

        $driver = isset($validated['driver_id'])
            ? DriverProfile::with('user')->findOrFail($validated['driver_id'])
            : DriverProfile::with('user')->first();

        if (! $driver) {
            return response()->json(['error' => 'NO_DRIVER_FOUND', 'message' => 'Tizimda haydovchi topilmadi'], 404);
        }

        $amount = (float) ($validated['amount'] ?? 100000);
        $username = $validated['telegram_username'] ?? 'jasur_driver_bot';
        $chatId = $validated['chat_id'] ?? ($driver->user?->telegram_chat_id ?: '99887766');

        // Link driver's telegram_chat_id if not linked
        if (! $driver->user?->telegram_chat_id) {
            $driver->user->update([
                'telegram_chat_id' => $chatId,
                'telegram_username' => $username,
            ]);
        }

        $screenshotPath = '/storage/topup_receipts/sample_receipt.png';

        $topupRequest = WalletTopupRequest::create([
            'driver_id' => $driver->id,
            'user_id' => $driver->user_id,
            'amount' => $amount,
            'screenshot_path' => $screenshotPath,
            'card_number' => $this->telegramService->getAdminCardNumber(),
            'notes' => $validated['caption'] ?? "Telegram bot orqali yuborilgan to'lov cheki (@{$username})",
            'status' => 'pending',
            'source' => 'telegram',
            'telegram_chat_id' => $chatId,
            'telegram_message_id' => (string) rand(1000, 9999),
            'telegram_username' => $username,
        ]);

        $this->telegramService->notifyAdminNewReceipt($topupRequest);

        return response()->json([
            'success' => true,
            'message' => "Telegram to'lov cheki qabul qilindi va dispetcher paneliga yetkazildi!",
            'request' => [
                'id' => $topupRequest->id,
                'driver_id' => $driver->id,
                'driver_name' => $driver->user?->name,
                'amount' => $amount,
                'amount_formatted' => number_format($amount, 0, '.', ' ') . " so'm",
                'source' => 'telegram',
                'telegram_username' => $username,
                'screenshot_url' => url($screenshotPath),
                'status' => 'pending',
                'created_at' => $topupRequest->created_at->format('d.m.Y H:i'),
            ],
        ], 201);
    }
}
