<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <title>{{ $subject ?? __('notifications.email_default_subject') }}</title>
    <style>
        /* Reset */
        body, table, td, a { -webkit-text-size-adjust: 100%; -ms-text-size-adjust: 100%; }
        table, td { mso-table-lspace: 0pt; mso-table-rspace: 0pt; }
        img { -ms-interpolation-mode: bicubic; border: 0; height: auto; line-height: 100%; outline: none; text-decoration: none; }
        body { margin: 0; padding: 0; width: 100% !important; height: 100% !important; }

        /* Design system colors */
        .brand-primary { color: #835500; }
        .brand-bg { background-color: #835500; }
        .cream-bg { background-color: #fbf9f1; }
        .white-bg { background-color: #ffffff; }
        .text-dark { color: #1b1c17; }
        .text-muted { color: #5c5641; }

        /* Layout */
        .email-wrapper { width: 100%; background-color: #f3f0e6; padding: 40px 0; }
        .email-container { max-width: 600px; margin: 0 auto; }

        /* Header */
        .header { background-color: #835500; padding: 28px 40px; border-radius: 8px 8px 0 0; text-align: center; }
        .header h1 { margin: 0; font-family: 'Noto Serif', Georgia, 'Times New Roman', serif; font-size: 22px; color: #ffffff; letter-spacing: 0.5px; }

        /* Content */
        .content { background-color: #ffffff; padding: 36px 40px; }
        .content h2 { font-family: 'Noto Serif', Georgia, 'Times New Roman', serif; color: #1b1c17; font-size: 20px; margin: 0 0 16px; }
        .content p { font-family: Inter, -apple-system, 'Helvetica Neue', Arial, sans-serif; color: #3d3a2e; font-size: 15px; line-height: 1.6; margin: 0 0 14px; }
        .content ul { font-family: Inter, -apple-system, 'Helvetica Neue', Arial, sans-serif; color: #3d3a2e; font-size: 15px; line-height: 1.6; margin: 0 0 14px; padding-left: 20px; }
        .content strong { color: #1b1c17; }

        /* Action Button */
        .action-button { display: inline-block; background-color: #835500; color: #ffffff !important; font-family: Inter, -apple-system, 'Helvetica Neue', Arial, sans-serif; font-size: 15px; font-weight: 600; text-decoration: none; padding: 14px 32px; border-radius: 8px; margin: 8px 0 20px; }
        .action-button:hover { background-color: #6b4500; }

        /* Footer */
        .footer { background-color: #fbf9f1; padding: 24px 40px; border-radius: 0 0 8px 8px; text-align: center; border-top: 1px solid #e8e2d4; }
        .footer p { font-family: Inter, -apple-system, 'Helvetica Neue', Arial, sans-serif; color: #8a846e; font-size: 12px; line-height: 1.5; margin: 0 0 8px; }
        .footer a { color: #835500; text-decoration: underline; }

        /* Responsive */
        @media only screen and (max-width: 620px) {
            .email-container { width: 100% !important; }
            .header, .content, .footer { padding-left: 20px !important; padding-right: 20px !important; }
        }
    </style>
</head>
<body>
    <div class="email-wrapper">
        <div class="email-container">

            {{-- Header --}}
            <div class="header">
                <h1>{{ __('notifications.email_brand_name') }}</h1>
            </div>

            {{-- Body Content --}}
            <div class="content">
                @isset($slot)
                    {{ $slot }}
                @else
                    {!! $body ?? '' !!}
                @endif
            </div>

            {{-- Footer --}}
            <div class="footer">
                <p>{{ __('notifications.email_footer_reason') }}</p>
                <p>
                    @isset($unsubscribeUrl)
                        <a href="{{ $unsubscribeUrl }}">{{ __('notifications.email_unsubscribe') }}</a>
                        &nbsp;&middot&nbsp;
                    @endisset
                    <a href="{{ config('app.url') }}/{{ app()->getLocale() }}/profile">{{ __('notifications.email_manage_settings') }}</a>
                </p>
                <p style="margin-top: 12px;">&copy; {{ date('Y') }} Roundup Games</p>
            </div>

        </div>
    </div>
</body>
</html>
