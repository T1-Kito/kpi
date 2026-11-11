<?php
namespace App\Models\Concerns;
trait HasVietnameseSearch
{
    protected static function bootHasVietnameseSearch(): void {
        static::saving(function($model) { $model->search_text = \App\Support\VietnameseSearch::text($model->getAttributes()); });
    }
}
