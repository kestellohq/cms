<?php

declare(strict_types=1);

namespace Kestello\Tests\Content;

use Kestello\Cms\Content\ContentModule;
use Kestello\Cms\Forms\Forms;
use Kestello\Cms\Forms\FormsModule;
use Kestello\Cms\Media\MediaModule;
use Kestello\Framework\Core\Request;
use Kestello\Framework\Core\Response;
use Kestello\Framework\Database\Model;
use Kestello\Framework\Database\QueryBuilder;
use Kestello\Support\AppKey;
use Kestello\Tests\Auth\AuthTestCase;

/** Forms: building, public submission and its spam checks, uploads, submissions. */
final class FormsTest extends AuthTestCase
{
    /** @var list<array{to: array|string, subject: string, html: string, headers: array}> */
    private array $sent = [];

    protected function setUp(): void
    {
        Model::resetRegistry();
        parent::setUp();
        $this->cms->container->instance('forms.mail', function (array|string $to, string $subject, string $html, array $headers = [], ?string $text = null): bool {
            $this->sent[] = compact('to', 'subject', 'html', 'headers', 'text');
            return true;
        });
        $this->cms->container->instance(\Kestello\Framework\Http\RateLimiter::class, new \Kestello\Framework\Http\RateLimiter(new \Kestello\Framework\Cache\ArrayStore()));
        foreach ([new ContentModule(), new MediaModule(), new FormsModule()] as $module) {
            $module->register($this->cms->container);
            $module->boot($this->cms);
        }
        ContentModule::finalize($this->cms);
        $this->createUser();
        $this->createUser(['username' => 'eve', 'email' => 'eve@example.test', 'role' => 'editor']);
        $this->createUser(['username' => 'al', 'email' => 'al@example.test', 'role' => 'author']);
        $this->signIn('ana');
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        Model::resetRegistry();
    }

    private function signIn(string $login): void
    {
        $this->api('DELETE', '/auth/session');
        $this->assertSame(200, $this->api('POST', '/auth/session', ['login' => $login, 'password' => self::PASSWORD])->statusCode());
    }

    private const array CONTACT = [
        'title' => 'Contact',
        'definition' => [
            'success_message' => 'Thanks, we will write back.',
            'per_ip_hour' => 100,
            'notifications' => [['to' => 'office@example.test, boss@example.test', 'subject' => '', 'reply_to' => 'email']],
            'fields' => [
                ['name' => 'name', 'type' => 'text', 'label' => 'Your name', 'required' => true, 'options' => ['max' => 80]],
                ['name' => 'email', 'type' => 'email', 'label' => 'Email', 'required' => true],
                ['name' => 'topic', 'type' => 'select', 'label' => 'About', 'options' => ['choices' => [['value' => 'order', 'label' => 'An order'], ['value' => 'other', 'label' => 'Something else']], 'display' => 'radios']],
                ['name' => 'details', 'type' => 'text', 'label' => 'What about?', 'required' => true,
                    'conditions' => ['match' => 'all', 'rules' => [['field' => 'topic', 'op' => 'is', 'value' => 'other']]]],
                ['name' => 'message', 'type' => 'textarea', 'label' => 'Message', 'required' => true],
                ['name' => 'consent', 'type' => 'boolean', 'label' => 'You may reply by email'],
            ],
        ],
    ];

    private function create(array $form = self::CONTACT): array
    {
        $r = $this->api('POST', '/forms', $form);
        $this->assertSame(200, $r->statusCode(), $r->content());

        return $this->json($r)['data'];
    }

    /** A token as the form block would have fetched it $age seconds ago. */
    private static function token(int $formId, int $age = 30): string
    {
        $payload = $formId . '.' . (time() - $age);

        return $payload . '.' . hash_hmac('sha256', $payload, AppKey::derive('forms'));
    }

    /** A visitor sending the form from a page of this site. */
    private function send(int $id, array $fields, array $headers = ['Origin' => 'https://bakery.test'], array $files = [], string $ip = '203.0.113.7'): Response
    {
        $this->nextRequest();
        $uri = "/api/v1/forms/{$id}/submissions";

        return $this->cms->routes->dispatch(new Request(
            'POST',
            $uri,
            $uri,
            $headers + ['Host' => 'bakery.test', 'Referer' => 'https://bakery.test/contact'],
            [],
            $fields + ['_token' => self::token($id)],
            [],
            $files,
            ['REMOTE_ADDR' => $ip],
            '',
        ));
    }

