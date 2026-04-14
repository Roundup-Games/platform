@php
$currentPath = request()->path();
$segments = explode('/', $currentPath);
$currentLocale = $segments[0] ?? app()->getLocale();

// Build the path without the locale prefix
$pathWithoutLocale = implode('/', array_slice($segments, 1));

$enUrl = url('en/' . $pathWithoutLocale);
$deUrl = url('de/' . $pathWithoutLocale);
@endphp

<link rel="alternate" hreflang="en" href="{{ $enUrl }}">
<link rel="alternate" hreflang="de" href="{{ $deUrl }}">
<link rel="alternate" hreflang="x-default" href="{{ $enUrl }}">
