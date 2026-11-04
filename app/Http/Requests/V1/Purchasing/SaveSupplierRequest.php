<?php

namespace App\Http\Requests\V1\Purchasing;

use App\Auth\ContexteClinique;
use App\Models\Supplier;
use App\Services\Purchasing\PurchasingConnectors;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Un fournisseur de la clinique (03.11.2026). Son canal demande ce qui lui
 * manque : une centrale connue de la configuration, ou une adresse e-mail.
 */
class SaveSupplierRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $supplier = $this->route('supplier');
        $isUpdate = $supplier instanceof Supplier;
        $channel = $this->input('channel', $isUpdate ? $supplier->channel : null);
        $sometimes = $isUpdate ? 'sometimes' : 'required';

        return [
            'name' => [$sometimes, 'string', 'max:120', Rule::unique('suppliers', 'name')
                ->where('clinique_id', app(ContexteClinique::class)->cliniqueId())
                ->ignore($isUpdate ? $supplier->id : null)],
            'channel' => [$sometimes, Rule::in(Supplier::CHANNELS)],
            'connector' => [Rule::requiredIf($channel === 'api' && (! $isUpdate || $this->has('channel'))), 'nullable', Rule::in(app(PurchasingConnectors::class)->keys())],
            'email' => [Rule::requiredIf($channel === 'email' && (! $isUpdate || $this->has('channel') || blank($supplier->email))), 'nullable', 'email', 'max:255'],
            'minimum_order' => ['sometimes', 'numeric', 'min:0', 'max:99999'],
            'order_days' => ['sometimes', 'nullable', 'array', 'max:7'],
            'order_days.*' => ['integer', 'between:1,7', 'distinct'],
            'order_time' => ['sometimes', 'nullable', 'date_format:H:i'],
            'under_minimum' => ['sometimes', Rule::in(Supplier::UNDER_MINIMUM)],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'name.required' => 'Donnez le nom du fournisseur.',
            'name.unique' => 'Ce fournisseur est déjà dans la liste de la clinique.',
            'channel.in' => 'Choisissez comment on lui commande : par sa centrale, par e-mail ou à la main.',
            'connector.required' => 'Choisissez sa centrale d’achat.',
            'connector.in' => 'Cette centrale d’achat n’est pas connue.',
            'email.required' => 'Donnez l’adresse e-mail des commandes.',
            'email.email' => 'Cette adresse e-mail n’est pas valable.',
            'minimum_order.min' => 'Le minimum de commande ne peut pas être négatif.',
            'order_days.*.between' => 'Un jour de commande va du lundi (1) au dimanche (7).',
            'order_time.date_format' => 'Donnez l’heure au format HH:MM.',
            'under_minimum.in' => 'Choisissez : demander, envoyer quand même, ou attendre la date suivante.',
        ];
    }
}
