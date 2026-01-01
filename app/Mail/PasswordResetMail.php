<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PasswordResetMail extends Mailable
{
    use Queueable, SerializesModels;

    public string $token;
    public string $email;
    public string $resetUrl;

    public function __construct(string $token, string $email)
    {
        $this->token = $token;
        $this->email = $email;
        $frontendUrl = env('FRONTEND_URL', env('APP_URL', 'http://localhost:5173'));
        $this->resetUrl = rtrim($frontendUrl, '/') . '/reset-password?token=' . $this->token . '&email=' . urlencode($this->email);
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Reset Your Password',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.password-reset',
            with: [
                'token' => $this->token,
                'email' => $this->email,
                'resetUrl' => $this->resetUrl,
            ],
        );
    }

    public function attachments(): array
    {
        return [];
    }
}

