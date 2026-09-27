<?php

namespace App\Services;

use App\Models\WalletTopupRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class TelegramService
{
    protected ?string $botToken;
    protected ?string $adminChatId;
    protected string $botUsername;
    protected string $adminCardNumber;

    public function __construct()
    {
        $this->botToken = env('TELEGRAM_BOT_TOKEN') ?: null;
        $this->adminChatId = env('TELEGRAM_ADMIN_CHAT_ID') ?: null;
        $this->botUsername = env('TELEGRAM_BOT_USERNAME') ?: 'ZbyTaxiBot';
        $this->adminCardNumber = env('TELEGRAM_ADMIN_CARD') ?: '8600 1234 5678 9012';
    }

    public function isConfigured(): bool
    {
        return ! empty($this->botToken);
    }

    public function getBotUsername(): string
    {
        return $this->botUsername;
    }

    public function getAdminCardNumber(): string
    {
        return $this->adminCardNumber;
    }

    /**
     * Send text message via Telegram Bot API.
     */
    public function sendMessage(string|int $chatId, string $text, ?array $keyboard = null, string $parseMode = 'HTML'): bool
    {
        if (! $this->isConfigured()) {
            Log::info("[TelegramService MOCK] sendMessage to {$chatId}: {$text}");
            return true;
        }

        try {
            $payload = [
                'chat_id' => $chatId,
                'text' => $text,
                'parse_mode' => $parseMode,
            ];

            if ($keyboard) {
                $payload['reply_markup'] = json_encode($keyboard);
            }

            $response = Http::timeout(10)->post("https://api.telegram.org/bot{$this->botToken}/sendMessage", $payload);

            if (! $response->successful()) {
                Log::warning("[TelegramService] sendMessage failed: " . $response->body());
                return false;
            }

            return true;
        } catch (\Throwable $e) {
            Log::error("[TelegramService] Error sending message: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Send photo with caption via Telegram Bot API.
     */
    public function sendPhoto(string|int $chatId, string $photoPath, string $caption = '', ?array $keyboard = null): bool
    {
        if (! $this->isConfigured()) {
            Log::info("[TelegramService MOCK] sendPhoto to {$chatId}, file: {$photoPath}, caption: {$caption}");
            return true;
        }

        try {
            $url = "https://api.telegram.org/bot{$this->botToken}/sendPhoto";
            $req = Http::timeout(15);

            $data = [
                'chat_id' => $chatId,
                'caption' => $caption,
                'parse_mode' => 'HTML',
            ];

            if ($keyboard) {
                $data['reply_markup'] = json_encode($keyboard);
            }

            if (file_exists($photoPath)) {
                $response = $req->attach('photo', file_get_contents($photoPath), basename($photoPath))->post($url, $data);
            } else {
                $data['photo'] = $photoPath;
                $response = $req->post($url, $data);
            }

            return $response->successful();
        } catch (\Throwable $e) {
            Log::error("[TelegramService] Error sending photo: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Download a file from Telegram servers and store it in public storage.
     */
    public function downloadFile(string $fileId, string $destinationSubdir = 'topup_receipts'): ?string
    {
        if (! $this->isConfigured()) {
            return null;
        }

        try {
            $infoRes = Http::timeout(10)->get("https://api.telegram.org/bot{$this->botToken}/getFile", [
                'file_id' => $fileId,
            ]);

            if (! $infoRes->successful()) {
                return null;
            }

            $filePath = $infoRes->json('result.file_path');
            if (! $filePath) {
                return null;
            }

            $fileContent = Http::timeout(20)->get("https://api.telegram.org/file/bot{$this->botToken}/{$filePath}")->body();

            $ext = pathinfo($filePath, PATHINFO_EXTENSION) ?: 'jpg';
            $fileName = 'telegram_receipt_' . time() . '_' . uniqid() . '.' . $ext;
            $saved = Storage::disk('public')->put("{$destinationSubdir}/{$fileName}", $fileContent);

            if ($saved) {
                return "/storage/{$destinationSubdir}/{$fileName}";
            }

            return null;
        } catch (\Throwable $e) {
            Log::error("[TelegramService] Failed to download file from Telegram: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Notify Admin / Dispatcher group about new receipt.
     */
    public function notifyAdminNewReceipt(WalletTopupRequest $request): void
    {
        if (! $this->adminChatId) {
            return;
        }

        $driverName = $request->driver?->user?->name ?? 'Haydovchi';
        $driverPhone = $request->driver?->user?->phone ?? '—';
        $amountFormatted = number_format($request->amount, 0, '.', ' ') . " so'm";
        $source = strtoupper($request->source ?: 'TELEGRAM');

        $text = "🚨 <b>YANGI TO'LOV CHEKI ({$source})</b>\n\n"
            . "👤 <b>Haydovchi:</b> {$driverName} ({$driverPhone})\n"
            . "💰 <b>Summa:</b> {$amountFormatted}\n"
            . "💳 <b>Karta:</b> {$request->card_number}\n"
            . "💬 <b>Izoh:</b> " . ($request->notes ?: 'Izohsiz') . "\n"
            . "⏱ <b>Vaqt:</b> " . now()->format('d.m.Y H:i') . "\n\n"
            . "🔎 <i>Dispetcher panelida tasdiqlash kutilmoqda.</i>";

        $this->sendMessage($this->adminChatId, $text);
    }

    /**
     * Notify Driver via Telegram that their top-up has been approved and delivered.
     */
    public function notifyDriverApproved(WalletTopupRequest $request, float $newBalance): void
    {
        $chatId = $request->telegram_chat_id ?? $request->driver?->user?->telegram_chat_id;
        if (! $chatId) {
            Log::info("[TelegramService] No telegram_chat_id for driver #{$request->driver_id}, skipping Telegram notification.");
            return;
        }

        $amountFormatted = number_format($request->amount, 0, '.', ' ') . " so'm";
        $balanceFormatted = number_format($newBalance, 0, '.', ' ') . " so'm";
        $dispatcherName = $request->processedBy?->name ?? 'Dispetcher';

        $text = "✅ <b>TO'LOVINGIZ TASDIQLANDI!</b>\n\n"
            . "💰 <b>Yetkazilgan summa:</b> +{$amountFormatted}\n"
            . "💳 <b>Yangi balansingiz:</b> <b>{$balanceFormatted}</b>\n"
            . "👨‍💼 <b>Dispetcher:</b> {$dispatcherName}\n"
            . "⏱ <b>Vaqt:</b> " . now()->format('d.m.Y H:i') . "\n\n"
            . "<i>ZBY Taxi bilan hamkorlik uchun rahmat! Xavfsiz safarlar tilaymiz! 🚖</i>";

        $this->sendMessage($chatId, $text);
    }

    /**
     * Notify Driver via Telegram that their receipt was rejected.
     */
    public function notifyDriverRejected(WalletTopupRequest $request, string $reason): void
    {
        $chatId = $request->telegram_chat_id ?? $request->driver?->user?->telegram_chat_id;
        if (! $chatId) {
            return;
        }

        $amountFormatted = number_format($request->amount, 0, '.', ' ') . " so'm";

        $text = "❌ <b>TO'LOV CHEKI RAD ETILDI</b>\n\n"
            . "💰 <b>Summa:</b> {$amountFormatted}\n"
            . "⚠️ <b>Rad etish sababi:</b> <i>{$reason}</i>\n"
            . "⏱ <b>Vaqt:</b> " . now()->format('d.m.Y H:i') . "\n\n"
            . "<i>Iltimos, o'tkazma summasini va chekni qayta tekshirib yuboring yoki dispetcher markazimiz bilan bog'laning.</i>";

        $this->sendMessage($chatId, $text);
    }
}
