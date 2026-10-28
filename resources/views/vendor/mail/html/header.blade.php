@props(['url'])
{{-- The punchcard wordmark (Claude Design `Punchcard Emails.dc.html`, CHW-221): the saffron dot and the name, never Laravel's logo. --}}
<tr>
<td class="header">
<a href="{{ $url }}" dir="ltr" style="display: inline-block;">
<span class="wordmark-dot"></span>{{ trim($slot) }}
</a>
</td>
</tr>
