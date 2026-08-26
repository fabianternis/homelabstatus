<?php

declare(strict_types=1);

namespace App\Service;

use App\Enum\ServiceStatus;
use App\Model\Monitor;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class NotificationService
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        #[Autowire(env: 'default::APP_NAME')]
        private readonly ?string $appName = 'Homelab Status',
        #[Autowire(env: 'default::DISCORD_WEBHOOK_URL')]
        private readonly ?string $discordWebhookUrl = null,
        #[Autowire(env: 'default::TELEGRAM_BOT_TOKEN')]
        private readonly ?string $telegramBotToken = null,
        #[Autowire(env: 'default::TELEGRAM_CHAT_ID')]
        private readonly ?string $telegramChatId = null,
        #[Autowire(env: 'default::GENERIC_WEBHOOK_URL')]
        private readonly ?string $genericWebhookUrl = null
    ) {}

    public function notifyStatusChange(Monitor $monitor, ServiceStatus $previousStatus, ServiceStatus $newStatus, ?string $reason = null): void
    {
        $app = $this->appName ?: 'Homelab Status';
        $title = "🚨 [{$app}] {$monitor->name} is now " . strtoupper($newStatus->value);
        $message = "Service '{$monitor->name}' changed status from {$previousStatus->value} to {$newStatus->value}.\nTarget: {$monitor->target}";
        if ($reason) {
            $message .= "\nReason: {$reason}";
        }

        $this->sendDiscord($title, $message, $newStatus);
        $this->sendTelegram("<b>{$title}</b>\n\n{$message}");
        $this->sendGenericWebhook([
            'event' => 'status_change',
            'monitor' => $monitor->toArray(),
            'previous_status' => $previousStatus->value,
            'new_status' => $newStatus->value,
            'reason' => $reason,
            'timestamp' => gmdate('c'),
        ]);
    }

    public function sendDiscord(string $title, string $message, ServiceStatus $status): void
    {
        if (empty($this->discordWebhookUrl)) {
            return;
        }

        $color = match ($status) {
            ServiceStatus::ONLINE => 0x10b981,
            ServiceStatus::DEGRADED => 0xf59e0b,
            ServiceStatus::OFFLINE => 0xef4444,
            default => 0x6b7280,
        };

        try {
            $this->httpClient->request('POST', $this->discordWebhookUrl, [
                'json' => [
                    'embeds' => [
                        [
                            'title' => $title,
                            'description' => $message,
                            'color' => $color,
                            'timestamp' => gmdate('c'),
                            'footer' => [
                                'text' => 'HomelabStatus Monitor'
                            ]
                        ]
                    ]
                ],
                'timeout' => 5,
            ]);
        } catch (\Throwable) {
            // Suppress notification delivery failures
        }
    }

    public function sendTelegram(string $htmlText): void
    {
        if (empty($this->telegramBotToken) || empty($this->telegramChatId)) {
            return;
        }

        $url = "https://api.telegram.org/bot{$this->telegramBotToken}/sendMessage";
        try {
            $this->httpClient->request('POST', $url, [
                'json' => [
                    'chat_id' => $this->telegramChatId,
                    'text' => $htmlText,
                    'parse_mode' => 'HTML',
                    'disable_web_page_preview' => true,
                ],
                'timeout' => 5,
            ]);
        } catch (\Throwable) {
            // Suppress notification delivery failures
        }
    }

    public function sendGenericWebhook(array $data): void
    {
        if (empty($this->genericWebhookUrl)) {
            return;
        }

        try {
            $this->httpClient->request('POST', $this->genericWebhookUrl, [
                'json' => $data,
                'timeout' => 5,
            ]);
        } catch (\Throwable) {
            // Suppress notification delivery failures
        }
    }
}
