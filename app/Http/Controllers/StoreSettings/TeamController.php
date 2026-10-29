<?php

namespace App\Http\Controllers\StoreSettings;

use App\Enums\StoreRole;
use App\Http\Controllers\Concerns\InteractsWithCurrentStore;
use App\Http\Controllers\Controller;
use App\Models\AdminAuditLog;
use App\Models\StoreMember;
use App\Models\User;
use App\Services\Team\TeamService;
use App\Support\CurrentStore;
use App\Support\PasswordPolicy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The store's team: owners and managers add people and choose their role.
 */
class TeamController extends Controller
{
    use InteractsWithCurrentStore;

    public function __construct(private readonly TeamService $team) {}

    public function index(Request $request): Response
    {
        $store = $this->currentStore();
        $actor = $this->actorRole();

        $members = StoreMember::query()
            ->where('store_id', $store->id)
            ->with('user')
            ->get()
            ->sortBy(fn (StoreMember $membership) => $membership->user->name ?? '')
            ->map(function (StoreMember $membership) use ($request, $actor): ?array {
                $member = $membership->user;

                if (! $member instanceof User) {
                    return null;
                }

                $role = $membership->role;
                $isYou = $member->id === $request->user()?->id;

                return [
                    'id' => $member->id,
                    'name' => $member->name,
                    'login' => $member->login(),
                    'role' => $role->value,
                    'roleLabel' => $role->label(),
                    'isYou' => $isYou,
                    'canManage' => ! $isYou && $this->team->canManage($actor, $role),
                    'canResetPassword' => ! $isYou && $this->team->canManage($actor, $role) && $this->team->canResetPassword($member),
                ];
            })
            ->filter()
            ->sortBy(fn (array $member) => array_search($member['role'], array_column(StoreRole::cases(), 'value'), true))
            ->values();

        return Inertia::render('store-settings/Team', [
            // Transparency: every time the platform support opened this store, and what it changed.
            'supportAccesses' => $actor === StoreRole::Owner ? AdminAuditLog::query()
                ->where('store_id', $store->id)
                ->whereIn('action', [AdminAuditLog::ACTION_SUPPORT_START, AdminAuditLog::ACTION_SUPPORT_CHANGE])
                ->latest('id')
                ->limit(15)
                ->get()
                ->map(fn (AdminAuditLog $log) => [
                    'id' => $log->id,
                    'action' => $log->actionLabel(),
                    'reason' => is_string($log->details['reason'] ?? null) ? $log->details['reason'] : null,
                    'path' => is_string($log->details['path'] ?? null) ? $log->details['path'] : null,
                    'at' => $log->created_at?->toIso8601String(),
                ]) : [],
            'members' => $members,
            // Managers need the strong password; the rest of the team, the lighter one.
            'passwordRules' => [
                'strong' => PasswordPolicy::strong()->toPasswordRulesString(),
                'light' => PasswordPolicy::light()->toPasswordRulesString(),
            ],
            'roles' => array_map(
                fn (StoreRole $role) => ['value' => $role->value, 'label' => $role->label(), 'description' => $this->roleDescription($role)],
                $this->team->assignableRoles($actor),
            ),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $request->merge([
            'username' => $request->filled('username') ? Str::lower(trim((string) $request->input('username'))) : null,
            'email' => $request->filled('email') ? trim((string) $request->input('email')) : null,
        ]);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'required_without:username', 'string', 'email', 'max:255'],
            'username' => ['nullable', 'required_without:email', 'string', 'min:3', 'max:40', 'regex:/^[a-z0-9._-]+$/', Rule::unique('users', 'username')],
            'password' => ['nullable', 'string', PasswordPolicy::forRole(StoreRole::tryFrom((string) $request->input('role')) ?? StoreRole::Owner)],
            'role' => ['required', Rule::enum(StoreRole::class)],
        ], [
            'email.required_without' => 'Informe um e-mail ou um usuário para o login.',
            'username.required_without' => 'Informe um e-mail ou um usuário para o login.',
            'username.regex' => 'Use só letras minúsculas, números, ponto, hífen ou sublinhado.',
            'username.unique' => 'Esse usuário já existe. Escolha outro.',
        ]);

        $result = $this->team->add($this->currentStore(), $this->actorRole(), StoreRole::from($data['role']), [
            'name' => $data['name'],
            'email' => $data['email'] ?? null,
            'username' => $data['username'] ?? null,
            'password' => $data['password'] ?? null,
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => $result['created']
            ? "{$result['user']->name} foi adicionado(a). Login: {$result['user']->login()}"
            : "{$result['user']->name} já tinha conta e foi adicionado(a) à equipe."]);

        return back();
    }

    public function update(Request $request, User $member): RedirectResponse
    {
        $this->ensureNotYou($request, $member);

        $data = $request->validate(['role' => ['required', Rule::enum(StoreRole::class)]]);

        $this->team->changeRole($this->currentStore(), $this->actorRole(), $member, StoreRole::from($data['role']));

        Inertia::flash('toast', ['type' => 'success', 'message' => "Papel de {$member->name} atualizado."]);

        return back();
    }

    public function resetPassword(Request $request, User $member): RedirectResponse
    {
        $this->ensureNotYou($request, $member);

        $data = $request->validate(['password' => ['required', 'string', PasswordPolicy::forUser($member)]]);

        $this->team->resetPassword($this->currentStore(), $this->actorRole(), $member, $data['password']);

        Inertia::flash('toast', ['type' => 'success', 'message' => "Senha de {$member->name} alterada."]);

        return back();
    }

    public function destroy(Request $request, User $member): RedirectResponse
    {
        $this->ensureNotYou($request, $member);

        $this->team->remove($this->currentStore(), $this->actorRole(), $member);

        Inertia::flash('toast', ['type' => 'success', 'message' => "{$member->name} foi removido(a) da equipe."]);

        return back();
    }

    private function actorRole(): StoreRole
    {
        $role = app(CurrentStore::class)->role();
        abort_if($role === null, 403);

        return $role;
    }

    private function ensureNotYou(Request $request, User $member): void
    {
        if ($member->id === $request->user()?->id) {
            throw ValidationException::withMessages(['member' => 'Você não pode alterar o seu próprio acesso aqui.']);
        }
    }

    private function roleDescription(StoreRole $role): string
    {
        return match ($role) {
            StoreRole::Owner => 'Acesso total, inclusive equipe e configurações.',
            StoreRole::Manager => 'Catálogo, configurações, PDV e caixa. Gerencia atendentes, garçons e entregadores.',
            StoreRole::Attendant => 'Pedidos, PDV, caixa e mesas.',
            StoreRole::Waiter => 'Só as mesas: abre, lança pedidos e pede a conta.',
            StoreRole::Courier => 'Só as entregas dele: endereço, contato do cliente e compartilhamento da localização.',
        };
    }
}
