<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\User;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\URL;

class EmailVerificationLink extends Notification
{
    /** @return list<string> */
    public function via(User $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(User $notifiable): MailMessage
    {
        $signedUrl = URL::temporarySignedRoute('verification.verify', now()->addHour(), [
            'id' => $notifiable->id,
            'hash' => sha1($notifiable->email),
        ], absolute: false);
        $url = rtrim((string) config('account.frontend_url'), '/').'/verify-email?'.http_build_query([
            'verification_url' => $signedUrl,
        ]);

        return (new MailMessage)
            ->subject('Verify your NEPSE Analyzer email')
            ->line('Confirm that this email address belongs to your account.')
            ->action('Verify email', $url)
            ->line('Sign in to the matching account to continue. This link expires in 60 minutes.')
            ->line('If you did not request this, you can ignore this message.');
    }
}
