<?php
namespace App\Jobs;

use App\Models\User;
use App\Services\Push\OneSignalService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;

class NotifyCouriersAboutJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $jobId, public int $dealerId, public ?string $region = null) {}

    public function handle(OneSignalService $push)
    {
        $couriers = User::query()
            ->where('role', 'courier')
            ->where('dealer_id', $this->dealerId)
            ->where('is_active', true)
            ->when($this->region, fn($q) => $q->where(function($qq){
                $qq->where('region', $this->region)
                   ->orWhereJsonContains('regions', $this->region);
            }))
            ->pluck('id')
            ->map(fn($id) => "courier_{$id}")
            ->values()
            ->all();

        if (empty($couriers)) {
            \Log::info("NotifyCouriersAboutJob: uygun kurye yok", [
                'dealer_id' => $this->dealerId, 'region' => $this->region
            ]);
            return;
        }

        $title = ['tr' => 'Yeni İş Var!', 'en' => 'New Job Available!'];
        $body  = ['tr' => 'Bayi yeni teslimat atadı. Detay için tıkla.',
                  'en' => 'A dealer posted a new delivery. Tap to view.'];
        $data  = [
            'job_id'    => $this->jobId,
            'dealer_id' => $this->dealerId,
            'region'    => $this->region,
            'deeplink'  => "haldeki://job/{$this->jobId}",
        ];

        foreach (array_chunk($couriers, 1000) as $chunk) {
            $push->sendToExternalUsers($chunk, $body, $data, $title);
        }
    }
}
