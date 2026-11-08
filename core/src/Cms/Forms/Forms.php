<?php

declare(strict_types=1);

namespace Kestello\Cms\Forms;

use Kestello\Cms\Cms;
use Kestello\Cms\Content\Conditions;
use Kestello\Cms\Content\ContentException;
use Kestello\Cms\Content\Field;
use Kestello\Cms\Media\MediaTypes;
use Kestello\Contracts\Forms\Captcha;
use Kestello\Framework\Database\QueryBuilder;
use Kestello\Support\AppKey;
use Kestello\Support\Date;
use Kestello\Support\Json;
use Kestello\Support\Translator;
use OutOfBoundsException;

/**
 * Forms: definitions made of content field types, public
 * submission with server-side validation and conditions, spam checks
 * that work on cached pages (signed token with a minimum fill time,
 * honeypot, per-IP limit, same-origin, an optional captcha driver),
 * private file uploads, email notifications, CSV export and retention.
 */
final class Forms
{
    /** Field types a form offers and the options each may set. */
    public const array OPTIONS = [
        'text' => ['placeholder' => 'string', 'min' => 'int', 'max' => 'int'],
        'textarea' => ['placeholder' => 'string', 'min' => 'int', 'max' => 'int', 'rows' => 'int'],
        'email' => ['placeholder' => 'string'],
        'url' => ['placeholder' => 'string'],
        'number' => ['min' => 'number', 'max' => 'number', 'step' => 'number', 'integer' => 'bool'],
        'select' => ['choices' => 'choices', 'display' => 'display'],
        'multi_select' => ['choices' => 'choices'],
        'boolean' => [],
        'date' => [],
        'file' => ['kinds' => 'kinds', 'max_size' => 'int', 'multiple' => 'bool', 'max' => 'int'],
    ];

    public const int MAX_FIELDS = 60;
    public const int MAX_UPLOAD_MB = 20;
    public const int MIN_FILL_SECONDS = 2;
    private const int TOKEN_LIFETIME = 172800;   // two days: a page left open still works
    public const int DEFAULT_PER_IP_HOUR = 10;

    public function __construct(private readonly Cms $cms) {}

    // ── definitions ──────────────────────────────────────────────────────

    /** @return list<array<string, mixed>> */
    public function all(): array
    {
        $counts = [];
        foreach (QueryBuilder::table('form_submissions')->select('form_id', 'status')->get() as $row) {
            $c = &$counts[(int) $row['form_id']];
            $c['total'] = ($c['total'] ?? 0) + ($row['status'] === 'spam' ? 0 : 1);
            $c['new'] = ($c['new'] ?? 0) + ($row['status'] === 'new' ? 1 : 0);
            unset($c);
        }

        return array_map(
            fn(array $r) => self::present($r) + ['submissions' => $counts[(int) $r['id']] ?? ['total' => 0, 'new' => 0]],
            QueryBuilder::table('forms')->orderBy('title')->get(),
        );
    }

    public function find(int $id): array
    {
        $row = QueryBuilder::table('forms')->where('id', $id)->first();

        return $row === null ? throw new OutOfBoundsException('No such form.') : self::present($row);
    }

    private static function present(array $row): array
    {
        return ['id' => (int) $row['id'], 'title' => (string) $row['title'], 'status' => (string) $row['status'],
            'definition' => Json::decode((string) $row['definition']), 'updated_at' => Date::isoUtc($row['updated_at'] ?? null)];
    }

    /** @throws ContentException */
    public function save(array $input, ?int $id = null, ?int $userId = null): array
    {
        $errors = [];
        $title = trim((string) (is_scalar($input['title'] ?? null) ? $input['title'] : ''));
        if ($title === '' || mb_strlen($title) > 200) {
            $errors['title'][] = Translator::get('A name of up to 200 characters.');
        }
        $status = ($input['status'] ?? 'open') === 'closed' ? 'closed' : 'open';
        [$def, $more] = $this->normalize(is_array($input['definition'] ?? null) ? $input['definition'] : []);
        $errors += $more;
        if ($errors !== []) {
            throw ContentException::invalid($errors);
        }
        $now = gmdate('Y-m-d H:i:s');
        $row = ['title' => $title, 'status' => $status, 'definition' => Json::encode($def), 'updated_at' => $now];
        if ($id === null) {
            $id = QueryBuilder::table('forms')->insert($row + ['created_by' => $userId, 'created_at' => $now]);
        } else {
            $this->find($id);
            QueryBuilder::table('forms')->where('id', $id)->update($row);
        }

        return $this->find((int) $id);
    }

