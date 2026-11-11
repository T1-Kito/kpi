<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        \Illuminate\Database\Connection::resolverFor('sqlsrv',
            fn($pdo,$database,$prefix,$config)=>new \App\Support\SqlServerInstallConnection($pdo,$database,$prefix,$config));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        foreach (['en','vi'] as $locale) app('translator')->addLines([
            'validation.required'=>'Vui lòng nhập :attribute.',
            'validation.required_without'=>'Vui lòng nhập :attribute khi chưa có :values.',
            'validation.required_with'=>'Vui lòng nhập :attribute khi có :values.',
            'validation.attributes.phone'=>'số điện thoại',
            'validation.attributes.email'=>'email',
            'validation.attributes.name'=>'tên',
            'validation.attributes.customer_id'=>'khách hàng',
            'validation.attributes.contact_phone'=>'số điện thoại người liên hệ',
            'validation.attributes.phone_number'=>'số điện thoại',
        ], $locale);
        \Illuminate\Support\Facades\Validator::extend('phone_number',
            fn ($attribute, $value) => \App\Support\PhoneNumber::valid($value),
            'Số điện thoại chỉ được chứa chữ số, dấu + ở đầu, khoảng trắng, dấu chấm, dấu gạch ngang hoặc dấu ngoặc.');
    }
}
