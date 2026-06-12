@props(['variant' => 'info'])

<span {{ $attributes->merge(['class' => 'badge '.$variant]) }}>
    {{ $slot }}
</span>
