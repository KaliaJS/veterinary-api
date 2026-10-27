<?php

namespace App\Http\Controllers\Api\V1\Plateforme;

use App\Http\Controllers\Controller;
use App\Mail\InvitationAccesEnvoyee;
use App\Models\InvitationAcces;
use App\Models\User;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

/**
 * Accès sur invitation (espace Plateforme, 27.10.2026) : la liste des
 * adresses qui peuvent créer leur compte. Une adresse ajoutée reçoit un
 * e-mail ; une invitation se retire tant que le compte n'est pas créé.
 */
class InvitationAccesPlateformeController extends Controller
{
    public function index(): JsonResponse
    {
        $invitations = InvitationAcces::query()->with('invitant')->latest()->latest('id')->get();

        return response()->json(['data' => $this->resumes($invitations)]);
    }

    public function store(Request $request): JsonResponse
    {
        $request->merge(['email' => mb_strtolower(trim((string) $request->input('email')))]);
        $donnees = $request->validate([
            'email' => [
                'required', 'email', 'max:190',
                'unique:invitations_acces,email',
                function (string $attribut, mixed $email, Closure $refuser): void {
                    if (User::query()->whereRaw('lower(email) = ?', [$email])->exists()) {
                        $refuser('Cette adresse a déjà un compte.');
                    }
                },
            ],
            'note' => ['nullable', 'string', 'max:190'],
        ], [
            'email.required' => 'Indiquez une adresse e-mail.',
            'email.email' => 'Cette adresse e-mail n’est pas valable.',
            'email.unique' => 'Cette adresse est déjà invitée.',
        ]);

        $invitation = InvitationAcces::query()->create([
            'email' => $donnees['email'],
            'note' => $donnees['note'] ?? null,
            'invitee_par' => $request->user()->id,
        ]);
        Mail::to($invitation->email)->send(new InvitationAccesEnvoyee);

        return response()->json(['data' => $this->resumes(collect([$invitation->load('invitant')]))[0]], 201);
    }

    public function destroy(InvitationAcces $invitation): Response
    {
        if ($this->resumes(collect([$invitation]))[0]['compte_cree']) {
            throw ValidationException::withMessages(['invitation' => 'Le compte est déjà créé : cette invitation ne se retire plus.']);
        }
        $invitation->delete();

        return response()->noContent();
    }

    /**
     * Compte créé : par cette invitation, ou par une autre voie (équipe,
     * fiche client, compte ouvert par une clinique).
     *
     * @param  Collection<int, InvitationAcces>  $invitations
     * @return list<array<string, mixed>>
     */
    private function resumes(Collection $invitations): array
    {
        $avecCompte = $invitations->isEmpty() ? collect() : User::query()
            ->whereIn(DB::raw('lower(email)'), $invitations->pluck('email')->all())
            ->pluck('email')
            ->map(fn (string $email): string => mb_strtolower($email));

        return $invitations->map(fn (InvitationAcces $invitation): array => [
            'id' => $invitation->id,
            'email' => $invitation->email,
            'note' => $invitation->note,
            'invitee_par' => $invitation->invitant?->name,
            'invitee_le' => $invitation->created_at?->toIso8601String(),
            'compte_cree' => $invitation->utilisee_le !== null || $avecCompte->contains($invitation->email),
        ])->values()->all();
    }
}
