<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Une conversation mise en mode silencieux par une personne (08.11.2026).
 * Pas de scope global : toujours lu par `user_id` (MessageMutes).
 *
 * @property int $id
 * @property int $user_id
 * @property int|null $clinique_id
 * @property string $thread
 */
class MessageMute extends Model
{
    protected $fillable = ['user_id', 'clinique_id', 'thread'];
}
