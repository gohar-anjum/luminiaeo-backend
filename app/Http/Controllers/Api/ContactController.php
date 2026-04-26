<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreContactRequest;
use App\Mail\ContactFormAdminNotification;
use App\Models\ContactSubmission;
use App\Models\User;
use App\Services\ApiResponseModifier;
use Illuminate\Support\Facades\Mail;

class ContactController extends Controller
{
    public function __construct(
        private ApiResponseModifier $responseModifier
    ) {}

    public function store(StoreContactRequest $request)
    {
        $submission = ContactSubmission::query()->create($request->validated());
        $adminEmails = User::query()->where('is_admin', true)->pluck('email')->filter();
        if ($adminEmails->isNotEmpty()) {
            Mail::to($adminEmails->all())->send(new ContactFormAdminNotification($submission));
        }

        return $this->responseModifier
            ->setMessage('Thank you. We have received your message.')
            ->setData(['id' => $submission->id])
            ->setResponseCode(201)
            ->response();
    }
}
