<?php

declare(strict_types=1);

namespace Kestello\Tests\Content;

use Kestello\Cms\Blocks\BlockDefinition;
use Kestello\Cms\Blocks\BlockDesign;
use Kestello\Framework\Testing\TestCase;

/** Block design: the styles an editor gives one block, per screen size. */
final class BlockDesignTest extends TestCase
{
    private const array ALL = ['color', 'spacing', 'type', 'border'];

    /** @return array{0: ?array, 1: list<string>} */
    private static function clean(mixed $design, array $groups = self::ALL, bool $strict = true): array
    {
        $errors = [];
        $out = (new BlockDesign())->clean($design, $groups, 'Block 1 (Text)', $errors, $strict);

        return [$out, $errors];
    }

    public function testPresetsAndCustomValuesAreKept(): void
    {
        $design = [
            'base' => ['text' => 'ink', 'background' => '#f4f1ea', 'padding-top' => 'lg', 'padding-left' => '1.5rem', 'font-size' => 'xl', 'border-width' => '2px', 'border-style' => 'dashed', 'radius' => 'full'],
            'tablet' => ['padding-top' => 'md'],
            'phone' => ['font-size' => '18px', 'margin-bottom' => '0'],
        ];
        $this->assertSame([$design, []], self::clean($design));
        $this->assertSame([null, []], self::clean(null));
        $this->assertSame([null, []], self::clean([]));
    }

    public function testTheLargestPresetsCanBeChosen(): void
    {
        // the defaults name them 2xl and 3xl: a leading digit is part of a name, while 1rem stays a length
        $design = ['base' => ['padding-top' => '2xl', 'font-size' => '3xl', 'margin-top' => '1rem']];
        $this->assertSame([$design, []], self::clean($design));
        [, $errors] = self::clean(['base' => ['padding-top' => '24']]);
        $this->assertCount(1, $errors, 'a bare number is neither a name nor a length');
    }

    public function testEveryProblemIsNamedWhenSaving(): void
    {
        [$out, $errors] = self::clean([
            'base' => [
                'padding-top' => 'huge',                  // not a preset
                'background' => 'red',                    // not a preset, not hex
                'margin-top' => '-1rem',                  // negative
                'font-size' => '12pt',                    // unit
                'border-width' => '200px',                // too wide
                'border-style' => 'groove',
                'shadow' => '0 1px',                      // not a style
                'text' => 'url(evil)',
                'radius' => 'var(--x)',                   // only presets or lengths
            ],
            'desktop' => ['text' => 'ink'],
            'phone' => ['text', 'ink'],
        ]);
        $this->assertNull($out);
        $this->assertSame([
            'Block 1 (Text): design.base.padding-top: "huge" isn\'t one of the theme\'s spacing sizes (xs, sm, md, lg, xl, 2xl).',
            'Block 1 (Text): design.base.background: "red" isn\'t one of the theme\'s colours (ink, paper, mist).',
            'Block 1 (Text): design.base.margin-top: is one of the theme\'s sizes or a length such as 24px or 1.5rem.',
            'Block 1 (Text): design.base.font-size: is one of the theme\'s sizes or a length such as 24px or 1.5rem.',
            'Block 1 (Text): design.base.border-width: is a width in px, at most 99px (such as 2px).',
            'Block 1 (Text): design.base.border-style: is one of solid, dashed, dotted, double, none.',
            'Block 1 (Text): design.base.shadow is not a style a block can have.',
            'Block 1 (Text): design.base.text: is one of the theme\'s colours or a colour such as #1e3a8a.',
            'Block 1 (Text): design.base.radius: is one of the theme\'s sizes or a length such as 24px or 1.5rem.',
            'Block 1 (Text): design.desktop is not a screen size (base, tablet or phone).',
            'Block 1 (Text): design.phone must be an object of styles.',
        ], $errors);
        $this->assertSame(['Block 1 (Text): design must be an object of screen sizes (base, tablet, phone).'], self::clean('padding: 9px')[1]);
    }

    public function testABlockOnlyTakesTheGroupsItSupports(): void
    {
        [$out, $errors] = self::clean(['base' => ['padding-top' => 'sm', 'background' => 'ink']], ['spacing']);
        $this->assertSame(['base' => ['padding-top' => 'sm']], $out);
        $this->assertSame(['Block 1 (Text): design.base.background: this block has no color settings.'], $errors);
    }

    public function testRenderingDropsWhatIsWrongAndKeepsTheRest(): void
    {
        // a theme change took "huge" away: the page still draws, with the rest of the design
        [$out, $errors] = self::clean(['base' => ['padding-top' => 'huge', 'background' => 'ink'], 'phone' => 'nonsense'], strict: false);
        $this->assertSame(['base' => ['background' => 'ink']], $out);
        $this->assertSame([], $errors);
    }

    public function testTheThemesPresetsReplaceTheDefaultsKindByKind(): void
    {
        $design = new BlockDesign();
        $design->usePresets(static fn() => ['space' => ['tight' => ['label' => 'Tight', 'value' => '.25rem']]]);
        $this->assertSame(['tight'], array_keys($design->presets()['space']));
        $this->assertSame(array_keys(BlockDesign::DEFAULTS['font']), array_keys($design->presets()['font']));
        // a provider that fails falls back to the defaults (and says why in the log)
        $design->usePresets(static fn() => throw new \RuntimeException('no theme'));
        $this->assertSame(BlockDesign::DEFAULTS['space'], $design->presets()['space']);
    }

    public function testPresetsBecomeCustomPropertiesAndNothingElse(): void
    {
        $css = BlockDesign::css(['space' => ['md' => ['value' => '1.5rem'], 'bad' => ['value' => '1rem; } * { color: red']], 'color' => ['brand' => ['value' => 'var(--plate)']]]);
        $this->assertSame(":root {\n  --k-color-brand: var(--plate);\n  --k-space-md: 1.5rem;\n}\n", $css);
        $this->assertTrue(BlockDesign::safeValue('clamp(1rem, 2vw + 1rem, 3rem)'));
        foreach (['red', 'url(x)', 'var(--a); color: red', 'expression(1)', 'calc(1px)'] as $bad) {
            $this->assertFalse(BlockDesign::safeValue($bad), $bad);
        }
    }

    public function testSupportsAreChecked(): void
    {
        $this->assertSame(['color', 'spacing'], BlockDefinition::make('acme/box')->supports(['color', 'spacing', 'color'])->getSupports());
        $error = $this->assertThrows(\InvalidArgumentException::class, static fn() => BlockDefinition::make('acme/box')->supports(['colour']));
        $this->assertStringContainsString('unknown design support "colour"', $error->getMessage());
    }

    /** The page draws what shared/block-design.js knows: the two lists must be the same. */
    public function testTheBrowserKnowsTheSameStyles(): void
    {
        $js = (string) file_get_contents(dirname(__DIR__, 2) . '/core/resources/js/shared/block-design.js');
        preg_match_all("/^\\s+'?([a-z-]+)'?: \\{ kind: '([a-z]+)', group: '([a-z]+)' \\},$/m", $js, $m, PREG_SET_ORDER);
        $browser = [];
        foreach ($m as [, $name, $kind, $group]) {
            $browser[$name] = [$kind, $group];
        }
        $this->assertSame(BlockDesign::PROPS, $browser);
        $this->assertStringContainsString("export const SCREENS = ['base', 'tablet', 'phone'];", $js);
        $this->assertStringContainsString("export const LINES = ['solid', 'dashed', 'dotted', 'double', 'none'];", $js);
    }
}
