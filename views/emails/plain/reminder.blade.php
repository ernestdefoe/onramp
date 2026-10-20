<x-mail::plain.notification>
<x-slot:body>
{!! $translator->trans('ernestdefoe-onramp.email.reminder.body', [
'count' => $blueprint->left,
'next' => $blueprint->next,
'url' => $url->to('forum')->route('onramp'),
]) !!}
</x-slot:body>
</x-mail::plain.notification>
