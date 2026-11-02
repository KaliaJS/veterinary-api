<?php

namespace App\Jobs;

use App\Enums\PushType;
use App\Models\AppareilNotification;
use App\Models\NotificationSetting;
use App\Services\Notifications\CanalApns;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Une notification affichée sur les iPhone d'un compte (02.11.2026), si
 * ses réglages la permettent maintenant (sorte coupée, nuit). Les jetons
 * qu'Apple ne connaît plus sont oubliés.
 */
class SendPush implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public const string TOPIC = 'ch.veterinary.client';

    public function __construct(
        public int $userId,
        public string $type,
        public string $title,
        public string $body,
        public ?string $url = null,
    ) {}

    public function handle(CanalApns $apns): void
    {
        if (blank(config('services.apns.cle_base64'))) {
            return;
        }
        $type = PushType::from($this->type);
        if (! NotificationSetting::for($this->userId)->allows($type)) {
            return;
        }
        $appareils = AppareilNotification::query()->actifs()->where('user_id', $this->userId)->where('plateforme', 'ios')->get()->groupBy('environnement');
        $contenu = [
            'aps' => ['alert' => ['title' => $this->title, 'body' => $this->body], 'sound' => 'default', 'thread-id' => $type->value],
            'type' => $type->value,
            'url' => $this->url,
        ];
        foreach ($appareils as $environnement => $liste) {
            $morts = $apns->envoyer($liste->pluck('jeton')->values()->all(), (string) $environnement, self::TOPIC, $contenu, visible: true);
            if ($morts !== []) {
                AppareilNotification::query()->whereIn('jeton', $morts)->delete();
            }
        }
    }
}
