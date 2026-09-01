<?php

namespace Tests\Feature;

use Tests\TestCase;

class ExampleTest extends TestCase
{
    private function landingUrl(string $path = '/'): string
    {
        return rtrim(config('app.url'), '/').'/'.ltrim($path, '/');
    }

    private function apiUrl(string $path = '/'): string
    {
        return rtrim(config('stamless.urls.api'), '/').'/'.ltrim($path, '/');
    }

    /**
     * Test that the landing domain returns the teaser page.
     */
    public function test_landing_domain_returns_teaser(): void
    {
        $response = $this->get($this->landingUrl('/'));

        $response->assertStatus(200);
        $response->assertSee('Stamless');
        $response->assertSee('Una fuente. Todos los sitios.');
    }

    /**
     * Test that the API domain root returns a native 404 response.
     */
    public function test_api_domain_root_returns_404(): void
    {
        $response = $this->get($this->apiUrl('/'));

        $response->assertStatus(404);
    }

    /**
     * Test that the API domain health check returns a JSON status response.
     */
    public function test_api_domain_health_returns_status(): void
    {
        $response = $this->get($this->apiUrl('/v1/health'));

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'status',
            'timestamp',
            'services' => [
                'database',
            ],
        ]);
    }

    /**
     * Test that requesting /api on the main domain returns an empty 404 response.
     */
    public function test_landing_domain_api_path_returns_empty_404(): void
    {
        $response = $this->get($this->landingUrl('/api'));

        $response->assertStatus(404);
        $this->assertEmpty($response->getContent());
    }

    /**
     * Test that requesting /graphql on the main domain returns an empty 404 response.
     */
    public function test_landing_domain_graphql_path_returns_empty_404(): void
    {
        $response = $this->get($this->landingUrl('/graphql'));

        $response->assertStatus(404);
        $this->assertEmpty($response->getContent());
    }

    /**
     * Test that requesting /graphql on the API domain returns an empty 404 response.
     */
    public function test_api_domain_graphql_path_returns_empty_404(): void
    {
        $response = $this->get($this->apiUrl('/graphql'));

        $response->assertStatus(404);
        $this->assertEmpty($response->getContent());
    }

    /**
     * Test that other unexpected domains redirect to the main app URL.
     */
    public function test_other_domains_redirect_to_landing(): void
    {
        $host = parse_url(config('app.url'), PHP_URL_HOST);
        $response = $this->get('https://unknown-subdomain.'.$host.'/');

        $response->assertRedirect(config('app.url'));
    }
}
