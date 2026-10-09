<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\User;
use Illuminate\Testing\TestResponse;
use Livewire\Mechanisms\HandleRequests\HandleRequests;
use RuntimeException;
use Tests\TestCase;

/**
 * A real Livewire update, as the browser sends it: the page's own snapshot
 * of a component, posted back to Livewire's update route with $refresh. The
 * admin panel's table actions, filters and modals all run on that route, so
 * this is what proves they run in bypass() (PlatformAdminWorksAcrossTenants),
 * which Livewire::test() skips.
 */
final class LivewireUpdate
{
    /** The snapshot of the page component named $component, as $as sees the page at $url. */
    public static function snapshot(TestCase $test, User $as, string $url, string $component): string
    {
        $html = (string) $test->actingAs($as)->get($url)->assertOk()->getContent();
        preg_match_all('/wire:snapshot="([^"]+)"/', $html, $found);

        foreach ($found[1] as $snapshot) {
            $snapshot = htmlspecialchars_decode($snapshot, ENT_QUOTES);
            $name = json_decode($snapshot, true)['memo']['name'] ?? '';

            if (is_string($name) && str_contains($name, class_basename($component))) {
                return $snapshot;
            }
        }

        throw new RuntimeException("No {$component} snapshot on {$url}.");
    }

    /** Posts the snapshot back as $as, re-rendering the component. */
    public static function refresh(TestCase $test, User $as, string $snapshot): TestResponse
    {
        return $test->actingAs($as)->withHeaders(['X-Livewire' => '1'])->postJson(app(HandleRequests::class)->getUpdateUri(), [
            'components' => [[
                'snapshot' => $snapshot,
                'updates' => new \stdClass,
                'calls' => [['path' => '', 'method' => '$refresh', 'params' => []]],
            ]],
        ]);
    }
}
