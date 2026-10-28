@props(['url'])
{{-- The punchcard wordmark (Claude Design `Punchcard Emails.dc.html`, CHW-221): the saffron dot and the name, never Laravel's logo.
     The dot is a character, not a sized box: Outlook's Word engine drops width, height and radius on inline elements.
     The slot comes escaped from message.blade.php, so it is printed as is. --}}
<tr>
<td class="header">
<a href="{{ $url }}" dir="ltr" style="display: inline-block;">
<span class="wordmark-dot">&#9679;</span>{!! trim($slot) !!}
</a>
</td>
</tr>
