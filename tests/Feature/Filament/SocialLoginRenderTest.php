<?php

namespace Tests\Feature\Filament;

use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SocialLoginRenderTest extends TestCase
{
    use RefreshDatabase;

    public function test_social_login_buttons_hidden_when_env_credentials_not_set(): void
    {
        config([
            'services.google.client_id' => null,
            'services.google.client_secret' => null,
            'services.linkedin-openid.client_id' => null,
            'services.linkedin-openid.client_secret' => null,
            'services.twitter-oauth-2.client_id' => null,
            'services.twitter-oauth-2.client_secret' => null,
            'services.instagram.client_id' => null,
            'services.instagram.client_secret' => null,
            'services.facebook.client_id' => null,
            'services.facebook.client_secret' => null,
            'services.microsoft.client_id' => null,
            'services.microsoft.client_secret' => null,
        ]);

        $cmsUrl = Filament::getPanel('cms')->getLoginUrl();
        $response = $this->get($cmsUrl);

        $response->assertSuccessful();
        $response->assertDontSee('oauth/google');
        $response->assertDontSee('oauth/linkedin-openid');
        $response->assertDontSee('oauth/twitter-oauth-2');
        $response->assertDontSee('oauth/instagram');
        $response->assertDontSee('oauth/facebook');
        $response->assertDontSee('oauth/microsoft');
    }

    public function test_social_login_buttons_visible_when_credentials_configured(): void
    {
        config([
            'services.google.client_id' => 'google-client-id',
            'services.google.client_secret' => 'google-secret',
            'services.facebook.client_id' => 'fb-client-id',
            'services.facebook.client_secret' => 'fb-secret',
            'services.twitter-oauth-2.client_id' => 'x-client-id',
            'services.twitter-oauth-2.client_secret' => 'x-secret',
            'services.linkedin-openid.client_id' => null,
            'services.linkedin-openid.client_secret' => null,
        ]);

        $cmsUrl = Filament::getPanel('cms')->getLoginUrl();
        $response = $this->get($cmsUrl);

        $response->assertSuccessful();
        $response->assertSee('O inicia sesión con');
        $response->assertSee('Google');
        $response->assertSee('Facebook');
        $response->assertSee('X');
        $response->assertDontSee('LinkedIn');

        $content = $response->getContent();
        $this->assertSame(1, substr_count($content, 'oauth/google'));
        $this->assertSame(1, substr_count($content, 'oauth/facebook'));
        $this->assertSame(1, substr_count($content, 'oauth/twitter-oauth-2'));
    }

    public function test_platform_login_page_renders_configured_providers_only(): void
    {
        config([
            'services.microsoft.client_id' => 'ms-client-id',
            'services.microsoft.client_secret' => 'ms-secret',
            'services.instagram.client_id' => 'insta-client-id',
            'services.instagram.client_secret' => 'insta-secret',
            'services.google.client_id' => null,
            'services.google.client_secret' => null,
        ]);

        $platformUrl = Filament::getPanel('platform')->getLoginUrl();
        $response = $this->get($platformUrl);

        $response->assertSuccessful();
        $response->assertSee('Microsoft');
        $response->assertSee('Instagram');
        $response->assertDontSee('Google');

        $content = $response->getContent();
        $this->assertSame(1, substr_count($content, 'oauth/microsoft'));
        $this->assertSame(1, substr_count($content, 'oauth/instagram'));
    }
}
