import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createErrorReporter, errorPayload } from '../../core/resources/js/app/errorReport.js';

test('an error becomes what the endpoint takes', () => {
    const error = new TypeError("Cannot read properties of null (reading 'id')");
    const payload = errorPayload(error, 'render function', '/admin/content/page/1');
    assert.equal(payload.message, "TypeError: Cannot read properties of null (reading 'id')");
    assert.equal(payload.screen, '/admin/content/page/1');
    assert.equal(payload.where, 'render function');
    assert.ok(payload.stack.includes('TypeError'));

    assert.deepEqual(errorPayload('a string', '', '/admin'), { message: 'a string', screen: '/admin' }, 'no where or stack when there are none');
    assert.equal(errorPayload({ code: 7 }, '', '/admin').message, '{"code":7}');
    assert.equal(errorPayload(undefined, '', '/admin').message, 'Unknown error');
    const circular = {};
    circular.self = circular;
    assert.equal(errorPayload(circular, '', '/admin').message, '[object Object]');
});

test('fields are cut to what the endpoint accepts', () => {
    const error = new Error('x'.repeat(2000));
    error.stack = 's'.repeat(9000);
    const payload = errorPayload(error, 'w'.repeat(500), '/'.repeat(500));
    assert.equal(payload.message.length, 500);
    assert.equal(payload.stack.length, 4000);
    assert.equal(payload.where.length, 200);
    assert.equal(payload.screen.length, 300);
});

test('the same error is sent once, and at most the limit per page load', async () => {
    const sent = [];
    const report = createErrorReporter({ send: (p) => sent.push(p), screen: () => '/admin', limit: 3 });
    assert.equal(report(new Error('a'), 'render'), true);
    assert.equal(report(new Error('a'), 'render'), false, 'the same error again');
    assert.equal(report(new Error('a'), 'promise'), true, 'the same message from elsewhere counts');
    assert.equal(report(new Error('b')), true);
    assert.equal(report(new Error('c')), false, 'over the limit');
    await new Promise((resolve) => setTimeout(resolve, 0));
    assert.deepEqual(
        sent.map((p) => p.message),
        ['Error: a', 'Error: a', 'Error: b'],
    );
});

test('sending never throws, even when the send itself fails', async () => {
    const throwing = createErrorReporter({
        send: () => {
            throw new Error('offline');
        },
    });
    assert.equal(throwing(new Error('a')), true);
    const rejecting = createErrorReporter({ send: () => Promise.reject(new Error('offline')) });
    assert.equal(rejecting(new Error('a')), true);
    const badScreen = createErrorReporter({
        send: () => {},
        screen: () => {
            throw new Error('no location');
        },
    });
    assert.equal(badScreen(new Error('a')), false);
    await new Promise((resolve) => setTimeout(resolve, 0));
});
