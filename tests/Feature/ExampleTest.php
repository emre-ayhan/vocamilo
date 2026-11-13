<?php

namespace Tests\Feature;

use Tests\TestCase;

class ExampleTest extends TestCase
{
    public function test_login_includes_the_branded_svg_favicon(): void
    {
        $this->get('/login')->assertOk()->assertSee('favicon.svg', false)->assertSee('image/svg+xml', false);
        $icon = simplexml_load_file(public_path('favicon.svg'));
        $this->assertSame('0 0 64 64', (string) $icon['viewBox']);
        $this->assertSame(1, count($icon->circle));
    }

    public function test_root_redirects_guests_to_login(): void
    {
        $this->get('/')->assertRedirect('/login');
    }
}
