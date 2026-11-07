<?php

declare(strict_types=1);

namespace Kestello\Tests\Framework;

use Closure;
use Kestello\Contracts\Validation\Rule;
use Kestello\Framework\Database\Connection;
use Kestello\Framework\Database\DatabaseManager;
use Kestello\Framework\Testing\TestCase;
use Kestello\Framework\Validation\ValidationException;
use Kestello\Framework\Validation\Validator;
use Kestello\Support\Translator;

final class ValidatorTest extends TestCase
{
    protected function tearDown(): void
    {
        Validator::forgetExtensions();
        Translator::reset();
        DatabaseManager::reset();
    }

    private static function errors(array $data, array $rules): array
    {
        return Validator::make($data, $rules)->errors();
    }

    public function testOptionalFieldsAndClassicRules(): void
    {
        $v = Validator::make(['name' => 'Ana', 'bio' => '', 'age' => '25'], ['name' => 'required|string|max:10', 'bio' => 'string|max:5', 'age' => 'integer|min:18', 'nick' => 'alpha']);
        $this->assertTrue($v->passes(), json_encode($v->errors()));
        $this->assertSame(['name' => 'Ana', 'bio' => '', 'age' => '25', 'nick' => null], $v->validated());

        $this->assertSame(['age' => ['The age must be at least 18.']], self::errors(['age' => '9'], ['age' => 'integer|min:18']), 'numbers compare numerically, not by length');
        $this->assertSame(['name' => ['The name must be at least 3 characters.']], self::errors(['name' => 'Al'], ['name' => 'string|min:3']));
        $this->assertSame(['email' => ['The email field is required.']], self::errors(['email' => '  '], ['email' => 'required|email']));
        $this->assertSame(['email' => ['The email must be a valid email address.']], self::errors(['email' => 'nope'], ['email' => 'required|email']));
        $this->assertSame([], self::errors(['p' => 'secret1', 'p_confirmation' => 'secret1'], ['p' => 'confirmed']));
        $this->assertCount(1, self::errors(['p' => 'secret1', 'p_confirmation' => 'other'], ['p' => 'confirmed']));
        $this->assertSame(['u' => ['The u must be a valid URL.']], self::errors(['u' => 'javascript://x%0Aalert(1)'], ['u' => 'url']), 'url accepts only http(s) by default');
        $this->assertSame([], self::errors(['u' => 'ftp://files.test/x'], ['u' => 'url:ftp']));
        $this->assertSame([], self::errors(['n' => 'Ђорђе'], ['n' => 'alpha']), 'unicode letters');
        $this->assertCount(1, self::errors(['s' => 'Not A Slug'], ['s' => 'slug']));
    }

    public function testNestedWildcardsAndDistinct(): void
    {
        $data = ['items' => [['sku' => 'A1', 'qty' => 2], ['sku' => 'A1', 'qty' => 0], ['qty' => 3]], 'tags' => ['x', 'y']];
        $v = Validator::make($data, [
            'items' => 'required|array|min:1',
            'items.*.sku' => 'required|string|distinct',
            'items.*.qty' => 'required|integer|gt:0',
            'tags' => 'list|max:5',
            'tags.*' => 'string|in:x,y,z',
        ]);
        $this->assertSame([
            'items.0.sku' => ['The items.0.sku has a duplicate value.'],
            'items.1.sku' => ['The items.1.sku has a duplicate value.'],
            'items.2.sku' => ['The items.2.sku field is required.'],
            'items.1.qty' => ['The items.1.qty must be greater than 0.'],
        ], $v->errors());
        $v->setAliases(['items.*.qty' => 'quantity']);
        $this->assertSame('The quantity must be greater than 0.', $v->error('items.1.qty'));
        $this->assertSame(['x', 'y'], $v->validated()['tags']);
        $this->assertSame(3, $v->validated()['items'][2]['qty']);
    }

    public function testConditionalComparisonAndDates(): void
    {
        $rules = ['type' => 'in:person,company', 'vat' => 'required_if:type,company|string', 'phone' => 'required_without:email', 'email' => 'nullable|email'];
        $this->assertSame(['vat' => ['The vat field is required when type is company.'], 'phone' => ['The phone field is required when email is not present.']], self::errors(['type' => 'company'], $rules));
        $this->assertSame([], self::errors(['type' => 'person', 'email' => 'a@b.test'], $rules));
        $this->assertCount(1, self::errors(['coupon' => 'X'], ['coupon' => 'prohibited']));

        $this->assertSame([], self::errors(['start' => '2026-11-10', 'end' => '2026-11-12'], ['start' => 'date|after:2026-11-01', 'end' => 'date|after:start']));
        $this->assertSame(['end' => ['The end must be a date after start.']], self::errors(['start' => '2026-11-10', 'end' => '2026-11-09'], ['end' => 'date|after:start']));
        $this->assertSame(['d' => ['The d is not a valid date.']], self::errors(['d' => '2026-02-30'], ['d' => 'date']), 'no roll-over to March');
        $this->assertSame([], self::errors(['d' => '04.11.2026'], ['d' => 'date_format:d.m.Y']));
        $this->assertCount(1, self::errors(['d' => '4.11.2026'], ['d' => 'date_format:d.m.Y']));
        $this->assertSame([], self::errors(['min' => 5, 'max' => 9], ['max' => 'integer|gte:min']));
        $this->assertCount(1, self::errors(['tz' => 'Mars/Base'], ['tz' => 'timezone']));
        $this->assertSame([], self::errors([
            'id' => '0192f6c4-8c4e-7b3a-9f00-3f1c2b1a0e55', 'ulid' => '01JC8Z3V4QW5E6R7T8Y9U0I1OP', 'json' => '{"a":1}', 'ip' => '2001:db8::1', 'c' => '#6676d8', 'oklch' => 'oklch(0.6 0.15 274)',
        ], ['id' => 'uuid', 'ulid' => 'string', 'json' => 'json', 'ip' => 'ipv6', 'c' => 'hex_color', 'oklch' => 'color']));
        $this->assertCount(1, self::errors(['json' => '{a:1}'], ['json' => 'json']));
    }

