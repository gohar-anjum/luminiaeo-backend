<?php

namespace App\Mail;

use App\Models\ContactSubmission;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class ContactFormAdminNotification extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public ContactSubmission $contactSubmission
    ) {}

    public function build()
    {
        return $this
            ->subject('New contact form message: '.$this->contactSubmission->email)
            ->view('emails.contact-form-admin')
            ->with([
                'submission' => $this->contactSubmission,
            ]);
    }
}
