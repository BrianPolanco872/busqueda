<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class CompiledAssetsTest extends TestCase
{
    public function test_home_uses_real_compiled_resources_and_every_manifest_dependency_exists_locally(): void
    {
        $manifestPath = public_path('build/manifest.json');
        $releasePath = public_path('build/release.json');
        if (! is_file($manifestPath) || ! is_file($releasePath)) {
            $this->markTestSkipped('Pendiente de build.sh con versión/tag válidos; un build antiguo sin sello de release no valida esta entrega.');
        }
        $release = json_decode(file_get_contents($releasePath), true, 512, JSON_THROW_ON_ERROR);
        $package = json_decode(file_get_contents(base_path('package.json')), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame($package['version'], $release['version']);
        $this->assertSame(hash_file('sha256', $manifestPath), $release['manifest_sha256']);
        $head = new Process(['git', 'rev-parse', 'HEAD'], base_path());
        $head->mustRun();
        $this->assertSame(trim($head->getOutput()), $release['commit']);
        $sources = new Process(['git', 'diff', '--quiet', 'HEAD', '--', 'resources', 'vite.config.js', 'package.json', 'package-lock.json', 'build.sh'], base_path());
        $this->assertSame(0, $sources->run(), 'Las fuentes cambiaron después del build identificado por el tag.');
        $this->assertFileDoesNotExist(public_path('hot'), 'La entrega offline no debe depender del servidor Vite.');
        Storage::fake('local');
        $manifest = json_decode(file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
        $this->assertArrayHasKey('resources/js/app.js', $manifest);
        $this->assertArrayHasKey('resources/css/app.css', $manifest);
        $response = $this->get('/')->assertOk();
        foreach ($manifest as $entry) {
            foreach ([$entry['file'], ...($entry['css'] ?? []), ...($entry['assets'] ?? [])] as $file) {
                $this->assertDoesNotMatchRegularExpression('~^(?:https?:)?//|\.\.~', $file);
                $this->assertFileExists(public_path('build/'.$file));
            }
            foreach ($entry['imports'] ?? [] as $import) {
                $this->assertArrayHasKey($import, $manifest);
            }
        }
        $response->assertSee('/build/'.$manifest['resources/js/app.js']['file'], false)
            ->assertSee('/build/'.$manifest['resources/css/app.css']['file'], false)
            ->assertDontSee('fonts.bunny.net')->assertDontSee('tile.openstreetmap.org');
    }
}
