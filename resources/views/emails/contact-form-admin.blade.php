<p>You received a new message from the website contact form.</p>
<p><strong>Name:</strong> {{ $submission->name }}</p>
<p><strong>Email:</strong> {{ $submission->email }}</p>
<p><strong>Message:</strong></p>
<p>{{ $submission->message }}</p>
<p>Submission ID: #{{ $submission->id }}</p>
