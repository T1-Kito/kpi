@extends('layouts.app')

@section('title', 'Công việc')
@section('page', 'tasks')
@section('page_title', 'Công việc')
@section('page_subtitle', 'Theo dõi công việc thủ công và công việc tự sinh từ luồng nghiệp vụ.')
@section('page_actions')
    <button class="btn primary" type="button" data-create-task>+ Tạo công việc</button>
@endsection

@section('content')
<div id="tasksRoot" class="tasks-root"></div>
@endsection

@push('scripts')
<script src="/assets/js/features/tasks.js"></script>
@endpush
