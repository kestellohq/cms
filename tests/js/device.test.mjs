import { test } from 'node:test';
import assert from 'node:assert/strict';
import { deviceLabel } from '../../core/resources/js/components/account/device.js';

const t = (text, values = {}) => text.replace(/\{(\w+)\}/g, (_, k) => values[k]);

test('a user agent reads as a browser on a system', () => {
    const mac = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 14_5) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36';
    assert.equal(deviceLabel(mac, t), 'Chrome on macOS');
    assert.equal(
        deviceLabel('Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 Version/18.0 Mobile Safari/604.1', t),
        'Safari on iOS',
        'an iPhone says Mac OS X too',
    );
    assert.equal(
        deviceLabel('Mozilla/5.0 (Windows NT 10.0) AppleWebKit/537.36 Chrome/130.0 Safari/537.36 Edg/130.0', t),
        'Edge on Windows',
        'Edge says Chrome too',
    );
    assert.equal(deviceLabel('curl/8.7.1', t), 'Unknown browser');
    assert.equal(deviceLabel(null, t), 'Unknown browser');
});
