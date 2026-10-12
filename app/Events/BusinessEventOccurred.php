<?php

namespace App\Events;

use App\Models\BusinessEvent;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class BusinessEventOccurred
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly BusinessEvent $businessEvent) {}
}
