<?php

namespace App\Http\Controllers\Api\V1;

use App\Auth\ContexteClinique;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\DossierMedical\NeuroExamRequest;
use App\Http\Resources\V1\NeuroExamResource;
use App\Models\NeuroExam;
use App\Models\Patient;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * Le schéma neurologique d'un animal (B, choix de Jim, 08.11.2026) : les
 * examens, du plus récent au plus ancien. Un examen se modifie et s'efface
 * le jour même, par l'organisation qui l'a fait ; ensuite il se relit
 * seulement. Une modification remplace chaque patte et chaque nerf envoyés
 * en entier, le dos s'il est envoyé ; le reste ne bouge pas.
 */
class NeuroExamController extends Controller
{
    public function index(Request $request, Patient $patient): AnonymousResourceCollection
    {
        $this->authorize('view', $patient);

        return NeuroExamResource::collection(
            $patient->neuroExams()->orderByDesc('day')->orderByDesc('id')->paginate($this->parPage($request)),
        );
    }

    public function store(NeuroExamRequest $request, Patient $patient): NeuroExamResource
    {
        $this->authorize('update', $patient);

        $data = $request->validated();
        $exam = $patient->neuroExams()->create([
            'day' => aujourdhui()->toDateString(),
            'mental_status' => $data['mental_status'] ?? null,
            'gait_score' => $data['gait_score'] ?? null,
            'limbs' => NeuroExam::normalizeLimbs($data['limbs'] ?? null),
            'spine' => NeuroExam::normalizeSpine($data['spine'] ?? null),
            'cranial_nerves' => NeuroExam::normalizeCranialNerves($data['cranial_nerves'] ?? null),
            'note' => $data['note'] ?? null,
            'by_id' => $request->user()?->id,
            'by_name' => app(ContexteClinique::class)->employe()->nom ?? $request->user()?->name,
        ]);

        return new NeuroExamResource($exam);
    }

    public function update(NeuroExamRequest $request, Patient $patient, int $exam): NeuroExamResource
    {
        $this->authorize('update', $patient);
        $record = $this->editable($patient, $exam);

        $data = $request->validated();
        foreach (['mental_status', 'gait_score', 'note'] as $field) {
            if (array_key_exists($field, $data)) {
                $record->{$field} = $data[$field];
            }
        }
        if (isset($data['limbs'])) {
            $record->limbs = NeuroExam::normalizeLimbs([...NeuroExam::normalizeLimbs($record->limbs), ...$data['limbs']]);
        }
        if (isset($data['spine'])) {
            $record->spine = NeuroExam::normalizeSpine($data['spine']);
        }
        if (isset($data['cranial_nerves'])) {
            $record->cranial_nerves = NeuroExam::normalizeCranialNerves([...NeuroExam::normalizeCranialNerves($record->cranial_nerves), ...$data['cranial_nerves']]);
        }
        $record->save();

        return new NeuroExamResource($record);
    }

    public function destroy(Patient $patient, int $exam): Response
    {
        $this->authorize('update', $patient);
        $this->editable($patient, $exam)->delete();

        return response()->noContent();
    }

    private function editable(Patient $patient, int $exam): NeuroExam
    {
        /** @var NeuroExam $record */
        $record = $patient->neuroExams()->findOrFail($exam);
        abort_unless($record->isEditableBy(app(ContexteClinique::class)->organisationId()), 403, 'Un examen passé, ou fait par une autre organisation, se relit sans se modifier.');

        return $record;
    }
}