    public function delete(int $id): void
    {
        $this->find($id);
        $this->deleteFiles(QueryBuilder::table('form_submissions')->where('form_id', $id));
        QueryBuilder::table('form_submissions')->where('form_id', $id)->delete();
        QueryBuilder::table('forms')->where('id', $id)->delete();
    }

    /** @return array{0: array<string, mixed>, 1: array<string, list<string>>} */
    public function normalize(array $in): array
    {
        $errors = [];
        $str = static function (string $key, int $max, string $default) use ($in, &$errors): string {
            $v = trim((string) (is_scalar($in[$key] ?? null) ? $in[$key] : ''));
            if (mb_strlen($v) > $max) {
                $errors[$key][] = Translator::get('At most {max} characters.', ['max' => $max]);
            }
            return $v === '' ? $default : $v;
        };
        $def = [
            'submit_label' => $str('submit_label', 60, self::defaultWording('submit_label')[1]),
            'success_message' => $str('success_message', 1000, self::defaultWording('success_message')[1]),
            'closed_message' => $str('closed_message', 500, self::defaultWording('closed_message')[1]),
            'redirect' => null,
            'store' => (bool) ($in['store'] ?? true),
            'retention_days' => max(0, min(3650, (int) ($in['retention_days'] ?? 0))),
            'per_ip_hour' => max(1, min(1000, (int) ($in['per_ip_hour'] ?? self::DEFAULT_PER_IP_HOUR))),
            'captcha' => (bool) ($in['captcha'] ?? false),
            'notifications' => [],
            'fields' => [],
        ];
        $redirect = trim((string) (is_scalar($in['redirect'] ?? null) ? $in['redirect'] : ''));
        if ($redirect !== '') {
            // a path must stay on this site: browsers read "/\host" as "//host"
            $local = str_starts_with($redirect, '/') && preg_match('/\s/', $redirect) !== 1 && \Kestello\Cms\Auth\External\ReturnPath::sanitize($redirect) !== null;
            if (!$local && (str_starts_with($redirect, '/') || filter_var($redirect, FILTER_VALIDATE_URL) === false)) {
                $errors['redirect'][] = Translator::get('A path on this site such as /thanks, or a full https:// address.');
            } elseif (!str_starts_with($redirect, '/') && !preg_match('#^https?://#i', $redirect)) {
                $errors['redirect'][] = Translator::get('Only http and https addresses.');
            } else {
                $def['redirect'] = mb_substr($redirect, 0, 1000);
            }
        }
        if ($def['captcha'] && !$this->cms->container->has(Captcha::class)) {
            $errors['captcha'][] = Translator::get('No captcha service is installed. Install a plugin that provides one first.');
        }

        // fields
        $names = [];
        $list = is_array($in['fields'] ?? null) ? array_values($in['fields']) : [];
        if (count($list) > self::MAX_FIELDS) {
            $errors['fields'][] = Translator::get('At most {max} fields.', ['max' => self::MAX_FIELDS]);
            $list = array_slice($list, 0, self::MAX_FIELDS);
        }
        foreach ($list as $i => $f) {
            $p = "fields.{$i}";
            if (!is_array($f)) {
                $errors[$p][] = Translator::get('Not a field.');
                continue;
            }
            $name = (string) ($f['name'] ?? '');
            $type = (string) ($f['type'] ?? '');
            if (preg_match('/^[a-z][a-z0-9_]{0,63}$/', $name) !== 1) {
                $errors["{$p}.name"][] = Translator::get('Lowercase letters, digits and underscores, starting with a letter.');
            } elseif (isset($names[$name])) {
                $errors["{$p}.name"][] = Translator::get('Another field is already called {name}.', ['name' => $name]);
            }
            if (!isset(self::OPTIONS[$type])) {
                $errors["{$p}.type"][] = Translator::get('Choose a field type.');
                continue;
            }
            [$conditions, $conditionErrors] = Conditions::normalize($f['conditions'] ?? null, array_keys($names));
            foreach ($conditionErrors as $m) {
                $errors["{$p}.conditions"][] = $m;
            }
            $names[$name] = true;
            $label = trim((string) ($f['label'] ?? ''));
            $default = $f['default'] ?? null;
            $field = [
                'id' => preg_match('/^[a-z0-9]{8}$/', (string) ($f['id'] ?? '')) === 1 ? (string) $f['id'] : bin2hex(random_bytes(4)),
                'name' => $name,
                'type' => $type,
                'label' => $label !== '' ? mb_substr($label, 0, 200) : ucfirst(str_replace('_', ' ', $name)),
                'help' => mb_substr(trim((string) ($f['help'] ?? '')), 0, 300),
                'required' => (bool) ($f['required'] ?? false),
                'width' => ($f['width'] ?? 'full') === 'half' ? 'half' : 'full',
                'default' => in_array($type, ['file', 'multi_select'], true) || !is_scalar($default) || $default === '' ? null : (is_string($default) ? mb_substr($default, 0, 1000) : $default),
                'options' => $this->options($type, is_array($f['options'] ?? null) ? $f['options'] : [], "{$p}.options", $errors),
                'conditions' => $conditions,
            ];
            if ($type !== 'file' && !array_filter(array_keys($errors), static fn($k) => str_starts_with($k, $p . '.'))) {
                try {
                    $this->cms->content->fields->get($type)->schema(self::field($field), $this->cms->content->fields);
                } catch (\Throwable $e) {
                    $errors[$p][] = $e->getMessage();
                }
            }
            if ($type === 'file' && !$def['store']) {
                $errors["{$p}.type"][] = Translator::get('File uploads need submissions to be kept (Settings: keep submissions).');
            }
            $def['fields'][] = $field;
        }
        if ($def['fields'] === [] && !isset($errors['fields'])) {
            $errors['fields'][] = Translator::get('Add at least one field.');
        }

        // notifications
        foreach (array_slice(is_array($in['notifications'] ?? null) ? array_values($in['notifications']) : [], 0, 5) as $i => $n) {
            if (!is_array($n)) {
                continue;
            }
            $to = array_values(array_filter(array_map('trim', preg_split('/[,;\s]+/', (string) ($n['to'] ?? '')) ?: [])));
            foreach ($to as $address) {
                if (filter_var($address, FILTER_VALIDATE_EMAIL) === false) {
                    $errors["notifications.{$i}.to"][] = Translator::get('{address} is not an email address.', ['address' => $address]);
                }
            }
            if ($to === []) {
                $errors["notifications.{$i}.to"][] = Translator::get('At least one address.');
            }
            $replyTo = (string) ($n['reply_to'] ?? '');
            if ($replyTo !== '' && !in_array($replyTo, array_column(array_filter($def['fields'], static fn($f) => $f['type'] === 'email'), 'name'), true)) {
                $errors["notifications.{$i}.reply_to"][] = Translator::get('Choose an email field of this form.');
            }
            $def['notifications'][] = [
                'to' => array_slice($to, 0, 10),
                'subject' => mb_substr(trim((string) ($n['subject'] ?? '')), 0, 200),
                'reply_to' => $replyTo !== '' ? $replyTo : null,
            ];
        }
        if ($def['notifications'] === [] && !$def['store']) {
            $errors['store'][] = Translator::get('Keep submissions or send them by email, or they go nowhere.');
        }

        return [$def, $errors];
    }