    private function rows(int $formId): array
    {
        return QueryBuilder::table('form_submissions')->where('form_id', $formId)->orderBy('id')->get();
    }

    public function testFormsAreCheckedWhenBuilt(): void
    {
        $form = $this->create();
        $this->assertSame(['name', 'email', 'topic', 'details', 'message', 'consent'], array_column($form['definition']['fields'], 'name'));
        $this->assertSame(['order' => 'An order', 'other' => 'Something else'], $form['definition']['fields'][2]['options']['choices']);

        $broken = static function (array $def): array {
            return ['title' => 'Broken', 'definition' => $def + ['fields' => [['name' => 'a', 'type' => 'text']]]];
        };
        foreach ([
            [['fields' => []], 'fields', 'a form needs fields'],
            [['notifications' => [['to' => 'not-an-address']]], 'notifications.0.to', 'addresses are checked'],
            [['notifications' => [['to' => 'a@example.test', 'reply_to' => 'a']]], 'notifications.0.reply_to', 'replies go to an email field'],
            [['store' => false], 'store', 'submissions must go somewhere'],
            [['fields' => [['name' => 'cv', 'type' => 'file']], 'store' => false, 'notifications' => [['to' => 'a@example.test']]], 'fields.0.type', 'files need kept submissions'],
            [['fields' => [['name' => 'pick', 'type' => 'select']]], 'fields.0', 'a select needs choices'],
            [['fields' => [['name' => 'a', 'type' => 'text', 'conditions' => ['rules' => [['field' => 'b', 'op' => 'filled']]]], ['name' => 'b', 'type' => 'text']]], 'fields.0.conditions', 'rules look only at fields above'],
            [['fields' => [['name' => 'x', 'type' => 'php']]], 'fields.0.type', 'only known types'],
            [['captcha' => true], 'captcha', 'no captcha service installed'],
            [['redirect' => 'javascript:alert(1)'], 'redirect', 'redirects are site paths or web addresses'],
            [['redirect' => '/\\evil.test/x'], 'redirect', 'browsers read /\\host as //host'],
            [['redirect' => '//evil.test'], 'redirect', 'no protocol-relative addresses'],
            [['redirect' => "/thanks\x07page"], 'redirect', 'no control characters'],
        ] as [$def, $key, $why]) {
            $r = $this->api('POST', '/forms', $broken($def));
            $this->assertSame(422, $r->statusCode(), $why);
            $this->assertTrue(array_key_exists($key, $this->json($r)['errors'] ?? []), "{$why}: error on {$key}, got " . $r->content());
        }

        $this->signIn('eve');
        $this->assertSame(200, $this->api('GET', '/forms')->statusCode(), 'editors manage forms');
        $this->signIn('al');
        $this->assertSame(403, $this->api('GET', '/forms')->statusCode(), 'authors don\'t');
        $this->assertSame(403, $this->api('GET', "/forms/{$form['id']}/submissions")->statusCode());
    }

    /** The builder's canvas and the Form block show these when a file field leaves its limits empty (shared/file-hint.js). */
    public function testFileFieldsWithoutLimitsGetTheDefaults(): void
    {
        $fields = [
            ['name' => 'one', 'type' => 'file'],
            ['name' => 'several', 'type' => 'file', 'options' => ['multiple' => true]],
            ['name' => 'many', 'type' => 'file', 'options' => ['multiple' => true, 'max' => 50]],
        ];
        $r = $this->api('POST', '/forms', ['title' => 'Files', 'definition' => ['fields' => $fields]]);
        $this->assertSame(200, $r->statusCode(), $r->content());
        $saved = array_column($this->json($r)['data']['definition']['fields'], 'options', 'name');
        $this->assertSame([5, 1], [$saved['one']['max_size'], $saved['one']['max']], 'one file of up to 5 MB');
        $this->assertSame([5, 5], [$saved['several']['max_size'], $saved['several']['max']], 'several: up to 5 files');
        $this->assertSame(10, $saved['many']['max'], 'never more than 10 files');
    }

