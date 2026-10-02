<?php

declare(strict_types=1);

namespace App\Modules\Administration\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class MemberPasswordReset extends Notification
{
    public function __construct(private readonly string $token) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $url = rtrim((string) config('app.frontend_url'), '/').'/reset-password?'.http_build_query([
            'token' => $this->token,
            'email' => $notifiable->email,
        ]);

        return (new MailMessage)
            ->subject('Reset your ERP password')
            ->greeting("Hello {$notifiable->name},")
            ->line('A password reset was requested for your ERP account.')
            ->action('Set a new password', $url)
            ->line('If you did not request this, you can ignore the email.');
    }
}
