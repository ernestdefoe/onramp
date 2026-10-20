<x-mail::html.notification>
    <x-slot:body>
        {!! $formatter->convert($translator->trans('ernestdefoe-onramp.email.reminder.body', [
            'count' => $blueprint->left,
            'next' => $blueprint->next,
            'url' => $url->to('forum')->route('onramp'),
        ])) !!}
    </x-slot:body>
</x-mail::html.notification>
