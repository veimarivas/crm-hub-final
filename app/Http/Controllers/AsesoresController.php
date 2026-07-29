<?php

namespace App\Http\Controllers;

use App\Models\Lead;
use App\Models\LeadEvent;
use App\Models\Pipeline;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AsesoresController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        $accountId = $user->account_id;
        $isAdmin = $user->hasRoleAtLeast(User::ROLE_ADMIN);

        $pipelines = Pipeline::forAccount($accountId)->with('stages')->get();

        $users = User::where('account_id', $accountId)
            ->whereIn('account_role', [User::ROLE_AGENT, User::ROLE_ADMIN, User::ROLE_OWNER])
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'account_role']);

        $agents = $users->filter(function ($agent) use ($isAdmin, $user) {
            return $isAdmin || $agent->id === $user->id;
        })->values()->map(function ($agent) use ($accountId, $pipelines) {
            $leads = Lead::forAccount($accountId)
                ->where('responsible_user_id', $agent->id)
                ->with('stage', 'pipeline')
                ->get();

            $byPipeline = $pipelines->mapWithKeys(function ($pipeline) use ($leads) {
                $pipelineLeads = $leads->where('pipeline_id', $pipeline->id);
                $stages = $pipeline->stages->map(function ($stage) use ($pipelineLeads) {
                    $stageLeads = $pipelineLeads->where('stage_id', $stage->id);
                    return [
                        'id' => $stage->id,
                        'name' => $stage->name,
                        'color' => $stage->color,
                        'stage_type' => $stage->stage_type,
                        'count' => $stageLeads->count(),
                        'value' => (float) $stageLeads->sum('value'),
                    ];
                });

                return [$pipeline->id => [
                    'id' => $pipeline->id,
                    'name' => $pipeline->name,
                    'stages' => $stages,
                    'total' => $pipelineLeads->count(),
                ]];
            });

            $openLeads = $leads->where('status', Lead::STATUS_OPEN);
            $wonLeads = $leads->where('status', Lead::STATUS_WON);
            $lostLeads = $leads->where('status', Lead::STATUS_LOST);

            // Daily history (last 30 days): leads received, responded, current status
            $dailyHistory = $this->buildDailyHistory($agent->id, $accountId);

            return [
                'id' => $agent->id,
                'name' => $agent->name,
                'email' => $agent->email,
                'role' => $agent->account_role,
                'initial' => strtoupper($agent->name[0] ?? '?'),
                'total_leads' => $leads->count(),
                'open_leads' => $openLeads->count(),
                'won_leads' => $wonLeads->count(),
                'lost_leads' => $lostLeads->count(),
                'won_value' => (float) $wonLeads->sum('value'),
                'by_pipeline' => array_values($byPipeline->toArray()),
                'daily_history' => $dailyHistory,
            ];
        });

        $totals = [
            'agents' => $agents->count(),
            'total_leads' => $agents->sum('total_leads'),
            'won_leads' => $agents->sum('won_leads'),
            'won_value' => $agents->sum('won_value'),
        ];

        return Inertia::render('Asesores/Index', [
            'agents' => $agents,
            'pipelines' => $pipelines->map(fn ($p) => [
                'id' => $p->id,
                'name' => $p->name,
                'stages' => $p->stages->map(fn ($s) => [
                    'id' => $s->id,
                    'name' => $s->name,
                    'color' => $s->color,
                    'stage_type' => $s->stage_type,
                ]),
            ]),
            'totals' => $totals,
            'isAdmin' => $isAdmin,
            'currency' => $user->account->default_currency ?? 'USD',
        ]);
    }

    /**
     * Build daily lead history for the last 30 days for a given agent.
     */
    private function buildDailyHistory(string $agentId, string $accountId): array
    {
        $thirtyDaysAgo = now()->subDays(29)->startOfDay();

        $leads = Lead::forAccount($accountId)
            ->where('responsible_user_id', $agentId)
            ->where('created_at', '>=', $thirtyDaysAgo)
            ->get(['id', 'status', 'created_at']);

        if ($leads->isEmpty()) {
            return [];
        }

        $leadIds = $leads->pluck('id');

        // Get responded leads (those with at least one message_out event)
        $respondedIds = LeadEvent::whereIn('lead_id', $leadIds)
            ->where('event_type', 'message_out')
            ->where('account_id', $accountId)
            ->distinct('lead_id')
            ->pluck('lead_id')
            ->toArray();

        $respondedSet = array_flip($respondedIds);

        // Build day map for the last 30 days
        $dayMap = [];
        $cursor = now()->subDays(29)->startOfDay();
        for ($i = 0; $i < 30; $i++) {
            $key = $cursor->format('Y-m-d');
            $dayMap[$key] = [
                'date' => $key,
                'label' => $cursor->format('d/m'),
                'total' => 0,
                'responded' => 0,
                'open' => 0,
                'won' => 0,
                'lost' => 0,
            ];
            $cursor->addDay();
        }

        foreach ($leads as $lead) {
            $key = $lead->created_at->format('Y-m-d');
            if (! isset($dayMap[$key])) {
                continue;
            }
            $dayMap[$key]['total']++;
            $dayMap[$key][$lead->status]++;
            if (isset($respondedSet[$lead->id])) {
                $dayMap[$key]['responded']++;
            }
        }

        return array_values($dayMap);
    }
}