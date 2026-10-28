<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
{{-- Published only for right-to-left emails (Arabic). Gmail and Outlook.com drop <html>/<body> attributes and
     Outlook desktop ignores text-align: start, so dir and a physical alignment also sit on the wrapper and content cell. --}}
@php($dir = \App\Support\Locales::direction(app()->getLocale()))
<html xmlns="http://www.w3.org/1999/xhtml" lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ $dir }}">
<head>
<title>{{ config('app.name') }}</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
<meta name="color-scheme" content="light">
<meta name="supported-color-schemes" content="light">
{{-- No web fonts: a linked font would make every open a request to a third party (the app serves its own, built
     in). The theme names Readex Pro and Baloo Bhaijaan 2 first for a reader who has them, then system fonts (CHW-221).
     Outlook on Windows, which can drop to Times New Roman on a font it lacks, gets the system stack outright. --}}
<!--[if mso]>
<style>
body, table, td, p, a, h1, h2, h3, span { font-family: 'Segoe UI', Arial, sans-serif !important; }
</style>
<![endif]-->
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
{!! $head ?? '' !!}
</head>
<body dir="{{ $dir }}">

<table class="wrapper" width="100%" cellpadding="0" cellspacing="0" role="presentation" dir="{{ $dir }}">
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
<td class="content-cell" dir="{{ $dir }}" style="text-align: {{ $dir === 'rtl' ? 'right' : 'left' }};">
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
