<?php
namespace App\Events;

use App\Models\CourierJob;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class CourierJobCreated
{
    use Dispatchable, SerializesModels;

    public function __construct(public CourierJob $job) {}
}
