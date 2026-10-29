<?php

namespace App\Http\Controllers\Courier;

use App\Http\Controllers\Controller;
use App\Http\Requests\Courier\CourierRegistrationRequest;
use App\Models\User;
use App\Services\CourierNetwork\CourierRegistry;
use App\Support\NetworkSettings;
use App\Support\PasswordPolicy;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Signing up to deliver for the network. The platform owner reviews each registration.
 */
class CourierRegistrationController extends Controller
{
    public function create(Request $request): Response|RedirectResponse
    {
        $user = $request->user();

        if ($user instanceof User && $user->networkCourier()->exists()) {
            return redirect()->route('courier.home');
        }

        return Inertia::render('courier/Register', [
            'signedIn' => $user instanceof User ? ['name' => $user->name, 'email' => $user->email] : null,
            'platformFeePercent' => NetworkSettings::all()['platform_fee_bps'] / 100,
            'passwordRules' => PasswordPolicy::light()->toPasswordRulesString(),
            ...CourierAppController::formOptions(),
        ]);
    }

    public function store(CourierRegistrationRequest $request, CourierRegistry $registry): RedirectResponse
    {
        $data = $request->validated();
        $document = $request->file('document');
        abort_unless($document instanceof UploadedFile, 422);
        $isNewAccount = ! $request->user() instanceof User;

        $user = DB::transaction(function () use ($request, $registry, $data, $document): User {
            $user = $request->user();

            if (! $user instanceof User) {
                $user = User::query()->create([
                    'name' => $data['name'],
                    'email' => $data['email'],
                    'password' => $data['password'],
                ]);
            }

            $registry->register($user, ['cpf' => $data['cpf'], ...$request->profile()], $document);

            return $user;
        });

        if ($isNewAccount) {
            event(new Registered($user));

            Auth::login($user);
            $request->session()->regenerate();
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Cadastro enviado! Avisaremos quando for aprovado.']);

        return redirect()->route('courier.home');
    }
}
