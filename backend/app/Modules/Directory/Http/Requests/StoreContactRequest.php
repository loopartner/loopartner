<?php

namespace App\Modules\Directory\Http\Requests;

use App\Models\User;
use App\Modules\Accounts\Actions\FindPublishedProvider;
use App\Modules\Accounts\Actions\GetProviderSummaries;
use App\Modules\Directory\Enums\ProjectStatus;
use App\Modules\Directory\Models\MatchRequest;
use App\Modules\Directory\Models\Project;
use App\Modules\Directory\Support\MatchingRules;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * POST /providers/{slug}/contact : un particulier ou un entrepreneur contacte un prestataire.
 *
 * L'autorisation passe avant la validation : profil non publié ou inconnu 404 (Action publique
 * FindPublishedProvider du module Accounts), puis MatchRequestPolicy::contact (rôle, blocage) 403.
 * "project" (facultatif) : uuid d'un projet publié et non expiré de l'utilisateur connecté. Le
 * projet d'un autre ou un brouillon donne la même erreur 422 qu'un uuid inconnu.
 *
 * @phpstan-import-type ProviderSummary from GetProviderSummaries
 */
class StoreContactRequest extends FormRequest
{
    /**
     * @var array{user_id: int, summary: ProviderSummary}|null
     */
    private ?array $provider = null;

    public function authorize(): Response
    {
        return Gate::inspect('contact', [MatchRequest::class, $this->providerUserId()]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'message' => MatchingRules::requestMessage(),
            'project' => [
                'nullable',
                'string',
                'uuid',
                Rule::exists('projects', 'uuid')
                    ->where('user_id', $this->authenticatedUser()->id)
                    ->where('status', ProjectStatus::Published->value)
                    ->where(fn ($query) => $query->where('expires_at', '>', now())),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'project.exists' => 'Choisissez l\'un de vos projets publiés.',
        ];
    }

    /**
     * Identifiant d'utilisateur du prestataire (404 si son profil n'est pas publié).
     */
    public function providerUserId(): int
    {
        if ($this->provider === null) {
            $provider = app(FindPublishedProvider::class)->handle((string) $this->route('slug'));
            abort_if($provider === null, 404, 'Prestataire introuvable.');
            $this->provider = $provider;
        }

        return $this->provider['user_id'];
    }

    /**
     * Projet rattaché à la demande, après validation.
     */
    public function project(): ?Project
    {
        $uuid = $this->validated('project');

        return is_string($uuid) ? Project::query()->where('uuid', $uuid)->first() : null;
    }

    private function authenticatedUser(): User
    {
        /** @var User */
        return $this->user();
    }
}
