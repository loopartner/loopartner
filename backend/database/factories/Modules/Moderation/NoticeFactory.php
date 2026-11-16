<?php

namespace Database\Factories\Modules\Moderation;

use App\Modules\Moderation\Enums\NoticePriority;
use App\Modules\Moderation\Enums\NoticeSource;
use App\Modules\Moderation\Enums\NoticeStatus;
use App\Modules\Moderation\Models\Notice;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Signalement public ouvert d'un article, par un visiteur sans compte.
 *
 * @extends Factory<Notice>
 */
class NoticeFactory extends Factory
{
    protected $model = Notice::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'target_type' => 'article',
            'target_ref' => 'un-article-'.Str::lower(Str::random(8)),
            'target_owner_id' => null,
            'category' => 'scam_fraud',
            'priority' => NoticePriority::Standard,
            'source' => NoticeSource::Public,
            'explanation' => 'Cet article promet des gains garantis et renvoie vers un site frauduleux.',
            'content_label' => 'Titre du contenu signalé',
            'content_snapshot' => 'Copie textuelle du contenu signalé.',
            'reporter_name' => 'Alex Martin',
            'reporter_email' => 'alex@example.org',
            'reporter_email_hash' => hash('sha256', 'alex@example.org'),
            'good_faith_confirmed_at' => now(),
            'status' => NoticeStatus::Open,
            'due_at' => now()->addDays(7),
        ];
    }

    public function closed(): static
    {
        return $this->state(fn (): array => [
            'status' => NoticeStatus::Dismissed,
            'closed_at' => now(),
        ]);
    }
}
