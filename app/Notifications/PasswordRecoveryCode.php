<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PasswordRecoveryCode extends Notification
{
    public function __construct(public readonly string $code) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->subject('Your Veylo password reset code')->greeting('Reset your Veylo password')->line('Your verification code is: '.$this->code)->line('This code expires in 10 minutes. Do not share it with anyone.')->line('If you did not request a password reset, you can ignore this email.');
    }
}
