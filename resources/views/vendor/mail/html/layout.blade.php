<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml" lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
<title>{{ config('app.name') }}</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
<meta name="color-scheme" content="light">
<meta name="supported-color-schemes" content="light">
<style>
@media only screen and (max-width: 600px) {
.inner-body {
width: 100% !important;
}

.footer {
width: 100% !important;
}
}

@media only screen and (max-width: 500px) {
.button {
width: 100% !important;
}
}
</style>
<style>
{{--
    Roundup brand theme (D279/R103) embedded verbatim so the parts the
    CssToInlineStyles pipeline cannot inline — the :focus-visible rules
    (pseudo-class selectors are not inlinable) — reach the delivered HTML.
    The same file is separately inlined as style attributes by the Markdown
    renderer. Single source of truth: the theme file itself. Do not
    hand-edit rules here.

    LIGHT-LOCK (2026-11-16, user report: illegible white-on-cream mail in
    Mailpit behind client-side dark recoloring): the mail declares itself
    light-only. The former @media (prefers-color-scheme: dark) block was
    removed — clients that recolor or partially apply embedded stylesheets
    produced broken hybrids (near-white text over the inlined cream
    surfaces). One legible light design, everywhere.
--}}
@include('mail::themes.roundup')
</style>
{!! $head ?? '' !!}
</head>
<body style="background-color: #f3f0e6; color: #3d3a2e; margin: 0; padding: 0;">

<table class="wrapper" width="100%" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td align="center">
<table class="content" width="100%" cellpadding="0" cellspacing="0" role="presentation">
{!! $header ?? '' !!}

<!-- Email Body -->
<tr>
<td class="body" width="100%" cellpadding="0" cellspacing="0" style="border: hidden !important;">
<table class="inner-body" align="center" width="570" cellpadding="0" cellspacing="0" role="presentation">
<!-- Body content -->
<tr>
<td class="content-cell">
{!! Illuminate\Mail\Markdown::parse($slot) !!}

{!! $subcopy ?? '' !!}
</td>
</tr>
</table>
</td>
</tr>

{!! $footer ?? '' !!}
</table>
</td>
</tr>
</table>
</body>
</html>
