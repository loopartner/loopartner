<?php

use App\Models\User;
use App\Notifications\ResetPasswordNotification;
use Illuminate\Support\Facades\Notification;

it('envoie un lien de réinitialisation qui pointe vers le frontend', function (): void {
    Notification::fake();
    config(['app.frontend_url' => 'https://app.loopartner.test']);
    $user = User::factory()->create(['email' => 'camille@example.com']);

    $this->postJson('/api/v1/auth/forgot-password', ['email' => 'camille@example.com'])->assertOk();

    Notification::assertSentTo($user, ResetPasswordNotification::class, function (ResetPasswordNotification $notification) use ($user): bool {
        $url = $notification->toMail($user)->actionUrl;

        return str_starts_with($url, 'https://app.loopartner.test/reset-password?token='.$notification->token)
            && str_contains($url, 'email=camille%40example.com');
    });
});