    public function testFilesUseTheRealContent(): void
    {
        $dir = $this->tempDir();
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
        file_put_contents("{$dir}/a", $png);
        file_put_contents("{$dir}/b", '<?php echo 1;');
        file_put_contents("{$dir}/c", str_repeat('x', 3000));
        $upload = static fn(string $name, string $tmp) => ['name' => $name, 'type' => 'image/png', 'tmp_name' => $tmp, 'error' => UPLOAD_ERR_OK, 'size' => filesize($tmp)];

        $this->assertSame([], self::errors(['f' => $upload('dot.png', "{$dir}/a")], ['f' => 'required|file|image|mimes:png,jpg|max:10|dimensions:min_width=1,max_width=10']));
        $this->assertCount(2, self::errors(['f' => $upload('shell.png', "{$dir}/b")], ['f' => 'image|mimes:png'])['f'], 'PHP with a PNG name and type');
        $this->assertCount(1, self::errors(['f' => $upload('dot.php.png', "{$dir}/a")], ['f' => 'mimes:png']), 'double extension');
        $this->assertCount(1, self::errors(['f' => $upload('dot.jpg', "{$dir}/a")], ['f' => 'mimes:jpg']), 'PNG named .jpg');
        $this->assertSame(['f' => ['The f must not be larger than 2 kilobytes.']], self::errors(['f' => $upload('notes.txt', "{$dir}/c")], ['f' => 'file|mimes:txt|max:2']));
        $this->assertSame([], self::errors(['f' => $upload('notes.txt', "{$dir}/c")], ['f' => 'mimetypes:text/*']));
        $this->assertSame(['f' => ['The f field is required.']], self::errors(['f' => ['name' => '', 'tmp_name' => '', 'error' => UPLOAD_ERR_NO_FILE, 'size' => 0]], ['f' => 'required|file']));
        $this->assertCount(1, self::errors(['f' => ['name' => 'x.png', 'tmp_name' => '/etc/passwd', 'error' => UPLOAD_ERR_INI_SIZE, 'size' => 1]], ['f' => 'file']));
    }

    public function testDatabaseRules(): void
    {
        $db = Connection::sqliteMemory();
        DatabaseManager::setConnection($db);
        $db->raw('CREATE TABLE users (id INTEGER PRIMARY KEY, email TEXT)');
        $db->table('users')->insert(['id' => 1, 'email' => 'ana@example.test']);
        $db->table('users')->insert(['id' => 2, 'email' => 'bo@example.test']);

        $this->assertCount(1, self::errors(['email' => 'ana@example.test'], ['email' => 'unique:users,email']));
        $this->assertSame([], self::errors(['email' => 'ana@example.test'], ['email' => 'unique:users,email,1']), 'except own row');
        $this->assertSame([], self::errors(['author' => 2, 'ids' => [1, 2]], ['author' => 'exists:users,id', 'ids' => 'array|exists:users,id']));
        $this->assertCount(1, self::errors(['ids' => [1, 99]], ['ids' => 'exists:users,id']));
        $this->assertThrows(\InvalidArgumentException::class, fn() => self::errors(['x' => 1], ['x' => 'exists:users;drop table users,id']));
    }

    public function testCustomRulesExtensionsAndMessages(): void
    {
        $isbn = new class implements Rule {
            public function validate(string $attribute, mixed $value, Closure $fail, array $data): void
            {
                if (!preg_match('/^97[89]\d{10}$/', (string) $value)) {
                    $fail('The {attribute} is not an ISBN-13.');
                }
            }
        };
        $this->assertSame(['isbn' => ['The isbn is not an ISBN-13.']], self::errors(['isbn' => '123'], ['isbn' => ['required', $isbn]]));
        $this->assertSame(['n' => ['Odd only']], self::errors(['n' => 4], ['n' => [static function (string $a, mixed $v, Closure $fail): void {
            if ($v % 2 === 0) {
                $fail('Odd only');
            }
        }]]));

        Validator::extend('acme_even', static fn(mixed $v) => (int) $v % 2 === 0, 'The {attribute} must be even.');
        $this->assertSame(['n' => ['The n must be even.']], self::errors(['n' => 3], ['n' => 'integer|acme_even']));
        $this->assertThrows(\InvalidArgumentException::class, fn() => Validator::extend('required', static fn() => true), 'no overriding built-ins');
        $this->assertThrows(\InvalidArgumentException::class, fn() => self::errors(['n' => 1], ['n' => 'no_such_rule']));

        $this->assertSame(['n' => ['The n must be an integer.', 'The n must be even.']], self::errors(['n' => '3.5'], ['n' => 'integer|acme_even']), 'all failures without bail');
        $this->assertSame(['n' => ['The n must be an integer.']], self::errors(['n' => '3.5'], ['n' => 'bail|integer|acme_even']));
        $this->assertSame(['email' => ['We need your email']], Validator::make(['email' => ''], ['email' => 'bail|required|email'], ['email.required' => 'We need your email'])->errors());

        Translator::setLocale('sr');
        Translator::add('core', 'sr', ['The {attribute} field is required.' => 'Polje {attribute} je obavezno.']);
        $this->assertSame(['ime' => ['Polje ime je obavezno.']], self::errors([], ['ime' => 'required']));

        $this->assertThrows(ValidationException::class, fn() => Validator::make([], ['a' => 'required'])->validateOrFail());
    }
}
