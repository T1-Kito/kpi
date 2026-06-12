@props(['title' => 'Chưa có dữ liệu', 'description' => 'Dữ liệu mới sẽ hiển thị tại đây.'])

<div {{ $attributes->merge(['class' => 'empty']) }}>
    <strong>{{ $title }}</strong>
    <span>{{ $description }}</span>
</div>
