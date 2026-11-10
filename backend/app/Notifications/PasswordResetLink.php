<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\User;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PasswordResetLink extends Notification
{
    public function __construct(public readonly string $token) {}

    /** @return list<string> */
    public function via(User $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(User $notifiable): MailMessage
    {
        $url = rtrim((string) config('account.frontend_url'), '/').'/reset-password?'.http_build_query([
            'token' => $this->token,
            'email' => $notifiable->getEmailForPasswordReset(),
        ]);

        return (new MailMessage)
            ->subject('Reset your NEPSE Analyzer password')
            ->line('A password reset was requested for your account.')
            ->action('Reset password', $url)
            ->line('This link expires in '.config('auth.passwords.users.expire').' minutes.')
            ->line('If you did not request this, you can ignore this message.');
    }
}
