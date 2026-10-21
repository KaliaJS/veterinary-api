<?php

namespace App\Models;

use App\Enums\DecisionVerification as Decision;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Une demande ou une décision de vérification d'un vétérinaire (historique). */
class DecisionVerification extends Model
{
    protected $table = 'decisions_verification';

    protected $fillable = ['decision', 'note', 'moderateur_id'];

    protected function casts(): array
    {
        return ['decision' => Decision::class];
    }

    /** @return BelongsTo<User, $this> */
    public function moderateur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'moderateur_id');
    }
}
