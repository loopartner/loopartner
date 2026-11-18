<?php

namespace App\Http\Controllers;

use App\Support\Ai\AiUsageReport;
use App\Support\SecurityLog;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /admin/ai/usage?month=AAAA-MM : suivi des coûts de l'IA (admins avec 2FA de session,
 * EnsureModerator) : totaux sans identité ni contenu. Mois en cours par défaut.
 */
class AiUsageController extends Controller
{
    public function __invoke(Request $request, AiUsageReport $report): JsonResponse
    {
        $validated = $request->validate([
            'month' => ['sometimes', 'string', 'date_format:Y-m'],
        ]);

        $month = isset($validated['month'])
            ? CarbonImmutable::createFromFormat('!Y-m', $validated['month'], 'UTC')
            : CarbonImmutable::now('UTC');

        SecurityLog::record('ai.usage_viewed', ['month' => $month?->format('Y-m')]);

        return response()->json(['data' => $report->month($month ?? CarbonImmutable::now('UTC'))]);
    }
}
