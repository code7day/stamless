<?php

namespace Tests\Feature\Filament;

use App\Enums\BlockTypeEnum;
use App\Enums\PageTypeEnum;
use App\Filament\Resources\PageResource;
use App\Http\Concerns\ResolvesPublicLinks;
use App\Models\Page;
use App\Models\Tenant;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Component;
use Tests\TestCase;

class PageSocialLinksUrlTestComponent extends Component implements HasSchemas
{
    use InteractsWithSchemas;

    public ?Page $record = null;
}

class PageSocialLinksUrlTest extends TestCase
{
    use RefreshDatabase;

    private function getSocialLinksUrlField(): mixed
    {
        $tenant = Tenant::create([
            'name' => 'Test Tenant',
            'slug' => 'test-tenant',
            'is_active' => true,
            'plan' => 'free',
        ]);

        $user = User::create([
            'name' => 'Admin User',
            'email' => 'admin@test.com',
            'password' => bcrypt('password'),
            'tenant_id' => $tenant->id,
        ]);

        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('cms'));
        Filament::setTenant($tenant);

        $footerPage = Page::create([
            'tenant_id' => $tenant->id,
            'title' => 'Footer Principal',
            'slug' => 'footer-principal',
            'type' => PageTypeEnum::Footer->value,
            'lang_iso' => 'es',
        ]);

        $livewire = new PageSocialLinksUrlTestComponent;
        $livewire->record = $footerPage;

        $schema = PageResource::form(
            Schema::make($livewire)->record($footerPage)
        );

