<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Tenant;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Tenant
 */
class TenantResource extends JsonResource
{
    /**
     * Solo se exponen los campos que el POS y la landing necesitan.
     *
     * Deliberadamente NO se expone `tax_id` ni `settings`: el POS no los necesita
     * y un token del POS es de larga vida en la practica. Cuanto menos PII y
     * menos configuracion interna viaje en una respuesta, menos superficie hay.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'slug' => $this->slug,
            'name' => $this->name,
            'logo_url' => $this->logo_url,
            'branding' => [
                'primary_color' => $this->primary_color,
                'secondary_color' => $this->secondary_color,
            ],
            'locale' => [
                'currency' => $this->currency,
                'timezone' => $this->timezone,
            ],
        ];
    }
}
