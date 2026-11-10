<?php

namespace App\Modules\Directory\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Directory\Actions\AcceptMatchRequest;
use App\Modules\Directory\Actions\BlockFromMatchRequest;
use App\Modules\Directory\Actions\DeclineMatchRequest;
use App\Modules\Directory\Actions\ReportContent;
use App\Modules\Directory\Actions\SendContactRequest;
use App\Modules\Directory\Actions\SendProposal;
use App\Modules\Directory\Actions\WithdrawMatchRequest;
use App\Modules\Directory\Http\Requests\ListMatchRequestsRequest;
use App\Modules\Directory\Http\Requests\ReportMatchRequestRequest;
use App\Modules\Directory\Http\Requests\StoreContactRequest;
use App\Modules\Directory\Http\Requests\StoreProposalRequest;
use App\Modules\Directory\Http\Resources\AbuseReportResource;
use App\Modules\Directory\Http\Resources\MatchRequestResource;
use App\Modules\Directory\Models\MatchRequest;
use App\Modules\Directory\Support\ViewerContext;
use App\Support\Pagination;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * Demandes de mise en relation : envoi (proposition ou contact), consultation par les deux
 * participants, et cycle de vie (acceptation, refus, retrait). Un non-participant reçoit 404
 * (MatchRequestPolicy).
 */
class MatchRequestController extends Controller
{
    private const RELATIONS = ['author.roles', 'recipient.roles', 'project.category', 'conversation'];

    /**
     * POST /projects/{uuid}/proposals
     */
    public function propose(StoreProposalRequest $request, SendProposal $sendProposal): JsonResponse
    {
        /** @var array{message: string, price_amount?: int|null, price_currency?: string|null} $data */
        $data = $request->validated();

        return $this->created($request, $sendProposal->handle($this->user($request), $request->project(), $data));
    }

    /**
     * POST /providers/{slug}/contact
     */
    public function contact(StoreContactRequest $request, SendContactRequest $sendContactRequest): JsonResponse
    {
        /** @var array{message: string} $data */
        $data = $request->validated();

        return $this->created($request, $sendContactRequest->handle($this->user($request), $request->providerUserId(), $request->project(), $data));
    }

    /**
     * GET /me/match-requests?box=received|sent
     */
    public function index(ListMatchRequestsRequest $request): AnonymousResourceCollection
    {
        $user = $this->user($request);
        $column = $request->string('box')->toString() === 'sent' ? 'author_id' : 'recipient_id';

        $matchRequests = MatchRequest::query()
            ->where($column, $user->id)
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')->toString()))
            ->when($request->filled('type'), fn ($query) => $query->where('type', $request->string('type')->toString()))
            ->with(self::RELATIONS)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(Pagination::perPage($request))
            ->withQueryString();

        $context = ViewerContext::build($user, $matchRequests->getCollection()->map(
            fn (MatchRequest $matchRequest): ?User => $matchRequest->isAuthor($user) ? $matchRequest->recipient : $matchRequest->author,
        ));

        return MatchRequestResource::collection(
            $matchRequests->through(fn (MatchRequest $matchRequest): MatchRequestResource => new MatchRequestResource($matchRequest, $context)),
        );
    }

    public function show(Request $request, string $uuid): MatchRequestResource
    {
        $matchRequest = $this->find($uuid);
        Gate::authorize('view', $matchRequest);

        return $this->resource($request, $matchRequest);
    }

    public function accept(Request $request, string $uuid, AcceptMatchRequest $acceptMatchRequest): MatchRequestResource
    {
        $matchRequest = $this->find($uuid);
        Gate::authorize('accept', $matchRequest);

        return $this->resource($request, $acceptMatchRequest->handle($matchRequest));
    }

    public function decline(Request $request, string $uuid, DeclineMatchRequest $declineMatchRequest): MatchRequestResource
    {
        $matchRequest = $this->find($uuid);
        Gate::authorize('decline', $matchRequest);

        return $this->resource($request, $declineMatchRequest->handle($matchRequest));
    }

    public function withdraw(Request $request, string $uuid, WithdrawMatchRequest $withdrawMatchRequest): MatchRequestResource
    {
        $matchRequest = $this->find($uuid);
        Gate::authorize('withdraw', $matchRequest);

        return $this->resource($request, $withdrawMatchRequest->handle($matchRequest));
    }

    /**
     * POST /me/match-requests/{uuid}/report : signalement par le destinataire.
     */
    public function report(ReportMatchRequestRequest $request, ReportContent $reportContent): JsonResponse
    {
        /** @var array{reason: string, comment?: string|null} $data */
        $data = $request->validated();

        return (new AbuseReportResource($reportContent->handle($this->user($request), $request->matchRequest(), $data)))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * POST /me/match-requests/{uuid}/block : le destinataire bloque l'auteur.
     */
    public function block(Request $request, string $uuid, BlockFromMatchRequest $blockFromMatchRequest): Response
    {
        $matchRequest = $this->find($uuid);
        Gate::authorize('block', $matchRequest);

        $blockFromMatchRequest->handle($this->user($request), $matchRequest);

        return response()->noContent();
    }

    private function created(Request $request, MatchRequest $matchRequest): JsonResponse
    {
        return $this->resource($request, $matchRequest)->response()->setStatusCode(201);
    }

    private function resource(Request $request, MatchRequest $matchRequest): MatchRequestResource
    {
        $user = $this->user($request);
        $matchRequest->load(self::RELATIONS);

        $context = ViewerContext::build($user, [$matchRequest->isAuthor($user) ? $matchRequest->recipient : $matchRequest->author]);

        return new MatchRequestResource($matchRequest, $context);
    }

    /**
     * abort() plutôt que firstOrFail() : le message 404 ne révèle pas le nom du modèle.
     */
    private function find(string $uuid): MatchRequest
    {
        $matchRequest = MatchRequest::query()->where('uuid', $uuid)->first();
        abort_if($matchRequest === null, 404, 'Demande introuvable.');

        return $matchRequest;
    }

    private function user(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