    private function options(string $type, array $in, string $at, array &$errors): array
    {
        $out = [];
        foreach (self::OPTIONS[$type] as $key => $kind) {
            if (!array_key_exists($key, $in) || $in[$key] === null || $in[$key] === '') {
                continue;
            }
            $v = $in[$key];
            switch ($kind) {
                case 'string':
                    $out[$key] = mb_substr(trim((string) (is_scalar($v) ? $v : '')), 0, 200);
                    break;
                case 'int':
                    is_numeric($v) && (int) $v >= 0 && (int) $v <= 1_000_000 ? $out[$key] = (int) $v : $errors["{$at}.{$key}"][] = Translator::get('A whole number.');
                    break;
                case 'number':
                    is_numeric($v) ? $out[$key] = $v + 0 : $errors["{$at}.{$key}"][] = Translator::get('A number.');
                    break;
                case 'bool':
                    $out[$key] = (bool) $v;
                    break;
                case 'display':
                    $out[$key] = $v === 'radios' ? 'radios' : 'dropdown';
                    break;
                case 'kinds':
                    $out[$key] = array_values(array_intersect(MediaTypes::KINDS, (array) $v));
                    break;
                case 'choices':
                    $choices = [];
                    foreach ((array) $v as $value => $label) {
                        if (is_array($label)) {
                            [$value, $label] = [(string) ($label['value'] ?? ''), (string) ($label['label'] ?? '')];
                        }
                        $value = trim((string) $value);
                        if ($value === '' || mb_strlen($value) > 100 || isset($choices[$value])) {
                            $errors["{$at}.{$key}"][] = Translator::get('Each choice needs its own value (up to 100 characters).');
                            break;
                        }
                        $choices[$value] = mb_substr(trim((string) $label) !== '' ? trim((string) $label) : $value, 0, 200);
                    }
                    if ($choices === [] || count($choices) > 200) {
                        $errors["{$at}.{$key}"][] = Translator::get('Between 1 and 200 choices.');
                    }
                    $out[$key] = $choices;
                    break;
            }
        }
        if ($type === 'file') {
            if (isset($out['max_size']) && ($out['max_size'] < 1 || $out['max_size'] > self::MAX_UPLOAD_MB)) {
                $errors["{$at}.max_size"][] = Translator::get('Between 1 and {max} MB.', ['max' => self::MAX_UPLOAD_MB]);
            }
            $out['max_size'] = min(self::MAX_UPLOAD_MB, max(1, (int) ($out['max_size'] ?? 5)));
            $out['max'] = !empty($out['multiple']) ? max(1, min(10, (int) ($out['max'] ?? 5))) : 1;
        }

        return $out;
    }

