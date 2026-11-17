<?php

namespace App\Modules\Marketplace\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Marketplace\Actions\AcceptQuote;
use App\Modules\Marketplace\Actions\DeclineQuote;
use App\Modules\Marketplace\Actions\ExerciseQuoteWithdrawal;
use App\Modules\Marketplace\Actions\ReviseQuote;
use App\Modules\Marketplace\Actions\SaveQuoteDraft;
use App\Modules\Marketplace\Actions\SendQuote;
use App\Modules\Marketplace\Actions\WithdrawQuote;
use App\Modules\Marketplace\Enums\QuoteStatus;
use App\Modules\Marketplace\Http\Requests\AcceptQuoteRequest;
use App\Modules\Marketplace\Http\Requests\DeclineQuoteRequest;
use App\Modules\Marketplace\Http\Requests\ListQuotesRequest;
use App\Modules\Marketplace\Http\Requests\StoreQuoteRequest;
use App\Modules\Marketplace\Http\Requests\UpdateQuoteRequest;
use App\Modules\Marketplace\Http\Resources\QuoteResource;
use App\Modules\Marketplace\Models\Quote;
use App\Support\Pagination;
use App\Support\SecurityLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Devis de l'utilisateur connecté : rédigés et envoyés (prestataire) ou reçus (client). Le devis
 * d'un autre utilisateur donne 404 (QuotePolicy).
 */
class QuoteController extends Controller
{
    public function index(ListQuotesRequest $request): AnonymousResourceCollection
    {
        $user = $this->user($request);
        $sent = $request->string('box')->toString() === 'sent';

        $quotes = Quote::query()
            ->when($sent, fn ($query) => $query->where('provider_id', $user->id))
            ->when(! $sent, fn ($query) => $query->where('client_id', $user->id)->where('status', '!=', QuoteStatus::Draft->value))
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')->toString()))
            ->with('lines')
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->paginate(Pagination::perPage($request))
            ->withQueryString();

        return QuoteResource::collection($quotes);
    }

    public function store(StoreQuoteRequest $request, SaveQuoteDraft $saveDraft): JsonResponse
    {
        $quote = $saveDraft->create($this->user($request), $request->validated());

        return (new QuoteResource($quote))->response()->setStatusCode(201);
    }

    public function show(string $uuid): QuoteResource
    {
        $quote = $this->findQuote($uuid);
        Gate::authorize('view', $quote);

        return new QuoteResource($quote->load('lines'));
    }

    public function update(UpdateQuoteRequest $request, SaveQuoteDraft $saveDraft): QuoteResource
    {
        return new QuoteResource($saveDraft->update($request->quote(), $this->user($request), $request->validated()));
    }

    public function destroy(string $uuid): Response
    {
        $quote = $this->findQuote($uuid);
        Gate::authorize('manage', $quote);

        if ($quote->status !== QuoteStatus::Draft) {
            throw new ConflictHttpException('Seul un brouillon se supprime. Retirez plutôt le devis envoyé.');
        }

        $quote->delete();
        SecurityLog::record('marketplace.quote_draft_deleted', ['quote' => $uuid]);

        return response()->noContent();
    }

    public function send(Request $request, string $uuid, SendQuote $sendQuote): QuoteResource
    {
        $quote = $this->findQuote($uuid);
        Gate::authorize('manage', $quote);

        return new QuoteResource($sendQuote->handle($this->user($request), $quote)->load('lines'));
    }

    public function revise(string $uuid, ReviseQuote $reviseQuote): JsonResponse
    {
        $quote = $this->findQuote($uuid);
        Gate::authorize('manage', $quote);

        return (new QuoteResource($reviseQuote->handle($quote)))->response()->setStatusCode(201);
    }

    public function withdraw(string $uuid, WithdrawQuote $withdrawQuote): QuoteResource
    {
        $quote = $this->findQuote($uuid);
        Gate::authorize('manage', $quote);

        return new QuoteResource($withdrawQuote->handle($quote)->load('lines'));
    }

    public function accept(AcceptQuoteRequest $request, AcceptQuote $acceptQuote): QuoteResource
    {
        /** @var array{content_hash: string, express_execution?: bool, certifications?: list<string>} $data */
        $data = $request->validated();

        return new QuoteResource($acceptQuote->handle($this->user($request), $request->quote(), $data, $request)->load('lines'));
    }

    public function decline(DeclineQuoteRequest $request, DeclineQuote $declineQuote): QuoteResource
    {
        /** @var array{reason?: string|null, comment?: string|null} $data */
        $data = $request->validated();

        return new QuoteResource($declineQuote->handle($request->quote(), $data)->load('lines'));
    }

    /**
     * Rétractation d'un consommateur (fonction de rétractation en ligne, décision 42).
     */
    public function withdrawal(Request $request, string $uuid, ExerciseQuoteWithdrawal $exerciseWithdrawal): QuoteResource
    {
        $quote = $this->findQuote($uuid);
        Gate::authorize('respond', $quote);
        $request->validate(['confirm' => ['required', 'accepted']]);

        return new QuoteResource($exerciseWithdrawal->handle($quote)->load('lines'));
    }

    /**
     * abort() plutôt que firstOrFail() : le message 404 ne révèle pas le nom du modèle.
     */
    private function findQuote(string $uuid): Quote
    {
        $quote = Quote::query()->where('uuid', $uuid)->first();
        abort_if($quote === null, 404, 'Devis introuvable.');

        return $quote;
    }

    private function user(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
