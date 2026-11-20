<?php

namespace App\Modules\Marketplace\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Accounts\Actions\GetEntitlements;
use App\Modules\Directory\Actions\FindOwnProject;
use App\Modules\Marketplace\Enums\QuoteStatus;
use App\Modules\Marketplace\Exceptions\PremiumFeatureRequired;
use App\Modules\Marketplace\Http\Resources\QuoteResource;
use App\Modules\Marketplace\Models\Quote;
use App\Modules\Marketplace\Support\ComparisonPdf;
use App\Modules\Marketplace\Support\QuoteComparison;
use App\Support\Locale;
use App\Support\Pagination;
use App\Support\SecurityLog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * Devis reçus pour un projet du porteur connecté (décisions 33, 43 et 50). Le projet d'un
 * autre utilisateur donne 404 (Action publique Directory FindOwnProject).
 *
 * - Liste et lecture de chaque devis : gratuites.
 * - Comparaison côte à côte (éléments manquants, questions à poser) et son export PDF :
 *   Premium (403 premium_required sinon).
 */
class ProjectQuotesController extends Controller
{
    public function __construct(
        private FindOwnProject $findOwnProject,
        private GetEntitlements $entitlements,
    ) {}

    public function index(Request $request, string $uuid): AnonymousResourceCollection
    {
        $project = $this->project($request, $uuid);

        $request->validate(Pagination::rules());

        return QuoteResource::collection(
            $this->receivedQuotes($this->user($request), $project['id'], [QuoteStatus::Sent, QuoteStatus::Accepted, QuoteStatus::Declined, QuoteStatus::Expired, QuoteStatus::Withdrawn, QuoteStatus::Superseded])
                ->paginate(Pagination::perPage($request))
                ->withQueryString()
        );
    }

    public function comparison(Request $request, string $uuid, QuoteComparison $comparison): JsonResponse
    {
        $project = $this->project($request, $uuid);
        $this->requireFeature($request, 'quote_comparison');

        $result = $comparison->build($this->comparedQuotes($request, $project['id']), ! $project['remote_ok'], Locale::fromRequest($request));
        $locale = Locale::fromRequest($request);

        return response()->json(['data' => [
            'project' => ['uuid' => $project['uuid'], 'title' => $project['title']],
            ...$result,
            'disclaimer' => ComparisonPdf::DISCLAIMER[$locale] ?? ComparisonPdf::DISCLAIMER['fr'],
        ]]);
    }

    public function comparisonPdf(Request $request, string $uuid, QuoteComparison $comparison): Response
    {
        $project = $this->project($request, $uuid);
        $this->requireFeature($request, 'quote_comparison_export');

        $locale = Locale::fromRequest($request);
        $result = $comparison->build($this->comparedQuotes($request, $project['id']), ! $project['remote_ok'], $locale);
        SecurityLog::record('marketplace.comparison_pdf_downloaded', ['project' => $project['uuid']]);

        return response(ComparisonPdf::render($result, $project['title'], $locale), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="comparaison-devis.pdf"',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /**
     * @return array{id: int, uuid: string, title: string, remote_ok: bool}
     */
    private function project(Request $request, string $uuid): array
    {
        $project = $this->findOwnProject->handle($uuid, $this->user($request));
        abort_if($project === null, 404, 'Projet introuvable.');

        return $project;
    }

    private function requireFeature(Request $request, string $feature): void
    {
        if (! $this->entitlements->handle($this->user($request))->allows('marketplace.'.$feature)) {
            SecurityLog::record('marketplace.premium_required', ['feature' => $feature]);

            throw new PremiumFeatureRequired($feature);
        }
    }

    /**
     * Devis comparés : envoyés ou acceptés, non masqués, les plus récents d'abord limités à
     * comparison.max_quotes, puis présentés dans l'ordre de réception.
     *
     * @return Collection<int, Quote>
     */
    private function comparedQuotes(Request $request, int $projectId): Collection
    {
        $quotes = $this->receivedQuotes($this->user($request), $projectId, [QuoteStatus::Sent, QuoteStatus::Accepted])
            ->whereNull('moderation_restricted_at')
            ->get()
            ->sortBy([['sent_at', 'desc'], ['id', 'desc']])
            ->take((int) config('marketplace.comparison.max_quotes'))
            ->sortBy([['sent_at', 'asc'], ['id', 'asc']])
            ->values();

        return $quotes;
    }

    /**
     * @param  list<QuoteStatus>  $statuses
     * @return Builder<Quote>
     */
    private function receivedQuotes(User $client, int $projectId, array $statuses): Builder
    {
        return Quote::query()
            ->where('project_id', $projectId)
            ->where('client_id', $client->id)
            ->whereIn('status', array_map(fn (QuoteStatus $status): string => $status->value, $statuses))
            ->with('lines')
            ->orderBy('sent_at')
            ->orderBy('id');
    }

    private function user(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
