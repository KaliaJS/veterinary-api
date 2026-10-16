<?php

namespace App\Models;

use App\Enums\ArticlePlanete;
use Illuminate\Database\Eloquent\Model;

/** Objet de la boutique de la planète acheté par un propriétaire (une fois par objet). */
class AchatPlanete extends Model
{
    protected $table = 'achats_planete';

    protected $fillable = ['user_id', 'article', 'stripe_facture_id'];

    protected function casts(): array
    {
        return ['article' => ArticlePlanete::class];
    }
}