    public function testVisitorsSendForms(): void
    {
        $form = $this->create();
        $id = $form['id'];
        $ok = ['name' => 'Ana', 'email' => 'ana@example.org', 'topic' => 'order', 'message' => 'Two loaves, please.', 'consent' => '1'];

        $token = $this->json($this->api('GET', "/forms/{$id}/token"))['data']['token'];
        $this->assertMatchesRegularExpression('/^' . $id . '\.\d+\.[a-f0-9]{64}$/', $token);

        $r = $this->send($id, $ok);
        $this->assertSame(200, $r->statusCode(), $r->content());
        $this->assertSame(['message' => 'Thanks, we will write back.', 'redirect' => null], array_intersect_key($this->json($r)['data'], ['message' => 1, 'redirect' => 1]));
        $row = $this->rows($id)[0];
        $this->assertSame(['name' => 'Ana', 'email' => 'ana@example.org', 'topic' => 'order', 'message' => 'Two loaves, please.', 'consent' => true], json_decode($row['data'], true), 'values in form order, typed');
        $this->assertSame(['new', '/contact'], [$row['status'], $row['page']]);

        $this->assertCount(1, $this->sent);
        $this->assertSame([['office@example.test', 'boss@example.test'], 'New submission: Contact', ['Reply-To' => 'ana@example.org']], [$this->sent[0]['to'], $this->sent[0]['subject'], $this->sent[0]['headers']]);
        $this->assertStringContainsString('Two loaves, please.', $this->sent[0]['html']);
        $this->assertStringContainsString('An order', $this->sent[0]['html'], 'choices by their label');

        // conditions: "what about?" is required only when it shows
        $this->assertSame(422, $this->send($id, ['topic' => 'other'] + $ok)->statusCode());
        $this->assertSame(['details'], array_keys($this->json($this->send($id, ['topic' => 'other'] + $ok))['errors']));
        $this->assertSame(200, $this->send($id, ['topic' => 'other', 'details' => 'Catering'] + $ok)->statusCode());
        $this->send($id, ['details' => 'Sneaked in'] + $ok);
        $rows = $this->rows($id);
        $this->assertFalse(array_key_exists('details', json_decode(end($rows)['data'], true)), 'a hidden field\'s value is dropped');

        $errors = $this->json($this->send($id, ['email' => 'nope', 'message' => ''] + $ok))['errors'];
        $this->assertSame(['email', 'message'], array_keys($errors), 'every problem at once, by field');
        $this->assertSame(422, $this->send($id, ['topic' => 'unknown'] + $ok)->statusCode(), 'only offered choices');

        // spam checks
        $before = count($this->rows($id));
        $mails = count($this->sent);
        $this->assertSame(200, $this->send($id, ['_hp' => 'http://spam.example'] + $ok)->statusCode(), 'a bot filling the honeypot is told it worked');
        $this->assertSame([$before, $mails], [count($this->rows($id)), count($this->sent)], 'but nothing is kept or sent');
        $this->assertSame(422, $this->send($id, ['_token' => self::token($id, 0)] + $ok)->statusCode(), 'faster than a person');
        $this->assertSame(422, $this->send($id, ['_token' => self::token($id, 3 * 86400)] + $ok)->statusCode(), 'an old page');
        $this->assertSame(422, $this->send($id, ['_token' => self::token($id + 1)] + $ok)->statusCode(), 'another form\'s token');
        $this->assertSame(403, $this->send($id, $ok, ['Referer' => ''])->statusCode(), 'no origin or referer: not from a page of this site');
        $this->assertSame(200, $this->send($id, $ok, [])->statusCode(), 'a referer from this site will do when there is no origin');
        $this->assertSame(403, $this->send($id, $ok, ['Referer' => 'https://bakery.test.evil.example/contact'])->statusCode(), 'a look-alike host isn\'t this site');
        $this->assertSame(403, $this->send($id, $ok, ['Origin' => 'https://evil.example'])->statusCode());

        $this->api('PATCH', "/forms/{$id}", ['title' => 'Contact', 'definition' => ['per_ip_hour' => 2] + self::CONTACT['definition']]);
        $statuses = array_map(fn() => $this->send($id, $ok, ip: '198.51.100.20')->statusCode(), range(1, 3));
        $this->assertSame([200, 200, 429], $statuses, 'per visitor and hour');

        $this->api('PATCH', "/forms/{$id}", ['title' => 'Contact', 'status' => 'closed', 'definition' => self::CONTACT['definition']]);
        $this->assertSame(409, $this->send($id, $ok, ip: '198.51.100.30')->statusCode(), 'closed');
        $this->assertSame(409, $this->api('GET', "/forms/{$id}/token")->statusCode());
    }

