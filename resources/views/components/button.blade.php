@props(['type' => 'button', 'variant' => 'secondary'])

<button type="{{ $type }}" {{ $attributes->merge(['class' => 'btn '.$variant]) }}>
    {{ $slot }}
</button>
