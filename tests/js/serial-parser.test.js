import test from 'node:test';
import assert from 'node:assert/strict';

import { parseReadingLine, drainLines } from '../../resources/js/kiosk/serial.js';

/**
 * Adversarial input for the §11.2 serial parser (FR-KSK-07 hardening).
 *
 * Run with `npm run test:js` (Node's built-in test runner — no packages).
 * The contract under test: a malformed token is SKIPPED, a wholly malformed
 * line yields {} (the reader drops it and keeps reading), unknown keys are
 * ignored, and absurd-but-parseable values ARE returned — range validation
 * is the state machine's job (inRange/captureStepFromSensor), never the
 * parser's. Nothing here may throw.
 */

test('a clean combined line parses every field', () => {
    assert.deepEqual(parseReadingLine('H:163;W:64;T:37.9;BP:145/92;HR:78'), {
        H: 163, W: 64, T: 37.9, S: 145, D: 92, R: 78,
    });
});

test('a partial line returns only the keys present', () => {
    assert.deepEqual(parseReadingLine('H:163'), { H: 163 });
    assert.deepEqual(parseReadingLine('BP:118/76'), { S: 118, D: 76 });
});

test('garbage bytes yield an empty reading, never a throw', () => {
    assert.deepEqual(parseReadingLine('\x00\xFF\x07\x1b[2Jgarbage'), {});
    assert.deepEqual(parseReadingLine('%$#@!;;;:::'), {});
    assert.deepEqual(parseReadingLine(''), {});
});

test('non-string input yields an empty reading, never a throw', () => {
    assert.deepEqual(parseReadingLine(null), {});
    assert.deepEqual(parseReadingLine(undefined), {});
    assert.deepEqual(parseReadingLine(42), {});
    assert.deepEqual(parseReadingLine({ H: 163 }), {});
});

test('unknown keys are ignored; known keys on the same line survive', () => {
    assert.deepEqual(parseReadingLine('SPO2:98;H:163;GLUCOSE:5.4'), { H: 163 });
});

test('malformed tokens are skipped; good tokens on the same line survive', () => {
    assert.deepEqual(parseReadingLine('H:;W:64'), { W: 64 }); // empty value
    assert.deepEqual(parseReadingLine(':170;W:64'), { W: 64 }); // no key
    assert.deepEqual(parseReadingLine('H 163;W:64'), { W: 64 }); // no colon
    assert.deepEqual(parseReadingLine('H:16x3;W:64'), { W: 64 }); // non-numeric
    assert.deepEqual(parseReadingLine('H:1e999;W:64'), { W: 64 }); // Infinity is not finite
});

test('a half-read BP pair is dropped whole — a lone systolic is meaningless', () => {
    assert.deepEqual(parseReadingLine('BP:145/'), {});
    assert.deepEqual(parseReadingLine('BP:/92'), {});
    assert.deepEqual(parseReadingLine('BP:145'), {});
    assert.deepEqual(parseReadingLine('BP:abc/def'), {});
    assert.deepEqual(parseReadingLine('BP:145/9x'), {});
});

test('two torn halves merged into one token do not produce a fake reading', () => {
    // A dropped newline can weld "H:16" + "H:163" into one token; the welded
    // value "16H:163" is not a number, so the token is skipped — the kiosk
    // must never capture the truncated 16 cm as a height.
    assert.deepEqual(parseReadingLine('H:16H:163'), {});
});

test('absurd-but-parseable values ARE parsed — range rejection happens downstream', () => {
    // T:99 (°C) and H:999 (cm) are valid numbers on the wire. The parser hands
    // them through; kiosk-state-machine.test.js proves the state machine then
    // FAILS them against config ranges and prompts retry/manual (FR-KSK-08).
    assert.deepEqual(parseReadingLine('T:99'), { T: 99 });
    assert.deepEqual(parseReadingLine('H:999'), { H: 999 });
    assert.deepEqual(parseReadingLine('T:-40'), { T: -40 });
    assert.deepEqual(parseReadingLine('BP:999/999'), { S: 999, D: 999 });
});

test('whitespace and case are tolerated', () => {
    assert.deepEqual(parseReadingLine('  h : 163 ; hr : 78 '), { H: 163, R: 78 });
});

// ── Chunk reassembly (half lines) ────────────────────────────────────────────
// Serial data arrives in arbitrary chunks; drainLines() buffers until a
// newline completes a line. A half line must NEVER be parsed early.

test('a chunk without a newline stays buffered — no premature parse', () => {
    assert.deepEqual(drainLines('H:16'), { lines: [], rest: 'H:16' });
});

test('a torn line is reassembled across chunks', () => {
    const first = drainLines('H:16');
    const second = drainLines(first.rest + '3\nW:6');
    assert.deepEqual(second.lines, ['H:163']);
    assert.equal(second.rest, 'W:6');
});

test('CRLF line endings are tolerated', () => {
    assert.deepEqual(drainLines('H:163\r\nW:64\r\n'), {
        lines: ['H:163', 'W:64'],
        rest: '',
    });
});

test('several complete lines in one chunk all come out', () => {
    assert.deepEqual(drainLines('H:163\nW:64\nT:36.8\n'), {
        lines: ['H:163', 'W:64', 'T:36.8'],
        rest: '',
    });
});