    public function testVisitorsReadProblemsInTheSiteLanguage(): void
    {
        $id = $this->create()['id'];
        $ok = ['name' => 'Ana', 'email' => 'ana@example.org', 'topic' => 'order', 'message' => 'Two loaves, please.'];
        \Kestello\Support\Translator::add('core', 'sr', [
            'Please fill this in.' => 'Popunite ovo polje.',
            'Must be a valid email address.' => 'Unesite ispravnu adresu e-pošte.',
            'Must be at most {max} characters.' => 'Najviše {max} znakova.',
            'The form has expired. Reload the page and send it again.' => 'Obrazac je istekao. Osvežite stranicu.',
        ]);
        $sr = fn(array $fields) => \Kestello\Support\Translator::withLocale('sr', fn() => $this->json($this->send($id, $fields + $ok))['errors']);

        $errors = $sr(['email' => 'nope', 'message' => '', 'name' => str_repeat('a', 81)]);
        $this->assertSame(['Najviše 80 znakova.'], $errors['name']);
        $this->assertSame(['Unesite ispravnu adresu e-pošte.'], $errors['email']);
        $this->assertSame(['Popunite ovo polje.'], $errors['message']);

        $expired = $sr(['_token' => self::token($id, 3 * 86400)]);
        $this->assertSame(['Obrazac je istekao. Osvežite stranicu.'], $expired['_form']);
        $this->assertSame(['expired'], $expired['_token'], 'the block knows to fetch a new token without reading the words');
        $this->assertArrayNotHasKey('_token', $sr(['_token' => self::token($id, 0)]), 'a token that was too quick becomes fine by itself');

        $this->assertSame(['Must be at most 80 characters.'], $this->json($this->send($id, ['name' => str_repeat('a', 81)] + $ok))['errors']['name'], 'English stays as it was');
    }

    public function testTheTeamIsEmailedInTheSiteLanguage(): void
    {
        $id = $this->create()['id'];
        \Kestello\Support\Translator::add('core', 'sr', [
            'New submission: {form}' => 'Nova poruka: {form}',
            'Someone sent {form} on {site}.' => 'Neko je poslao {form} na sajtu {site}.',
            'Someone sent {form} on {site}, from {page}.' => 'Neko je poslao {form} na sajtu {site}, sa {page}.',
            'Yes' => 'Da',
        ]);
        $ok = ['name' => 'Ana <b>', 'email' => 'ana@example.org', 'topic' => 'order', 'message' => 'Two loaves, please.', 'consent' => '1'];
        \Kestello\Support\Translator::withLocale('sr', fn() => $this->assertSame(200, $this->send($id, $ok)->statusCode()));

        $mail = end($this->sent);
        $this->assertSame('Nova poruka: Contact', $mail['subject']);
        $this->assertStringContainsString('>Nova poruka: Contact</h1>', $mail['html'], 'the heading names the form');
        $this->assertMatchesRegularExpression('~Neko je poslao Contact na sajtu [^<]+\.~', $mail['html'], 'the sentence is translated whole');
        $this->assertStringContainsString('Ana &lt;b&gt;', $mail['html']);
        $this->assertStringContainsString('Neko je poslao Contact na sajtu', $mail['text'], 'with a plain-text part in the same language');
        $this->assertStringContainsString(": Da\n", $mail['text'] . "\n", 'answers are rows in the text part too');
        $this->assertStringContainsString('>Da</td>', $mail['html']);
        $this->assertStringContainsString('Ana &lt;b&gt;', $mail['html'], 'what visitors type is still escaped');
    }

