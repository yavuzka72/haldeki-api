<?php
namespace App\Listeners;

use App\Events\CourierJobCreated;
use App\Jobs\NotifyCouriersAboutJob;

class EnqueueCourierJobNotification
{
    public function handle(CourierJobCreated $event): void
    {
        dispatch(new NotifyCouriersAboutJob($event->job->id, $event->job->dealer_id, $event->job->region))
            ->onQueue('push');
    }
}
