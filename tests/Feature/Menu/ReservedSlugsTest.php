<?php

namespace Tests\Feature\Menu;

use App\Http\Requests\StoreCreateRequest;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as RouteFacade;
use Tests\TestCase;

class ReservedSlugsTest extends TestCase
{
    /**
     * The menu is served at /{slug}; a store whose slug equals an app path would be unreachable
     * (or would hide the app page). Any new top-level route must be added to RESERVED_SLUGS.
     */
    public function test_every_top_level_route_segment_is_reserved(): void
    {
        $segments = collect(RouteFacade::getRoutes()->getRoutes())
            ->map(fn (Route $route) => explode('/', $route->uri())[0])
            ->filter(fn (string $segment) => $segment !== '' && ! str_contains($segment, '{') && ! str_starts_with($segment, '.') && ! str_starts_with($segment, '_'))
            ->unique()
            ->values();

        $missing = $segments->diff(StoreCreateRequest::RESERVED_SLUGS)->values()->all();

        $this->assertSame([], $missing, 'Add these to StoreCreateRequest::RESERVED_SLUGS: '.implode(', ', $missing));
    }
}
