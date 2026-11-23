import assert from 'node:assert/strict';
import { test } from 'node:test';

import { escapeTags } from '../markdown-tags.mts';

test('tag-like text that is not HTML is escaped so the editor keeps it', () => {
  assert.equal(escapeTags('Protect <domain> and <your-site></your-site>'), 'Protect \\<domain> and \\<your-site>\\</your-site>');
  assert.equal(escapeTags('<a><b>'), '<a><b>');
  assert.equal(escapeTags('<x><y>'), '\\<x>\\<y>');
  assert.equal(escapeTags('`a`<domain>'), '`a`\\<domain>');
  assert.equal(escapeTags('an open ` then <domain>'), 'an open ` then \\<domain>');
});

test('real HTML, code, autolinks, plain comparisons and escaped text are left alone', () => {
  assert.equal(escapeTags('Fast<br />and <strong>safe</strong> <a href="/x">link</a> <!-- c -->'), 'Fast<br />and <strong>safe</strong> <a href="/x">link</a> <!-- c -->');
  assert.equal(escapeTags('a < b, already \\<x>'), 'a < b, already \\<x>');
  assert.equal(escapeTags('Use `<domain>` here\n```\n<domain>\n```'), 'Use `<domain>` here\n```\n<domain>\n```');
  assert.equal(escapeTags('Run ``ssh `whoami`@<domain>`` now'), 'Run ``ssh `whoami`@<domain>`` now');
  assert.equal(escapeTags('A backtick (\\`) is special. Run `ssh <domain>` here.'), 'A backtick (\\`) is special. Run `ssh <domain>` here.');
  assert.equal(escapeTags('[Setup guide](<docs/Setup Guide.pdf>)'), '[Setup guide](<docs/Setup Guide.pdf>)');
  assert.equal(escapeTags('See <https://example.com/a> or <me@example.com>'), 'See <https://example.com/a> or <me@example.com>');
});
