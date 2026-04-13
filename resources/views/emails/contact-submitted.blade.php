@component('mail::message')
# New Contact Form Submission

A new message has been submitted through the contact form.

---

**Name:** {{ $contactMessage->name }}
**Email:** {{ $contactMessage->email }}
@if($contactMessage->subject)
**Subject:** {{ $contactMessage->subject }}
@endif

**Message:**

{{ $contactMessage->message }}

---

@endcomponent
