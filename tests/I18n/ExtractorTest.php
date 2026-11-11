<?php

declare(strict_types=1);

namespace Kestello\Tests\I18n;

use Kestello\Cms\I18n\Extractor;
use Kestello\Framework\Testing\TestCase;

/** What `bin/cms i18n:extract` finds, and the template it writes. */
final class ExtractorTest extends TestCase
{
    public function testPhpCallsRegistrationsAndArrayLabels(): void
    {
        $code = <<<'PHP'
        <?php
        use function Kestello\{__, trans_choice};
        // __('In a comment') is not text people see
        echo __('Save changes');
        echo \Kestello\__("Line\nbreak", ['x' => 1]);
        echo trans_choice('{count, plural, one {# file} other {# files}}', $n);
        echo Translator::get('From the translator');
        echo __($dynamic);
        echo __('Hello, ' . $name);
        $b->register(BlockDefinition::make('acme/card')->label('Card')->description('A card with a picture.'));
        $types->type('book')->label('Book', 'Books');
        Field::text('isbn')->label('ISBN')->help('Thirteen digits.');
        $admin->page('books', '@acme/Books', 'Books on sale', 'books.read', ['label' => 'Shop', 'icon' => 'book']);
        $admin->widget('dashboard', '@acme/Sales', 'Sales today');
        $admin->settings('shop', label: 'Shop settings', schema: $s, labels: ['currency' => 'Currency', 'tax' => 'Tax rate']);
        $groups = ['general' => ['label' => 'General', 'description' => 'The basics.', 'labels' => ['name' => 'Name', 'nested' => ['x' => 'Not a label']]]];
        function __($text) {}
        $logger->description($x);
        $other->label;
        $who = Schema::enum(['closed' => 'Nobody', 'open' => 'Anyone'])->optional();
        $tls = Schema::enum(['tls', 'ssl', 'none']);
        $list = Other::enum(['a' => 'Not a schema']);
        PHP;

        $this->assertSame([
            'Save changes', "Line\nbreak", '{count, plural, one {# file} other {# files}}', 'From the translator',
            'Card', 'A card with a picture.', 'Book', 'Books', 'ISBN', 'Thirteen digits.',
            'Books on sale', 'Shop', 'Sales today', 'Shop settings', 'Currency', 'Tax rate',
            'General', 'The basics.', 'Name', 'Nobody', 'Anyone',
        ], Extractor::php($code), 'the labels of a labelled Schema::enum, never the values of a plain one');
    }

    public function testJavascriptAndVue(): void
    {
        $code = <<<'JS'
        <template><KButton :label="__('Publish')">{{ __("Saved {time}", { time }) }}</KButton></template>
        <script setup>
        const { __ } = useI18n('acme');
        notify(__('It\'s done.'));
        const a = __(`No ${interpolation}`);
        const b = __(`Plain template`);
        const c = obj.__('a method, not ours');
        const d = my__('not ours either');
        </script>
        JS;

        $this->assertSame(['Publish', 'Saved {time}', "It's done.", 'Plain template'], Extractor::js($code));
    }

    public function testTheTemplateIsStableAndListsWhereEachMessageIs(): void
    {
        $pot = (new Extractor())
            ->add('core/b.vue', ['Save', 'Say "hi"'])
            ->add('core/a.php', ['Save', '{n, plural, one {# day} other {# days}}', "Two\nlines"])
            ->pot('Kestello 1.0.0', 'core');

        $this->assertContains("\"Project-Id-Version: Kestello 1.0.0\\n\"\n", $pot);
        $this->assertNotContains('POT-Creation-Date', $pot, 'no date: the file only changes with the text');
        $this->assertContains("\n#: core/a.php\n#: core/b.vue\nmsgid \"Save\"\nmsgstr \"\"\n", $pot);
        $this->assertContains("msgid \"Say \\\"hi\\\"\"\n", $pot);
        $this->assertContains("#. ICU message: translate every case, keep the {name, plural, ...} structure.\nmsgid \"{n, plural, one {# day} other {# days}}\"", $pot);
        $this->assertContains("msgid \"\"\n\"Two\\n\"\n\"lines\"\nmsgstr \"\"", $pot);
        $this->assertTrue(strpos($pot, 'msgid "Save"') < strpos($pot, 'msgid "Say'), 'sorted');
    }

    public function testScanningSkipsBuildsAndTests(): void
    {
        $root = $this->tempDir();
        mkdir("{$root}/src");
        mkdir("{$root}/assets/build", 0777, true);
        mkdir("{$root}/tests");
        file_put_contents("{$root}/src/Plugin.php", "<?php echo __('Kept');");
        file_put_contents("{$root}/src/Page.vue", "<template>{{ __('Also kept') }}</template>");
        file_put_contents("{$root}/assets/build/app.js", "__('Built')");
        file_put_contents("{$root}/tests/T.php", "<?php __('Test');");
        file_put_contents("{$root}/src/sample.php", "<?php\n/* Sample content. i18n:extract skips this file. */\nreturn ['title' => 'Casa'];");
        file_put_contents("{$root}/src/Later.php", "<?php echo __('Kept');\n" . str_repeat("\n", 1200) . '// i18n:extract skips this file, said too late to count');

        $this->assertSame(['Also kept' => ['src/Page.vue'], 'Kept' => ['src/Later.php', 'src/Plugin.php']], (new Extractor())->scan($root, $root)->messages());
        $this->assertThrows(\InvalidArgumentException::class, fn() => (new Extractor())->scan($root, "{$root}/missing"));
    }
}
