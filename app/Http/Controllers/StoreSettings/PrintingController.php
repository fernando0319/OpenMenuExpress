<?php

namespace App\Http\Controllers\StoreSettings;

use App\Enums\AutoPrint;
use App\Enums\PrintFont;
use App\Http\Controllers\Concerns\InteractsWithCurrentStore;
use App\Http\Controllers\Controller;
use App\Models\PrintAgent;
use App\Models\Printer;
use App\Models\PrintJob;
use App\Printing\PrintingPairing;
use App\Printing\PrintQueue;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Computers with the print agent, the printers in use and when tickets print.
 */
class PrintingController extends Controller
{
    use InteractsWithCurrentStore;

    public function edit(Request $request): Response
    {
        $store = $this->currentStore();

        return Inertia::render('store-settings/Printing', [
            'autoPrint' => $store->auto_print->value,
            'autoPrintOptions' => array_map(
                fn (AutoPrint $option) => ['value' => $option->value, 'label' => $option->label()],
                AutoPrint::cases(),
            ),
            'pairingCode' => $request->session()->get('print_pairing_code'),
            'serverUrl' => rtrim((string) config('app.url'), '/'),
            'fontOptions' => array_map(
                fn (PrintFont $font) => ['value' => $font->value, 'label' => $font->label()],
                PrintFont::cases(),
            ),
            'downloads' => $this->downloads(),
            'agents' => fn () => $store->printAgents()->with('printers')->orderBy('name')->get()
                ->map(fn (PrintAgent $agent) => [
                    'id' => $agent->id,
                    'name' => $agent->name,
                    'platform' => $agent->platform,
                    'version' => $agent->version,
                    'online' => $agent->isOnline(),
                    'lastSeenAt' => $agent->last_seen_at?->toIso8601String(),
                    'availablePrinters' => $agent->available_printers ?? [],
                    'printers' => $agent->printers->map(fn (Printer $printer) => [
                        'id' => $printer->id,
                        'systemName' => $printer->system_name,
                        'label' => $printer->label,
                        'paperWidth' => $printer->paper_width,
                        'copies' => $printer->copies,
                        'font' => $printer->font->value,
                        'isActive' => $printer->is_active,
                    ]),
                ]),
            'recentJobs' => fn () => $store->printJobs()->with(['printer:id,label', 'order:id,number'])->latest('id')->limit(15)->get()
                ->map(fn (PrintJob $job) => [
                    'id' => $job->id,
                    'printer' => $job->printer->label,
                    'orderNumber' => $job->order?->number,
                    'kind' => $job->kind,
                    'status' => $job->status,
                    'error' => $job->error,
                    'attempts' => $job->attempts,
                    'at' => $job->created_at->toIso8601String(),
                ]),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate(['auto_print' => ['required', Rule::enum(AutoPrint::class)]]);

        $this->currentStore()->update($data);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Preferência de impressão salva.']);

        return to_route('store-settings.printing.edit');
    }

    public function pairingCode(Request $request, PrintingPairing $pairing): RedirectResponse
    {
        $request->session()->flash('print_pairing_code', $pairing->issue($this->currentStore()));

        return to_route('store-settings.printing.edit');
    }

    public function savePrinter(Request $request): RedirectResponse
    {
        $store = $this->currentStore();

        $data = $request->validate([
            'print_agent_id' => ['required', 'integer', Rule::exists('print_agents', 'id')->where('store_id', $store->id)],
            'system_name' => ['required', 'string', 'max:200'],
            'label' => ['required', 'string', 'max:60'],
            'paper_width' => ['required', 'integer', 'in:58,80'],
            'copies' => ['required', 'integer', 'between:1,3'],
            'font' => ['sometimes', Rule::enum(PrintFont::class)],
            'is_active' => ['boolean'],
        ]);

        $store->printers()->updateOrCreate(
            ['print_agent_id' => $data['print_agent_id'], 'system_name' => $data['system_name']],
            $data,
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Impressora salva.']);

        return to_route('store-settings.printing.edit');
    }

    public function destroyPrinter(Printer $printer): RedirectResponse
    {
        $this->ensureBelongsToCurrentStore($printer);

        $printer->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Impressora removida.']);

        return to_route('store-settings.printing.edit');
    }

    public function testPrinter(Printer $printer, PrintQueue $queue): RedirectResponse
    {
        $this->ensureBelongsToCurrentStore($printer);

        $queue->queueTest($printer);

        Inertia::flash('toast', ['type' => 'success', 'message' => "Teste enviado para {$printer->label}."]);

        return back();
    }

    public function destroyAgent(PrintAgent $agent): RedirectResponse
    {
        $this->ensureBelongsToCurrentStore($agent);

        $agent->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Computador desvinculado. O agente nele para de imprimir.']);

        return to_route('store-settings.printing.edit');
    }

    /**
     * The agent builds the deploy publishes in public/downloads (only those present are offered).
     *
     * @return list<array{label: string, url: string}>
     */
    private function downloads(): array
    {
        $files = [
            'menu-express-impressao.exe' => 'Baixar para Windows',
            'menu-express-impressao-linux' => 'Baixar para Linux',
        ];

        $downloads = [];

        foreach ($files as $file => $label) {
            if (is_file(public_path("downloads/{$file}"))) {
                $downloads[] = ['label' => $label, 'url' => asset("downloads/{$file}")];
            }
        }

        return $downloads;
    }
}
