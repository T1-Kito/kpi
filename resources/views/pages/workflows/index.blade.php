@extends('layouts.app')

@section('title', 'Quy trình')
@section('page', 'workflows')
@section('page_title', 'Quy trình vận hành')
@section('page_subtitle', 'Ma trận hướng dẫn thao tác theo từng luồng nghiệp vụ để khách hàng biết làm bước nào, ở màn nào.')

@section('content')
<div id="workflowsRoot"></div>
@endsection

@push('scripts')
<script src="/assets/js/features/workflows.js?v=20261031-full-pagination12"></script>
@endpush
