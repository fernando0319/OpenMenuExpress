<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PrintAgent;
use App\Models\PrintJob;
use App\Models\Store;
use App\Printing\PrintingPairing;
use App\Printing\PrintQueue;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Endpoints used by the print agent installed on the store's computer.
 */
class PrintAgentController extends Controller
{
    public function pair(Request $request, PrintingPairing $pairing): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'size:6'],
            'name' => ['required', 'string', 'max:80'],
            'platform' => ['nullable', 'string', 'max:40'],
            'version' => ['nullable', 'string', 'max:20'],
        ]);

        $storeId = $pairing->consume($data['code']);
        $store = $storeId === null ? null : Store::query()->find($storeId);

        if ($store === null) {
            return response()->json(['message' => 'Código inválido ou expirado. Gere um novo no painel.'], 422);
        }

        $token = Str::random(48);

        $store->printAgents()->create([
            'name' => $data['name'],
            'token_hash' => PrintAgent::hashToken($token),
            'platform' => $data['platform'] ?? null,
            'version' => $data['version'] ?? null,
            'last_seen_at' => now(),
        ]);

        return response()->json(['token' => $token, 'store' => $store->name], 201);
    }

    public function heartbeat(Request $request): JsonResponse
    {
        $data = $request->validate([
            'printers' => ['array', 'max:50'],
            'printers.*' => ['string', 'max:200'],
            'version' => ['nullable', 'string', 'max:20'],
        ]);

        $agent = $this->agent($request);
        $agent->update([
            'available_printers' => array_values(array_unique($data['printers'] ?? [])),
            'version' => $data['version'] ?? $agent->version,
        ]);

        return response()->json(['store' => $agent->store->name]);
    }

    public function jobs(Request $request, PrintQueue $queue): JsonResponse
    {
        $agent = $this->agent($request);
        $printers = $agent->printers()->where('is_active', true)->get()->keyBy('id');

        $jobs = $queue->claim(array_values($printers->keys()->all()));

        return response()->json(['jobs' => array_map(fn (PrintJob $job) => [
            'id' => $job->id,
            'printer' => $printers->get($job->printer_id)?->system_name,
            'payload' => $job->payload,
        ], $jobs)]);
    }

    public function result(Request $request, PrintJob $job): JsonResponse
    {
        $agent = $this->agent($request);

        abort_unless($job->printer()->where('print_agent_id', $agent->id)->exists(), 404);

        $data = $request->validate([
            'status' => ['required', 'in:printed,failed'],
            'error' => ['nullable', 'string', 'max:250'],
        ]);

        $job->update($data['status'] === 'printed'
            ? ['status' => PrintJob::STATUS_PRINTED, 'printed_at' => now(), 'error' => null]
            : [
                // Failed attempts go back to the queue until the limit; then they stay failed.
                'status' => $job->attempts >= PrintJob::MAX_ATTEMPTS ? PrintJob::STATUS_FAILED : PrintJob::STATUS_PENDING,
                'error' => $data['error'] ?? 'Falha ao imprimir',
            ]);

        return response()->json(['ok' => true]);
    }

    private function agent(Request $request): PrintAgent
    {
        $agent = $request->attributes->get('printAgent');

        abort_unless($agent instanceof PrintAgent, 401);

        return $agent;
    }
}
