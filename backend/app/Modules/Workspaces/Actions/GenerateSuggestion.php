<?php

namespace App\Modules\Workspaces\Actions;

use App\Models\ServiceCategory;
use App\Models\User;
use App\Modules\Workspaces\Enums\SuggestionKind;
use App\Modules\Workspaces\Enums\SuggestionStatus;
use App\Modules\Workspaces\Exceptions\EmailNotVerified;
use App\Modules\Workspaces\Models\CopilotSuggestion;
use App\Modules\Workspaces\Models\Step;
use App\Modules\Workspaces\Models\Workspace;
use App\Modules\Workspaces\Support\CopilotOutput;
use App\Modules\Workspaces\Support\CopilotPayload;
use App\Modules\Workspaces\Support\CopilotPrompts;
use App\Modules\Workspaces\Support\CopilotQuota;
use App\Modules\Workspaces\Support\Workspaces;
use App\Support\Ai\AiGateway;
use App\Support\Ai\AiRequest;
use App\Support\Ai\Exceptions\AiBusy;
use App\Support\Ai\Exceptions\AiUnavailable;
use App\Support\SecurityLog;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Génération du copilote de projet (décisions 33, 61 et 64), dans cet ordre :
 * 1. email vérifié (403 email_not_verified) ; workspace modifiable (409 archivé, 403
 *    verrouillé) ;
 * 2. IA disponible (503 ai_unavailable) ;
 * 3. verrou par utilisateur (409 generation_in_progress), puis quotas (403 premium_required,
 *    429 ai_quota_exceeded) comptés sous ce verrou ;
 * 4. contenu construit par liste blanche et expurgé (CopilotPayload), appel par AiGateway
 *    (plafonds de coût, journal sans contenu) ;
 * 5. réponse nettoyée (CopilotOutput ; 502 si inexploitable) puis gardée comme
 *    **suggestion** : rien n'entre dans le workspace sans l'action de l'utilisateur ;
 * 6. génération décomptée seulement une fois réussie.
 */
class GenerateSuggestion
{
    public function __construct(private AiGateway $gateway, private CopilotQuota $quota, private Workspaces $workspaces) {}

    public function handle(User $user, Workspace $workspace, SuggestionKind $kind, string $locale, ?Step $step = null, ?ServiceCategory $category = null): CopilotSuggestion
    {
        if (! $user->hasVerifiedEmail()) {
            throw new EmailNotVerified;
        }

        $this->workspaces->assertWritable($user, $workspace);

        $reason = $this->gateway->unavailableReason();

        if ($reason !== null) {
            throw new AiUnavailable($reason);
        }

        $lock = Cache::lock('copilot:generation:'.$user->id, AiGateway::requiredExecutionTime());

        if (! $lock->get()) {
            throw new AiBusy;
        }

        try {
            $this->quota->assertAvailable($user, $kind);

            $category ??= $step?->category;
            $payload = CopilotPayload::build($user, $workspace, $kind, $locale, $step, $category);
            $response = $this->gateway->generate($user, new AiRequest(
                feature: 'copilot.'.$kind->value,
                promptVersion: CopilotPrompts::version($kind),
                system: CopilotPrompts::system($kind),
                content: CopilotPayload::encode($payload['content']),
                schemaName: 'copilot_'.$kind->value,
                schema: CopilotPrompts::schema($kind),
                maxOutputTokens: (int) config('ai.max_output_tokens'),
            ));

            $content = CopilotOutput::clean($kind, $response->data);

            if ($kind === SuggestionKind::Specification) {
                $content['category'] = $category?->slug;
            }

            $suggestion = new CopilotSuggestion;
            $suggestion->forceFill([
                'uuid' => (string) Str::uuid(),
                'workspace_id' => $workspace->id,
                'step_id' => $step?->id,
                'kind' => $kind,
                'status' => SuggestionStatus::Pending,
                'content' => $content,
                'prompt_version' => CopilotPrompts::version($kind),
                'provider' => $this->gateway->client()->name(),
                'model' => $response->model,
            ])->save();

            $this->quota->record($user, $kind);

            SecurityLog::record('copilot.generated', [
                'user_id' => $user->id,
                'kind' => $kind->value,
                'workspace' => $workspace->uuid,
                'suggestion' => $suggestion->uuid,
                'redactions' => $payload['redactions'],
            ]);

            return $suggestion;
        } finally {
            $lock->release();
        }
    }
}
