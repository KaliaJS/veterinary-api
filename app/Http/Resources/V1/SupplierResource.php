<?php

namespace App\Http\Resources\V1;

use App\Models\Supplier;
use App\Services\Purchasing\PurchasingConnectors;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Un fournisseur de la clinique (03.11.2026), avec le nom de sa centrale et
 * si la clinique l'a reliée, et sa prochaine date butoir (servie, en UTC).
 *
 * @mixin Supplier
 */
class SupplierResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $connector = $this->channel === 'api' ? app(PurchasingConnectors::class)->get($this->connector) : null;

        return [
            'id' => $this->id,
            'name' => $this->name,
            'channel' => $this->channel,
            'connector' => $this->channel === 'api' ? $this->connector : null,
            'connector_name' => $connector?->name(),
            'is_linked' => $connector !== null && $connector->isLinked((int) $this->clinique_id),
            'email' => $this->email,
            'minimum_order' => (float) $this->minimum_order,
            'order_days' => array_values(array_map('intval', $this->order_days ?? [])),
            'order_time' => $this->order_time,
            'under_minimum' => $this->under_minimum,
            'next_deadline_at' => $this->resource->nextDeadline()?->toIso8601String(),
        ];
    }
}