    /** A content Field for a form field, so the content field types validate it. */
    private static function field(array $f): Field
    {
        $field = Field::make($f['name'], $f['type'])->label($f['label'])->required((bool) $f['required']);
        foreach ($f['options'] as $k => $v) {
            if ($k !== 'display') {
                $field->option($k, $v);
            }
        }

        return $field;
    }

    // ── the public side ──────────────────────────────────────────────────

    /** What the form block renders: never notification addresses or settings. */
    public function publicDefinition(int $id): ?array
    {
        try {
            $form = $this->find($id);
        } catch (OutOfBoundsException) {
            return null;
        }
        $d = $form['definition'];
        $types = $this->mediaTypes();
        $captcha = $d['captcha'] && $this->cms->container->has(Captcha::class) ? $this->cms->container->make(Captcha::class)->widget() : null;

        return [
            'id' => $form['id'],
            'title' => $form['title'],
            'open' => $form['status'] === 'open',
            'closed_message' => self::wording($d, 'closed_message'),
            'submit_label' => self::wording($d, 'submit_label'),
            'captcha' => $captcha,
            'fields' => array_map(static function (array $f) use ($types): array {
                $o = $f['options'];
                if (isset($o['choices'])) {
                    $o['choices'] = array_map(static fn($v, $l) => ['value' => (string) $v, 'label' => $l], array_keys($o['choices']), $o['choices']);
                }
                if ($f['type'] === 'file') {
                    $o['accept'] = implode(',', array_filter(array_map(static fn($k) => $types->accept($k), $o['kinds'] ?: $types->enabledKinds())));
                }
                return array_intersect_key($f, array_flip(['name', 'type', 'label', 'help', 'required', 'width', 'default', 'conditions'])) + ['options' => (object) $o];
            }, $d['fields']),
            // the block's own words, in the site's language: the site loads no translations
            'texts' => [
                'sending' => Translator::get('Sending…'),
                'honeypot' => Translator::get('Leave this empty'),
                'choose' => Translator::get('Choose…'),
                'check' => Translator::get('Please check the form and try again.'),
                'too_many' => Translator::get('Too many tries from here. Please wait a while and send it again.'),
                'failed' => Translator::get('Something went wrong. Please reload the page and try again.'),
                'offline' => Translator::get('The form could not be sent. Check your connection and try again.'),
                // under a file field; the Form block fills in {size} and {count}
                'file_one' => Translator::get('Up to {size} MB.'),
                'file_many' => Translator::get('Up to {count} files, {size} MB each.'),
            ],
        ];
    }