        return $this->findUrlComponent($schema->getComponents());
    }

    private function findUrlComponent(mixed $items): mixed
    {
        if (! is_iterable($items)) {
            return null;
        }

        foreach ($items as $item) {
            if (method_exists($item, 'getName') && $item->getName() === 'url' && method_exists($item, 'getPlaceholder') && $item->getPlaceholder() === 'https://www.tiktok.com/@usuario') {
                return $item;
            }

            if (method_exists($item, 'getBlocks')) {
                foreach ($item->getBlocks() as $block) {
                    $found = $this->findUrlComponent($block->getChildComponents());
                    if ($found) {
                        return $found;
                    }
                }
            }

            if (method_exists($item, 'getChildComponents')) {
                $found = $this->findUrlComponent($item->getChildComponents());
                if ($found) {
                    return $found;
                }
            }

            if (method_exists($item, 'getChildComponentContainers')) {
                foreach ($item->getChildComponentContainers() as $container) {
                    $found = $this->findUrlComponent($container->getComponents());
                    if ($found) {
                        return $found;
                    }
                }
            }
        }

        return null;
    }

    public function test_tiktok_url_with_at_sign_passes_validation(): void
    {
        $field = $this->getSocialLinksUrlField();
        $this->assertNotNull($field, 'Social links URL field must be found in PageResource schema.');

        $rules = $field->getValidationRules();

        $v = validator(['url' => 'https://www.tiktok.com/@cicavidafeliz'], ['url' => $rules]);
        $this->assertTrue($v->passes(), 'Standard TikTok URL with @ should pass validation.');
    }

    public function test_linkedin_url_with_accents_and_facebook_with_query_passes_validation(): void
    {
        $field = $this->getSocialLinksUrlField();
        $rules = $field->getValidationRules();

        // Accented URL (as in user screenshot: cica-consultoría)
        $vLinkedIn = validator(['url' => 'https://www.linkedin.com/in/cica-consultoría'], ['url' => $rules]);
        $this->assertTrue($vLinkedIn->passes(), 'LinkedIn URL with accented characters should pass validation.');

        // Protocol-less accented URL
        $vLinkedInNoProto = validator(['url' => 'linkedin.com/in/cica-consultoría '], ['url' => $rules]);
        $this->assertTrue($vLinkedInNoProto->passes(), 'LinkedIn URL without protocol and trailing space should pass validation.');

        // Facebook profile with query params (as in user screenshot)
        $vFacebook = validator(['url' => 'https://www.facebook.com/profile.php?id=61586972725857'], ['url' => $rules]);
        $this->assertTrue($vFacebook->passes(), 'Facebook profile URL with query params should pass validation.');

        $vFacebookNoProto = validator(['url' => 'facebook.com/profile.php?id=61586972725857'], ['url' => $rules]);
        $this->assertTrue($vFacebookNoProto->passes(), 'Facebook URL without protocol should pass validation.');
    }

    public function test_tiktok_url_with_whitespace_and_unicode_spaces_passes_validation(): void
    {
        $field = $this->getSocialLinksUrlField();
        $rules = $field->getValidationRules();

        $vTrailing = validator(['url' => 'https://www.tiktok.com/@cicavidafeliz '], ['url' => $rules]);
        $this->assertTrue($vTrailing->passes(), 'TikTok URL with trailing space should pass validation.');

        $vUnicode = validator(['url' => "https://www.tiktok.com/@cicavidafeliz\u{00a0}"], ['url' => $rules]);
        $this->assertTrue($vUnicode->passes(), 'TikTok URL with non-breaking space should pass validation.');
    }

    public function test_tiktok_url_without_protocol_passes_validation_and_is_dehydrated_with_https(): void
    {
        $field = $this->getSocialLinksUrlField();
        $rules = $field->getValidationRules();

        $vNoProtocol = validator(['url' => 'tiktok.com/@cicavidafeliz'], ['url' => $rules]);
        $this->assertTrue($vNoProtocol->passes(), 'TikTok URL without protocol should pass validation.');

        $vWww = validator(['url' => 'www.tiktok.com/@cicavidafeliz'], ['url' => $rules]);
        $this->assertTrue($vWww->passes(), 'TikTok URL with www should pass validation.');

        // Verify dehydrateStateUsing prefixes https://
        $dehydrated1 = $field->getStateToDehydrate('tiktok.com/@cicavidafeliz');
        $this->assertEquals('https://tiktok.com/@cicavidafeliz', reset($dehydrated1));

        $dehydrated2 = $field->getStateToDehydrate("  https://www.tiktok.com/@cicavidafeliz \u{00a0} ");
        $this->assertEquals('https://www.tiktok.com/@cicavidafeliz', reset($dehydrated2));
    }

    public function test_invalid_url_fails_validation_with_clean_message(): void
    {
        $field = $this->getSocialLinksUrlField();
        $rules = $field->getValidationRules();

        $v = validator(['url' => 'this is not a url'], ['url' => $rules]);
        $this->assertFalse($v->passes(), 'Invalid URL string should fail validation.');
        $this->assertStringContainsString('El campo URL debe ser una dirección web válida.', $v->errors()->first('url'));
    }

    public function test_resolves_public_links_normalizes_social_links_urls(): void
    {
        $transformer = new class
        {
            use ResolvesPublicLinks;

            public function transform(object $block): array
            {
                return $this->transformBlockContent($block, collect(), collect(), collect());
            }
        };

        $block = (object) [
            'type' => BlockTypeEnum::Colophon,
            'content' => [
                'columns' => [
                    [
                        'title' => 'Síguenos',
                        'blocks' => [
                            [
                                'type' => 'social_links',
                                'data' => [
                                    'items' => [
                                        ['platform' => 'tiktok', 'url' => 'https://www.tiktok.com/@cicavidafeliz '],
                                        ['platform' => 'instagram', 'url' => 'instagram.com/cica360'],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $transformed = $transformer->transform($block);
        $items = $transformed['columns'][0]['blocks'][0]['data']['items'];

        $this->assertEquals('https://www.tiktok.com/@cicavidafeliz', $items[0]['url']);
        $this->assertEquals('https://instagram.com/cica360', $items[1]['url']);
    }
}
