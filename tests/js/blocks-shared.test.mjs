import { test } from 'node:test';
import assert from 'node:assert/strict';
import {
    addColumn,
    addRow,
    blankTable,
    MAX_COLUMNS,
    moveRow,
    parseGrid,
    pasteGrid,
    removeColumn,
    removeRow,
    setCell,
    tidy,
} from '../../core/resources/js/shared/table.js';
import { panelId, tabFromKey, tabId } from '../../core/resources/js/shared/tabs.js';

test('table: rows and columns come and go; the value given is never changed', () => {
    const t = blankTable(2, 2);
    const frozen = JSON.stringify(t);
    let n = setCell(t, 0, 1, 'Price');
    n = addRow(n, 1);
    n = addColumn(n);
    assert.deepEqual(n.rows, [
        ['', 'Price', ''],
        ['', '', ''],
        ['', '', ''],
    ]);
    assert.deepEqual(removeColumn(n, 1).rows[0], ['', '']);
    assert.deepEqual(moveRow(n, 0, 1).rows[1], ['', 'Price', '']);
    assert.equal(moveRow(n, 0, -1), n, 'nowhere to go: the same table');
    assert.equal(JSON.stringify(t), frozen);
    const one = { head: false, rows: [['x']] };
    assert.equal(removeRow(one, 0), one, 'a table keeps its last cell');
    assert.equal(removeColumn(one, 0), one);
    const wide = blankTable(MAX_COLUMNS, 1);
    assert.equal(addColumn(wide), wide, 'no more than the server allows');
});

test('table: anything becomes a well-formed table', () => {
    assert.deepEqual(tidy(null), blankTable());
    assert.deepEqual(tidy({ head: 1, rows: [['a', 'b'], ['c'], 'junk', [3]] }), {
        head: true,
        rows: [
            ['a', 'b'],
            ['c', ''],
            ['3', ''],
        ],
    });
});

test('table: text from a spreadsheet pastes as cells and grows the table', () => {
    assert.equal(parseGrid('just words'), null, 'plain text pastes into the one cell');
    assert.deepEqual(parseGrid('Day\tOpen\nMon\t9–17\n'), [
        ['Day', 'Open'],
        ['Mon', '9–17'],
    ]);
    assert.deepEqual(parseGrid('"a\tb"\t"say ""hi"""\r\n"two\nlines"\tx'), [
        ['a\tb', 'say "hi"'],
        ['two\nlines', 'x'],
    ]);
    const t = pasteGrid(blankTable(2, 1), 0, 1, [
        ['A', 'B'],
        ['C', 'D'],
    ]);
    assert.deepEqual(t.rows, [
        ['', 'A', 'B'],
        ['', 'C', 'D'],
    ]);
});

test('tabs: arrow keys wrap around, Home and End go to the ends', () => {
    assert.equal(tabFromKey('ArrowRight', 2, 3), 0);
    assert.equal(tabFromKey('ArrowLeft', 0, 3), 2);
    assert.equal(tabFromKey('ArrowDown', 0, 3), 1);
    assert.equal(tabFromKey('Home', 2, 3), 0);
    assert.equal(tabFromKey('End', 0, 3), 2);
    assert.equal(tabFromKey('a', 0, 3), null);
    assert.equal(tabFromKey('ArrowRight', 0, 0), null);
    assert.equal(tabId('x1', 2), 'k-tab-x1-2');
    assert.equal(panelId('x1', 2), 'k-panel-x1-2');
});
