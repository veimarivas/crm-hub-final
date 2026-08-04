<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAccount;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

#[Fillable(['account_id', 'name', 'color'])]
class Tag extends Model
{
    use BelongsToAccount, HasUuids;

    public const UPDATED_AT = null;

    /**
     * La etiqueta que reciben los leads que entran solos (WhatsApp, formulario,
     * anuncio). Existe para poder difundir "a los que llegaron y todavía no
     * trabajamos" sin tener que etiquetar a mano uno por uno.
     */
    public const NEW_LEAD = 'Nuevo';

    public const NEW_LEAD_COLOR = '#0ea5e9';

    /** Lados inversos del pivot polimórfico: los usan los contadores de uso. */
    public function leads(): MorphToMany
    {
        return $this->morphedByMany(Lead::class, 'taggable');
    }

    public function contacts(): MorphToMany
    {
        return $this->morphedByMany(Contact::class, 'taggable');
    }

    public function companies(): MorphToMany
    {
        return $this->morphedByMany(Company::class, 'taggable');
    }
}
