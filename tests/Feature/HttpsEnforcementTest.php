<?php

namespace Tests\Feature;

use App\Providers\AppServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class HttpsEnforcementTest extends TestCase
{
    public function test_generated_urls_use_https_when_app_url_is_https(): void
    {
        config(['app.url' => 'https://recruitment.example.com']);
        (new AppServiceProvider($this->app))->boot();

        $this->assertStringStartsWith('https://', url('/some-path'));
    }

    public function test_a_production_deployment_with_an_http_app_url_is_not_forced_to_https(): void
    {
        // e.g. a host without working HTTPS: production alone must not
        // imply https:// links.
        $this->app['env'] = 'production';
        config(['app.url' => 'http://recruitment.example.com']);
        (new AppServiceProvider($this->app))->boot();

        $this->assertStringStartsWith('http://', url('/some-path'));
    }

    public function test_generated_urls_keep_the_request_scheme_by_default(): void
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
