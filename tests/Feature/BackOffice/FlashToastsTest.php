<?php

namespace Tests\Feature\BackOffice;

use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

class FlashToastsTest extends TestCase
{
    public function test_success_and_error_messages_render_as_fixed_bottom_right_toasts(): void
    {
        $renderedToasts = Blade::render('<x-flash-toasts status="Enregistré" error="Échec" />');

        $this->assertStringContainsString('fixed', $renderedToasts);
        $this->assertStringContainsString('bottom-4', $renderedToasts);
        $this->assertStringContainsString('Enregistré', $renderedToasts);
        $this->assertStringContainsString('Échec', $renderedToasts);
    }

    public function test_nothing_is_rendered_without_a_message(): void
    {
        $this->assertSame('', trim(Blade::render('<x-flash-toasts />')));
    }
}
