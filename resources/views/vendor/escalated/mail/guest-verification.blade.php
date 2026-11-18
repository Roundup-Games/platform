{{-- Roundup override of escalated::mail/guest-verification
    (M081/S03/T02). This mailable is TEXT-ONLY (vendor's
    GuestVerificationCode::build() calls ->text(), so no HTML part exists
    and no CSS brand token can apply) — the brand surface here is the
    {{ config('app.name') }} name in the copy. EN only (K002 exemption). --}}
Your {{ config('app.name') }} support verification code is: {{ $code }}

This code expires in 10 minutes and can be used once. Enter it in the support form where you requested it.

If you did not request this code, you can ignore this email. No ticket access has been granted.

— {{ config('app.name') }} Support
