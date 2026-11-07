<?php

declare(strict_types=1);

namespace Kestello\Tests\I18n;

use Kestello\Cms\I18n\BrowserCatalogue;
use Kestello\Cms\I18n\Labels;
use Kestello\Cms\I18n\SiteLanguage;
use Kestello\Framework\Testing\TestCase;
use Kestello\Support\Translator;

/** The site language, the catalogue the admin downloads, and the packs behind them. */
final class LanguagesTest extends TestCase
{
    protected function tearDown(): void
    {
        Translator::reset();
    }

    public function testTheSettingWinsOverConfigAndEnglishIsTheLastResort(): void
    {
        $this->assertSame('sr', SiteLanguage::resolve('sr', 'de'));
        $this->assertSame('de', SiteLanguage::resolve('', 'de'), 'no setting: config');
        $this->assertSame('de', SiteLanguage::resolve('../etc', 'de'), 'a malformed setting is ignored');
        $this->assertSame('en', SiteLanguage::resolve(null, null));
    }

    public function testChoicesAreEnglishThenTheInstalledPacks(): void
    {
        $choices = SiteLanguage::choices(['de', 'sr_Latn']);
        $this->assertSame(['en', 'de', 'sr_Latn'], array_keys($choices));
        if (class_exists(\Locale::class)) {
            $this->assertSame('English', $choices['en']);
            $this->assertSame('Deutsch', $choices['de'], 'each language in its own words');
        }
    }

    public function testPacksAreFoundAndMergedRegionOverLanguage(): void
    {
        [$core, $plugin] = [$this->tempDir(), $this->tempDir()];
        file_put_contents("{$core}/sr.json", json_encode(['Save' => 'Sačuvaj', 'Cancel' => 'Otkaži', 'Empty' => '']));
        file_put_contents("{$core}/sr_Latn_RS.json", json_encode(['Save' => 'Snimi']));
        file_put_contents("{$core}/de-CH.php", "<?php return ['Save' => 'Speichern'];");
        file_put_contents("{$core}/notes.txt", 'not a pack');
        file_put_contents("{$plugin}/sr.json", json_encode(['Wave' => 'Mahni']));
        Translator::addPath('core', $core);
        Translator::addPath('hello', $plugin);

        $this->assertSame(['core', 'hello'], Translator::domains());
        $this->assertSame(['de_CH', 'sr', 'sr_Latn_RS'], Translator::locales());
        $this->assertSame(['Cancel' => 'Otkaži', 'Save' => 'Snimi'], $this->sorted(Translator::catalogue('core', 'sr-Latn-RS')), 'empty messages are left out');
        $this->assertSame([], Translator::catalogue('core', 'en'), 'English needs nothing');

        $this->assertSame(['core' => ['Cancel' => 'Otkaži', 'Save' => 'Sačuvaj'], 'hello' => ['Wave' => 'Mahni']], BrowserCatalogue::messages('sr'));
        $this->assertNull(BrowserCatalogue::url('en'), 'nothing to download for English');
        $this->assertNull(BrowserCatalogue::url('fr'));
        $url = (string) BrowserCatalogue::url('sr');
        $this->assertTrue((bool) preg_match('#^/admin/translations/sr\?v=[0-9a-f]{16}$#', $url), $url);

        $version = (string) BrowserCatalogue::version('sr');
        touch("{$plugin}/sr.json", time() + 10);
        clearstatcache();
        $this->assertNotSame($version, BrowserCatalogue::version('sr'), 'a changed pack is a new version');
    }

    public function testSitePagesGetTheirThemesAndPluginsTranslations(): void
    {
        [$core, $theme] = [$this->tempDir(), $this->tempDir()];
        file_put_contents("{$core}/sr.json", json_encode(['Save' => 'Sačuvaj']));
        Translator::addPath('core', $core);
        $this->assertNull(BrowserCatalogue::url('sr', site: true), 'only core has a pack: the site has nothing of its own to translate');

        file_put_contents("{$theme}/sr.json", json_encode(['Skip to content' => 'Preskoči na sadržaj']));
        Translator::addPath('base', $theme);
        $url = (string) BrowserCatalogue::url('sr', site: true);
        $this->assertTrue((bool) preg_match('#^/admin/translations/sr\?v=[0-9a-f]{16}&site=1$#', $url), $url);
        $this->assertSame(['base' => ['Skip to content' => 'Preskoči na sadržaj']], BrowserCatalogue::messages('sr', site: true), "not core's admin text");
        $r = BrowserCatalogue::respond('sr', BrowserCatalogue::version('sr'), site: true);
        $this->assertSame(['base' => ['Skip to content' => 'Preskoči na sadržaj']], json_decode($r->content(), true));
    }

    public function testTheDownloadIsCachedOnlyAtItsCurrentVersion(): void
    {
        $dir = $this->tempDir();
        file_put_contents("{$dir}/sr.json", json_encode(['Save' => 'Sačuvaj']));
        Translator::addPath('core', $dir);
        $version = BrowserCatalogue::version('sr');

        $current = BrowserCatalogue::respond('sr', $version);
        $this->assertSame(200, $current->statusCode());
        $this->assertSame(['core' => ['Save' => 'Sačuvaj']], json_decode($current->content(), true));
        $this->assertSame('public, max-age=31536000, immutable', $current->headers()['Cache-Control']);
        $this->assertSame('no-cache', BrowserCatalogue::respond('sr', 'old')->headers()['Cache-Control'], 'a stale version is never kept');
        $this->assertSame(404, BrowserCatalogue::respond('fr', null)->statusCode());
        $this->assertSame(404, BrowserCatalogue::respond('../../etc', null)->statusCode());
    }

    public function testRegisteredLabelsAreTranslatedInTheirOwnersDomain(): void
    {
        [$core, $plugin] = [$this->tempDir(), $this->tempDir()];
        file_put_contents("{$core}/sr.json", json_encode(['Heading' => 'Naslov', 'Text' => 'Tekst', 'Large' => 'Veliko']));
        file_put_contents("{$plugin}/sr.json", json_encode(['Heading' => 'Naslov prodavnice']));
        Translator::addPath('core', $core);
        Translator::addPath('shop', $plugin);
        Translator::setLocale('sr');

        $this->assertSame('core', Labels::domain('core'));
        $this->assertSame('shop', Labels::domain('plugin:shop'));
        $this->assertSame('base', Labels::domain('theme:base'));
        $this->assertSame('Naslov', Labels::text('Heading', 'core'));
        $this->assertSame('Naslov prodavnice', Labels::text('Heading', 'shop'), "the plugin's own first");
        $this->assertSame('Tekst', Labels::text('Text', 'shop'), "then core's");
        $this->assertSame('Typed in the admin', Labels::text('Typed in the admin', 'core'));
        $this->assertSame(['lg' => 'Veliko'], Labels::choices(['lg' => 'Large'], 'core'));
        $this->assertSame(['Large', 'Text'], Labels::choices(['Large', 'Text'], 'core'), 'bare values are data');
        $schema = Labels::schema(['type' => 'object', 'properties' => ['size' => ['type' => 'string', 'description' => 'Text', 'x-enum-labels' => ['lg' => 'Large']]]], 'core');
        $this->assertSame(['type' => 'string', 'description' => 'Tekst', 'x-enum-labels' => ['lg' => 'Veliko']], $schema['properties']['size']);
    }

    /** @param array<string, string> $a @return array<string, string> */
    private function sorted(array $a): array
    {
        ksort($a);

        return $a;
    }
}
