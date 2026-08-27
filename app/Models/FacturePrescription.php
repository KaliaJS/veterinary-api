<?php

namespace App\Models;

use App\Enums\ModePrescription;
use Database\Factories\FacturePrescriptionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Prescription jointe à la facture (`rx` du front) — imprimable depuis
 * l'encaissement ; le registre des ordonnances reste l'entité de
 * référence quand il existe (factures.ordonnance_id).
 */
class FacturePrescription extends Model
{
    /** @use HasFactory<FacturePrescriptionFactory> */
    use HasFactory;

    protected $fillable = ['facture_id', 'nom', 'posologie', 'mode'];

    protected function casts(): array
    {
        return [
            'mode' => ModePrescription::class,
        ];
    }

    /** @return BelongsTo<Facture, $this> */
    public function facture(): BelongsTo
    {
        return $this->belongsTo(Facture::class);
    }
}