    /** A token proving the form was loaded a moment ago by a browser (pages are cached, so there is no session). */
    public function token(int $formId): string
    {
        $payload = $formId . '.' . time();

        return $payload . '.' . hash_hmac('sha256', $payload, AppKey::derive('forms'));
    }

    /** null when fine, otherwise why not. */
    private function checkToken(int $formId, string $token): ?string
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3 || (int) $parts[0] !== $formId || !ctype_digit($parts[1])) {
            return 'invalid';
        }
        $valid = false;
        foreach ([AppKey::derive('forms'), ...AppKey::derivePrevious('forms')] as $key) {
            $valid = $valid || hash_equals(hash_hmac('sha256', $parts[0] . '.' . $parts[1], $key), $parts[2]);
        }
        if (!$valid) {
            return 'invalid';
        }
        $age = time() - (int) $parts[1];

        return $age < self::MIN_FILL_SECONDS ? 'fast' : ($age > self::TOKEN_LIFETIME ? 'expired' : null);
    }

    /**
     * Take a submission.
     *
     * @param array<string, mixed> $post  form fields as sent (multipart or JSON)
     * @param array<string, mixed> $files PHP's $_FILES shape
     * @param array{ip: string, user_agent: string, page: string, user_id: ?int} $meta
     * @return array{id: ?int, message: string, redirect: ?string, spam?: bool}
     * @throws ContentException invalid (field errors), or forbidden/conflict
     */
    public function submit(int $formId, array $post, array $files, array $meta): array
    {
        $form = $this->find($formId);
        $d = $form['definition'];
        $done = ['id' => null, 'message' => self::wording($d, 'success_message'), 'redirect' => $d['redirect']];
        if ($form['status'] !== 'open') {
            throw ContentException::conflict(self::wording($d, 'closed_message'));
        }
        // a filled honeypot looks like success to the bot, and nothing happens
        if (trim((string) (is_scalar($post['_hp'] ?? null) ? $post['_hp'] : '')) !== '') {
            return $done + ['spam' => true];
        }
        $why = $this->checkToken($formId, (string) (is_scalar($post['_token'] ?? null) ? $post['_token'] : ''));
        if ($why === 'fast') {
            throw ContentException::invalid(['_form' => [Translator::get('That was quick. Please check the form and send it again.')]]);
        }
        if ($why !== null) {
            // _token tells the form block to fetch a new one, whatever language the words are in
            throw ContentException::invalid(['_form' => [Translator::get('The form has expired. Reload the page and send it again.')], '_token' => ['expired']]);
        }
        if ($d['captcha'] && $this->cms->container->has(Captcha::class)) {
            $captcha = $this->cms->container->make(Captcha::class);
            if (!$captcha->verify((string) (is_scalar($post['_captcha'] ?? null) ? $post['_captcha'] : ''), $meta['ip'])) {
                throw ContentException::invalid(['_form' => [Translator::get('Please confirm you are not a robot.')]]);
            }
        }

        // values: only this form's fields, and only those its conditions show
        $values = [];
        foreach ($d['fields'] as $f) {
            if ($f['type'] !== 'file' && array_key_exists($f['name'], $post)) {
                $values[$f['name']] = self::cast($f, $post[$f['name']]);
            }
        }
        $visible = Conditions::visible($d['fields'], $values);
        $shown = array_values(array_filter($d['fields'], static fn($f) => $visible[$f['name']] ?? true));
        $plain = array_values(array_filter($shown, static fn($f) => $f['type'] !== 'file'));
        $fieldObjects = array_map(self::field(...), $plain);
        $values = array_intersect_key($values, array_flip(array_column($plain, 'name')));
        [$clean, $schemaErrors] = $this->cms->content->fields->objectSchema($fieldObjects, false)->validate($values);
        $errors = [];
        foreach ($schemaErrors as $key => $messages) {
            $errors[$key === '_' ? '_form' : $key] = $messages;
        }
        // visitors read these: an empty required field gets plain words, not the validator's
        foreach ($plain as $f) {
            $v = $values[$f['name']] ?? null;
            if ($f['required'] && ($v === null || $v === '' || $v === [] || ($f['type'] === 'boolean' && $v === false))) {
                $errors[$f['name']] = [match (true) {
                    $f['type'] === 'boolean' => Translator::get('Please tick this box.'),
                    in_array($f['type'], ['select', 'multi_select'], true) => Translator::get('Please choose one.'),
                    default => Translator::get('Please fill this in.'),
                }];
            }
        }
        $data = $schemaErrors === [] ? $this->cms->content->fields->finish($fieldObjects, (array) $clean, '', $errors) : [];
        $uploads = [];
        foreach ($shown as $f) {
            if ($f['type'] === 'file') {
                $uploads[$f['name']] = $this->checkFiles($f, self::filesOf($files, $f['name']), $errors);
            }
        }
        if ($errors !== []) {
            throw ContentException::invalid($errors);
        }

        // keep the files, then the submission
        foreach ($uploads as $name => $list) {
            if ($list !== []) {
                $data[$name] = array_map(fn(array $u) => $this->store($formId, $u), $list);
            }
        }
        $ordered = [];
        foreach ($d['fields'] as $f) {
            if (array_key_exists($f['name'], $data)) {
                $ordered[$f['name']] = $data[$f['name']];
            }
        }
        $id = null;
        if ($d['store']) {
            $now = gmdate('Y-m-d H:i:s');
            $id = QueryBuilder::table('form_submissions')->insert([
                'form_id' => $formId, 'data' => Json::encode($ordered === [] ? new \stdClass() : $ordered), 'status' => 'new',
                'ip' => mb_substr($meta['ip'], 0, 45), 'user_agent' => mb_substr($meta['user_agent'], 0, 255),
                'page' => mb_substr($meta['page'], 0, 500), 'user_id' => $meta['user_id'], 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
        if ($d['notifications'] !== []) {
            $this->cms->queue->push(new SendFormNotification($formId, $id, $id === null ? $ordered : null, $meta['page']));
        }
        $this->cms->events->dispatch(new \Kestello\Cms\Events\FormSubmitted($formId, $id === null ? null : (int) $id, $ordered));

        return ['id' => $id === null ? null : (int) $id] + $done;
    }

    /** What a browser sends, in the shape the content field types expect. */
    private static function cast(array $f, mixed $v): mixed
    {
        if (is_string($v)) {
            $v = trim($v);
        }
        return match ($f['type']) {
            'number' => is_string($v) && $v !== '' && is_numeric($v) ? $v + 0 : ($v === '' ? null : $v),
            'boolean' => is_bool($v) ? $v : (is_string($v) ? in_array(strtolower($v), ['1', 'true', 'on', 'yes'], true) : (bool) $v),
            'multi_select' => is_array($v) ? array_values(array_filter(array_map(static fn($x) => is_scalar($x) ? (string) $x : null, $v), static fn($x) => $x !== null && $x !== '')) : ($v === '' || $v === null ? [] : [(string) $v]),
            default => $v === '' ? null : $v,
        };
    }

    /** @return list<array{name: string, tmp: string, size: int, error: int}> */
    private static function filesOf(array $files, string $name): array
    {
        $f = $files[$name] ?? null;
        if (!is_array($f) || !isset($f['name'])) {
            return [];
        }
        $out = [];
        foreach ((array) $f['name'] as $i => $n) {
            $err = is_array($f['error']) ? (int) ($f['error'][$i] ?? 4) : (int) $f['error'];
            if ($err === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            $out[] = ['name' => (string) $n, 'tmp' => (string) (is_array($f['tmp_name']) ? ($f['tmp_name'][$i] ?? '') : $f['tmp_name']),
                'size' => (int) (is_array($f['size']) ? ($f['size'][$i] ?? 0) : $f['size']), 'error' => $err];
        }

        return $out;
    }

    /** @return list<array{name: string, tmp: string, size: int, ext: string, mime: string}> */
    private function checkFiles(array $field, array $list, array &$errors): array
    {
        $o = $field['options'];
        $name = $field['name'];
        if ($list === []) {
            if ($field['required']) {
                $errors[$name][] = Translator::get('Please add a file.');
            }
            return [];
        }
        if (count($list) > (int) $o['max']) {
            $errors[$name][] = Translator::get('{max, plural, one {Only one file.} other {At most # files.}}', ['max' => (int) $o['max']]);
            return [];
        }
        $types = $this->mediaTypes();
        $ok = [];
        foreach ($list as $u) {
            $label = basename(str_replace('\\', '/', $u['name']));
            if ($u['error'] !== UPLOAD_ERR_OK || !is_file($u['tmp'])) {
                $errors[$name][] = in_array($u['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) ? Translator::get('{file} is too large.', ['file' => $label]) : Translator::get('{file} didn’t arrive. Please try again.', ['file' => $label]);
                continue;
            }
            if ($u['size'] > $o['max_size'] * 1024 * 1024 || filesize($u['tmp']) > $o['max_size'] * 1024 * 1024) {
                $errors[$name][] = Translator::get('{file} is larger than {size} MB.', ['file' => $label, 'size' => (string) $o['max_size']]);
                continue;
            }
            $problems = $types->checkName($label);
            $ext = strtolower(pathinfo($label, PATHINFO_EXTENSION));
            $type = $problems === [] ? $types->forExtension($ext) : null;
            if ($type === null) {
                $errors[$name][] = $problems[0] ?? Translator::get('{file}: this type of file is not accepted.', ['file' => $label]);
                continue;
            }
            if (($o['kinds'] ?? []) !== [] && !in_array($type['kind'], $o['kinds'], true)) {
                $errors[$name][] = Translator::get('{file}: only {kinds} files.', ['file' => $label, 'kinds' => implode(', ', $o['kinds'])]);
                continue;
            }
            $sniffed = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($u['tmp']);
            if (!$types->agrees($ext, $sniffed)) {
                $errors[$name][] = Translator::get('{file} doesn’t look like a .{ext} file.', ['file' => $label, 'ext' => $ext]);
                continue;
            }
            $ok[] = $u + ['ext' => $ext, 'mime' => $sniffed];
        }

        return $ok;
    }

    private function mediaTypes(): MediaTypes
    {
        return $this->cms->container->has(\Kestello\Cms\Media\MediaLibrary::class)
            ? $this->cms->container->make(\Kestello\Cms\Media\MediaLibrary::class)->types()
            : new MediaTypes();
    }

    /** Private storage, outside the public folder; downloaded only through the admin API. */
    private function store(int $formId, array $u): array
    {
        $key = $formId . '/' . gmdate('Y/m') . '/' . bin2hex(random_bytes(16)) . '.' . $u['ext'];
        $path = $this->filePath($key);
        if (!is_dir(dirname($path)) && !mkdir(dirname($path), 0750, true) && !is_dir(dirname($path))) {
            throw new \RuntimeException('Could not create the form uploads folder.');
        }
        if (!(is_uploaded_file($u['tmp']) ? move_uploaded_file($u['tmp'], $path) : rename($u['tmp'], $path))) {
            throw new \RuntimeException('Could not store an uploaded file.');
        }
        @chmod($path, 0640);

        return ['name' => mb_substr(basename(str_replace('\\', '/', $u['name'])), 0, 200), 'size' => (int) filesize($path), 'type' => $u['mime'], 'key' => $key];
    }

    public function filePath(string $key): string
    {
        if (preg_match('#^\d+/\d{4}/\d{2}/[a-f0-9]{32}\.[a-z0-9]{1,10}$#', $key) !== 1) {
            throw new OutOfBoundsException('No such file.');
        }

        return $this->cms->paths->storage('forms/' . $key);
    }

    // ── submissions (admin) ──────────────────────────────────────────────

    public function submission(int $id): array
    {
        $row = QueryBuilder::table('form_submissions')->where('id', $id)->first();

        return $row === null ? throw new OutOfBoundsException('No such submission.') : self::presentSubmission($row);
    }

    /**
     * The button text, thank-you or closed message as visitors see it: what the
     * form says, or the default in the site's language when it was left empty
     * or is the English default (forms saved before their site had another language).
     *
     * @param array<string, mixed> $definition
     * @param 'submit_label'|'success_message'|'closed_message' $key
     */
    public static function wording(array $definition, string $key): string
    {
        $text = trim((string) ($definition[$key] ?? ''));
        [$english, $translated] = self::defaultWording($key);

        return $text === '' || $text === $english ? $translated : $text;
    }

    /**
     * @param 'submit_label'|'success_message'|'closed_message' $key
     * @return array{string, string} the default in English, and in the site's language
     */
    private static function defaultWording(string $key): array
    {
        return match ($key) {
            'submit_label' => ['Send', Translator::get('Send')],
            'success_message' => ['Thank you. We received your message.', Translator::get('Thank you. We received your message.')],
            'closed_message' => ['This form is closed.', Translator::get('This form is closed.')],
        };
    }

    public static function presentSubmission(array $row): array
    {
        return ['id' => (int) $row['id'], 'form_id' => (int) $row['form_id'], 'data' => Json::decode((string) $row['data']), 'status' => (string) $row['status'],
            'ip' => (string) $row['ip'], 'user_agent' => (string) $row['user_agent'], 'page' => (string) $row['page'],
            'user_id' => $row['user_id'] === null ? null : (int) $row['user_id'], 'created_at' => Date::isoUtc($row['created_at'])];
    }

    public function setStatus(int $id, string $status): array
    {
        $this->submission($id);
        QueryBuilder::table('form_submissions')->where('id', $id)->update(['status' => $status, 'updated_at' => gmdate('Y-m-d H:i:s')]);

        return $this->submission($id);
    }

    public function deleteSubmission(int $id): void
    {
        $this->submission($id);
        $this->deleteFiles(QueryBuilder::table('form_submissions')->where('id', $id));
        QueryBuilder::table('form_submissions')->where('id', $id)->delete();
    }

    /** Delete the files of the submissions $query selects. */
    private function deleteFiles(QueryBuilder $query): void
    {
        foreach ((clone $query)->select('data')->get() as $row) {
            foreach ((array) Json::decode((string) $row['data']) as $value) {
                foreach (is_array($value) && array_is_list($value) ? $value : [] as $file) {
                    if (is_array($file) && isset($file['key'])) {
                        try {
                            @unlink($this->filePath((string) $file['key']));
                        } catch (OutOfBoundsException) {
                        }
                    }
                }
            }
        }
    }

    /** Remove submissions older than each form's retention. @return int removed */
    public function prune(): int
    {
        $removed = 0;
        foreach (QueryBuilder::table('forms')->get() as $row) {
            $days = (int) (Json::decode((string) $row['definition'])['retention_days'] ?? 0);
            if ($days <= 0) {
                continue;
            }
            $old = fn() => QueryBuilder::table('form_submissions')->where('form_id', (int) $row['id'])->where('created_at', '<', gmdate('Y-m-d H:i:s', time() - $days * 86400));
            $this->deleteFiles($old());
            $removed += $old()->delete();
        }

        return $removed;
    }

    /** All of a form's submissions as CSV (newest first), spam left out unless asked for. */
    public function csv(int $formId, bool $spam = false): string
    {
        $form = $this->find($formId);
        $fields = $form['definition']['fields'];
        $out = fopen('php://temp', 'w+');
        fputcsv($out, ['ID', 'Sent (UTC)', 'Status', ...array_column($fields, 'label'), 'Page'], escape: '');
        $q = QueryBuilder::table('form_submissions')->where('form_id', $formId)->orderBy('id', 'DESC');
        if (!$spam) {
            $q->where('status', '!=', 'spam');
        }
        foreach ($q->get() as $row) {
            $data = (array) Json::decode((string) $row['data']);
            $cells = [];
            foreach ($fields as $f) {
                $v = $data[$f['name']] ?? null;
                $cells[] = match (true) {
                    $f['type'] === 'file' => implode(', ', array_map(static fn($x) => (string) ($x['name'] ?? ''), is_array($v) ? $v : [])),
                    $f['type'] === 'boolean' => $v === null ? '' : ($v ? 'Yes' : 'No'),
                    is_array($v) => implode(', ', array_map(static fn($x) => (string) ($f['options']['choices'][$x] ?? $x), $v)),
                    $f['type'] === 'select' && $v !== null => (string) ($f['options']['choices'][$v] ?? $v),
                    default => (string) ($v ?? ''),
                };
            }
            fputcsv($out, array_map(self::cell(...), [(string) $row['id'], (string) $row['created_at'], (string) $row['status'], ...$cells, (string) $row['page']]), escape: '');
        }
        rewind($out);
        $csv = (string) stream_get_contents($out);
        fclose($out);

        return "\u{FEFF}" . $csv;    // a byte order mark, so spreadsheet apps read UTF-8
    }

    /** Text that a spreadsheet would run as a formula gets a leading apostrophe. */
    private static function cell(string $value): string
    {
        return $value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'" . $value : $value;
    }
}
