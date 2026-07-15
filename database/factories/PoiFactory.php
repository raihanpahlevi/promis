<?php

namespace Database\Factories;

use App\Models\Kantor;
use App\Models\Poi;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Poi>
 *
 * There's no KantorFactory in this codebase (existing tests just call
 * Kantor::create([...]) directly) and Poi doesn't use HasFactory, so this is
 * instantiated directly as `PoiFactory::new()->create([...])` rather than via
 * `Poi::factory()`. Callers that care which kantor a POI belongs to should
 * always override kantor_id explicitly (e.g. for active-kantor scoping tests)
 * — the default here just falls back to an existing kantor, or creates a
 * throwaway one, so the factory is usable standalone.
 */
class PoiFactory extends Factory
{
    protected $model = Poi::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'nama_poi' => fake()->unique()->company(),
            'alamat' => fake()->address(),
            'sektor' => fake()->randomElement(Poi::SEKTOR_OPTIONS),
            'sub_sektor' => null,
            'area' => fake()->randomElement(Poi::AREA_OPTIONS),
            'kantor_id' => fn () => Kantor::query()->inRandomOrder()->value('id')
                ?? Kantor::create([
                    'kode' => strtoupper(Str::random(5)),
                    'nama' => fake()->company(),
                ])->id,
            'status_mitra' => fake()->randomElement(Poi::STATUS_MITRA_OPTIONS),
            'pic' => fake()->name(),
            'latitude' => null,
            'longitude' => null,
            'geocode_status' => 'pending',
            'status' => 'aktif',
            'created_by' => null,
        ];
    }

    public function nonaktif(): static
    {
        return $this->state(fn (array $attributes) => ['status' => 'nonaktif']);
    }
}