    public function testUploadsAndTheSubmissionsScreen(): void
    {
        $form = $this->create(['title' => 'Jobs', 'definition' => ['retention_days' => 30, 'fields' => [
            ['name' => 'name', 'type' => 'text', 'required' => true],
            ['name' => 'cv', 'type' => 'file', 'required' => true, 'options' => ['kinds' => ['document', 'image'], 'max_size' => 1]],
        ]]]);
        $id = $form['id'];
        $upload = function (string $name, string $bytes): array {
            $tmp = tempnam(sys_get_temp_dir(), 'kst');
            file_put_contents($tmp, $bytes);
            return ['cv' => ['name' => $name, 'type' => 'application/octet-stream', 'tmp_name' => $tmp, 'error' => UPLOAD_ERR_OK, 'size' => strlen($bytes)]];
        };
        $png = (function (): string {
            ob_start();
            imagepng(imagecreatetruecolor(4, 4));
            return (string) ob_get_clean();
        })();

        $this->assertSame(['cv'], array_keys($this->json($this->send($id, ['name' => 'Eve']))['errors']), 'a required file');
        $this->assertSame(422, $this->send($id, ['name' => 'Eve'], files: $upload('cv.png', '<?php echo 1;'))->statusCode(), 'content must match the extension');
        $this->assertSame(422, $this->send($id, ['name' => 'Eve'], files: $upload('cv.php', $png))->statusCode(), 'never scripts');
        $this->assertSame(422, $this->send($id, ['name' => 'Eve'], files: $upload('cv.png', $png . str_repeat("\0", 1024 * 1024)))->statusCode(), 'larger than the limit');
        $r = $this->send($id, ['name' => '=HYPERLINK("http://evil.example")'], files: $upload('my cv.png', $png));
        $this->assertSame(200, $r->statusCode(), $r->content());

        $sub = $this->json($this->api('GET', "/forms/{$id}/submissions"))['data'][0];
        // times in UTC with their zone: a browser reads one without as its own local time
        $this->assertMatchesRegularExpression('/^\\d{4}-\\d\\d-\\d\\dT\\d\\d:\\d\\d:\\d\\dZ$/', (string) $sub['created_at']);
        $this->assertMatchesRegularExpression('/^\\d{4}-\\d\\d-\\d\\dT\\d\\d:\\d\\d:\\d\\dZ$/', (string) $this->json($this->api('GET', '/forms'))['data'][0]['updated_at']);
        $this->cms->api->extend('forms')->field('acme_owner', static fn(array $row): string => 'sales');
        $this->cms->api->extend('form-submissions')->field('acme_score', static fn(array $row): int => 7);
        $this->assertSame('sales', $this->json($this->api('GET', "/forms/{$id}"))['data']['acme_owner'], 'plugins add fields to forms, the builder\'s read too');
        $this->assertSame([7, 7], [$this->json($this->api('GET', "/forms/{$id}/submissions"))['data'][0]['acme_score'], $this->json($this->api('GET', "/form-submissions/{$sub['id']}"))['data']['acme_score']], 'and to submissions');
        $file = $sub['data']['cv'][0];
        $this->assertSame(['my cv.png', 'image/png'], [$file['name'], $file['type']]);
        $path = $this->cms->container->make(Forms::class)->filePath($file['key']);
        $this->assertFileExists($path);
        $this->assertFalse(str_contains($path, '/public/'), 'kept outside the public folder');

        $download = $this->api('GET', "/form-submissions/{$sub['id']}/files/cv/0");
        $this->assertSame([200, 'application/octet-stream', 'nosniff'], [$download->statusCode(), $download->headers()['Content-Type'], $download->headers()['X-Content-Type-Options']]);
        $this->assertStringContainsString('attachment;', $download->headers()['Content-Disposition']);

        $this->assertSame('new', $sub['status']);
        $this->assertSame('read', $this->json($this->api('GET', "/form-submissions/{$sub['id']}"))['data']['status'], 'opening one marks it read');
        $this->assertSame(1, $this->json($this->api('GET', "/forms/{$id}/submissions?search=HYPERLINK"))['meta']['total']);
        $this->assertSame(0, $this->json($this->api('GET', "/forms/{$id}/submissions?search=nobody"))['meta']['total']);

        $csv = $this->api('GET', "/forms/{$id}/submissions/export")->content();
        $this->assertStringContainsString('ID,"Sent (UTC)",Status,Name,Cv,Page', $csv);
        $this->assertStringContainsString('"\'=HYPERLINK(""http://evil.example"")"', $csv, 'formulas are defused');
        $this->assertStringContainsString('my cv.png', $csv);

        $this->api('PATCH', "/form-submissions/{$sub['id']}", ['status' => 'spam']);
        $this->assertSame(0, $this->json($this->api('GET', "/forms/{$id}/submissions"))['meta']['total'], 'spam leaves the inbox');
        $this->assertSame(1, $this->json($this->api('GET', "/forms/{$id}/submissions?status=spam"))['meta']['total']);

        // retention: older than 30 days goes, with its files
        QueryBuilder::table('form_submissions')->where('id', $sub['id'])->update(['created_at' => gmdate('Y-m-d H:i:s', time() - 31 * 86400)]);
        $this->assertSame(1, $this->cms->container->make(Forms::class)->prune());
        $this->assertFileDoesNotExist($path);

        // deleting one submission takes its file
        $this->send($id, ['name' => 'Bo'], files: $upload('note.png', $png));
        $one = $this->rows($id)[0];
        $its = $this->cms->container->make(Forms::class)->filePath(json_decode($one['data'], true)['cv'][0]['key']);
        $this->assertSame(204, $this->api('DELETE', "/form-submissions/{$one['id']}")->statusCode());
        $this->assertSame([404, false], [$this->api('DELETE', "/form-submissions/{$one['id']}")->statusCode(), is_file($its)]);

        $this->send($id, ['name' => 'Al'], files: $upload('cv.pdf', "%PDF-1.4\n%\xe2\xe3\xcf\xd3\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n"));
        $last = $this->rows($id)[0];
        $kept = $this->cms->container->make(Forms::class)->filePath(json_decode($last['data'], true)['cv'][0]['key']);
        $this->assertSame(204, $this->api('DELETE', "/forms/{$id}")->statusCode());
        $this->assertSame([0, false], [count($this->rows($id)), is_file($kept)], 'deleting the form takes its submissions and files');
    }

