<?php

declare(strict_types=1);

namespace App\Modules\Administration\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class MemberInvitation extends Notification
{
    use Queueable;

    public function __construct(private readonly string $token) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $url = rtrim((string) config('app.frontend_url'), '/').'/accept-invitation?'.http_build_query([
            'token' => $this->token,
            'email' => $notifiable->email,
        ]);

        return (new MailMessage)
            ->subject('You have been invited to ERP')
            ->greeting("Hello {$notifiable->name},")
            ->line('An administrator invited you to join their ERP company.')
            ->action('Set password and activate account', $url)
            ->line('This link expires and can be used only once.');
    }
}
