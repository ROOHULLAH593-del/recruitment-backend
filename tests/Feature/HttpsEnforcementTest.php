<?php

namespace Tests\Feature;

use App\Providers\AppServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class HttpsEnforcementTest extends TestCase
{
    public function test_generated_urls_use_https_in_production(): void
    {
        $this->app['env'] = 'production';
        (new AppServiceProvider($this->app))->boot();

        $this->assertStringStartsWith('https://', url('/some-path'));
    }

    public function test_generated_urls_keep_the_request_scheme_outside_production(): void
    {
        $this->assertStringStartsWith('http://', url('/some-path'));
    }

    public function test_forwarded_proto_and_client_ip_are_trusted_behind_the_proxy(): void
    {
        Route::get('/proxy-probe', fn (Request $request) => response()->json([
            'secure' => $request->isSecure(),
            'ip' => $request->ip(),
        ]));

        $this->getJson('/proxy-probe', [
            'X-Forwarded-Proto' => 'https',
            'X-Forwarded-For' => '203.0.113.7',
        ])->assertOk()->assertExactJson([
            'secure' => true,
            'ip' => '203.0.113.7',
        ]);
    }
}