    public function testTheFormBlockShowsOnlyWhatVisitorsNeed(): void
    {
        $form = $this->create();
        $def = $this->cms->container->make(Forms::class)->publicDefinition($form['id']);
        $this->assertSame(['id', 'title', 'open', 'closed_message', 'submit_label', 'captcha', 'fields', 'texts'], array_keys($def));
        // core's catalogue is not sent to the site: the block's own words come with it, in the site's language
        \Kestello\Support\Translator::add('core', 'sr', ['Sending…' => 'Šalje se…', 'Leave this empty' => 'Ostavite prazno', 'Send' => 'Pošalji', 'This form is closed.' => 'Obrazac je zatvoren.', 'Thank you. We received your message.' => 'Hvala, primili smo poruku.']);
        $sr = \Kestello\Support\Translator::withLocale('sr', fn() => $this->cms->container->make(Forms::class)->publicDefinition($form['id']));
        $this->assertSame(['Šalje se…', 'Ostavite prazno'], [$sr['texts']['sending'], $sr['texts']['honeypot']]);
        $this->assertSame(['Pošalji', 'Obrazac je zatvoren.'], [$sr['submit_label'], $sr['closed_message']], 'untouched defaults speak the site\'s language');
        $this->assertSame(['Send', 'This form is closed.'], [$def['submit_label'], $def['closed_message']]);
        $made = \Kestello\Support\Translator::withLocale('sr', fn() => $this->json($this->api('POST', '/forms', ['title' => 'Prijava', 'definition' => ['fields' => [['name' => 'ime', 'type' => 'text']]]]))['data']);
        $this->assertSame(['Pošalji', 'Hvala, primili smo poruku.'], [$made['definition']['submit_label'], $made['definition']['success_message']], 'a new form starts in the site\'s language');
        $this->assertSame(['sending', 'honeypot', 'choose', 'check', 'too_many', 'failed', 'offline', 'file_one', 'file_many'], array_keys($def['texts']));
        $this->assertFalse(str_contains(json_encode($def), 'office@example.test'), 'never the notification addresses');
        $this->assertSame([['value' => 'order', 'label' => 'An order'], ['value' => 'other', 'label' => 'Something else']], ((array) $def['fields'][2]['options'])['choices']);
        $this->assertSame('topic', $def['fields'][3]['conditions']['rules'][0]['field']);
        $this->assertNull($this->cms->container->make(Forms::class)->publicDefinition(999));
    }
}
