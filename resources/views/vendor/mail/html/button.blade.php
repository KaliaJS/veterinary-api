@props([
    'url',
    'color' => 'primary',
    'align' => 'center',
])
{{-- Bouton blanc en pilule, comme « Me connecter en un clic » --}}
<table class="action" align="{{ $align }}" width="100%" cellpadding="0" cellspacing="0" role="presentation" style="margin:26px 0 8px;">
<tr>
<td align="{{ $align }}">
<table border="0" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td class="bouton" align="center" bgcolor="#ffffff" style="background-color:#ffffff; border-radius:999px;">
<a href="{{ $url }}" class="button" target="_blank" rel="noopener" style="display:inline-block; padding:14px 30px; font-family:-apple-system, BlinkMacSystemFont, 'Segoe UI', Helvetica, Arial, sans-serif; font-size:15px; line-height:20px; font-weight:700; color:#13111b; text-decoration:none; border-radius:999px;">{!! $slot !!}</a>
</td>
</tr>
</table>
</td>
</tr>
</table>
