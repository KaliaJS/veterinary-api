<?php

namespace App\Models;

use App\Enums\StatutDecompteAssurance;
use Database\Factories\DecompteAssuranceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Décompte d'assurance d'une facture (0..1) — sans scope propre : il se
 * résout toujours via sa facture. Suivi à envoyer → envoyée →
 * remboursée, horodaté à chaque avancée.
 */
class DecompteAssurance extends Model
{
    /** @use HasFactory<DecompteAssuranceFactory> */
    use HasFactory;

    protected $table = 'decomptes_assurance';

    protected $fillable = ['facture_id', 'assureur', 'statut', 'envoye_le', 'rembourse_le'];

    /** Miroir du défaut de la base. */
    protected $attributes = [
        'statut' => 'todo',
    ];

    protected function casts(): array
    {
        return [
            'statut' => StatutDecompteAssurance::class,
            'envoye_le' => 'datetime',
            'rembourse_le' => 'datetime',
        ];
    }

    /** @return BelongsTo<Facture, $this> */
    public function facture(): BelongsTo
    {
        return $this->belongsTo(Facture::class);
    }
}
