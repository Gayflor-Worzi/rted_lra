<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Source files must stay valid UTF-8 and free of mojibake.
 *
 * ManagerDashboardService.php was found rendering the account manager dashboard
 * with U+00F0 U+0178 U+201C style garbage where emoji icons belonged. The bytes
 * were valid UTF-8, so nothing at the response layer could detect it - the
 * damage was committed to the source by bulk edits that re-encoded UTF-8 as
 * cp1252 and back, repeatedly. Several passes were lossy enough to destroy the
 * original C1 control bytes, so the mojibake could not be reversed
 * automatically and had to be rebuilt by hand.
 *
 * This guard fails loudly at the next edit instead of at the next user report.
 */
class SourceEncodingTest extends TestCase
{
    /**
     * Directories scanned for encoding damage. Vendor, build output, storage
     * and the test fixtures are excluded: they are either generated or not
     * author-edited.
     *
     * @var list<string>
     */
    private const ROOTS = [
        'app',
        'config',
        'database/migrations',
        'routes',
        'frontend/src',
        'mobile/src',
        'tests',
        'docs',
    ];

    /**
     * Extensions worth scanning.
     *
     * @var list<string>
     */
    private const EXTENSIONS = ['php', 'js', 'jsx', 'ts', 'tsx', 'vue', 'json', 'md', 'blade.php'];

    /**
     * Continuation characters: the bytes a mis-decode leaves after its lead.
     *
     * Covers the Latin-1 supplement, the C1 control block (never produced by a
     * correct encode) and the cp1252 repertoire (curly quotes, dashes,
     * trademark, ellipsis...). U+FE0F is excluded on purpose: it is the emoji
     * variation selector and is legitimately paired with pictographs.
     *
     * @var list<string>
     */
    private const CONTINUATIONS = [
        "\u{00A0}-\u{00FF}",
        "\u{0080}-\u{009F}",
        "\u{20AC}", "\u{201A}", "\u{0192}", "\u{201E}", "\u{2026}", "\u{2020}", "\u{2021}",
        "\u{02C6}", "\u{2030}", "\u{0160}", "\u{2039}", "\u{0152}", "\u{017D}", "\u{2018}",
        "\u{2019}", "\u{201C}", "\u{201D}", "\u{2022}", "\u{2013}", "\u{2014}", "\u{02DC}",
        "\u{2122}", "\u{0161}", "\u{203A}", "\u{0153}", "\u{017E}", "\u{0178}",
    ];

    /**
     * Characters a mis-decode produces at the *start* of a run.
     *
     * A correctly encoded file never puts one of these Latin-1 letters directly
     * in front of a cp1252 repertoire character, so the pair — not either half
     * on its own — is what identifies damage. Matching the continuation set
     * alone would flag every legitimate em dash, bullet and curly quote.
     *
     * @var list<string>
     */
    private const LEADS = [
        "\u{00F0}", "\u{00E2}", "\u{00C3}", "\u{00C5}", "\u{00C2}", "\u{00EF}",
    ];

    /** @return list<string> */
    private function sourceFiles(): array
    {
        $root = dirname(__DIR__, 2);
        $files = [];

        foreach (self::ROOTS as $dir) {
            $path = $root.'/'.$dir;
            if (! is_dir($path)) {
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS)
            );

            foreach ($iterator as $file) {
                if (! $file->isFile()) {
                    continue;
                }
                $name = $file->getFilename();
                $ok = false;
                foreach (self::EXTENSIONS as $ext) {
                    if (str_ends_with($name, '.'.$ext)) {
                        $ok = true;
                        break;
                    }
                }
                if ($ok) {
                    $files[] = $file->getPathname();
                }
            }
        }

        sort($files);

        return $files;
    }

    /**
     * A mojibake run: a Latin-1 lead character followed by one or more
     * continuation characters.
     *
     * Both halves are emitted as \x{...} escapes rather than as literal
     * characters. Building the character class from raw bytes is what made an
     * earlier version of this test report ~2500 false positives. The sets are
     * spelled out here, and documented by self::LEADS and
     * self::CONTINUATIONS.
     */
    private function markerClass(): string
    {
        $leads = 'F0 E2 C3 C5 C2 EF';

        $continuations =
            '00A0-00FF 0080-009F'
            .' 20AC 201A 0192 201E 2026 2020 2021 02C6 2030'
            .' 0160 2039 0152 017D 2018 2019 201C 201D 2022'
            .' 2013 2014 02DC 2122 0161 203A 0153 017E 0178';

        $class = static function (string $hexList): string {
            $out = '';
            foreach (preg_split('/\s+/', trim($hexList)) as $item) {
                $out .= str_contains($item, '-')
                    ? "\x{".str_replace('-', '}-\x{', $item).'}'
                    : "\x{$item}";
            }

            return $out;
        };

        return '/['.$class($leads).']['.$class($continuations).']/u';
    }

    /**
     * Render a bounded, actionable failure message.
     *
     * @param  list<string>  $offenders
     */
    private function describe(array $offenders, string $headline): string
    {
        $shown = array_slice($offenders, 0, 40);
        $more = count($offenders) - count($shown);

        return $headline
            .(count($offenders) ? ' ('.count($offenders).' total)' : '')."\n"
            .implode("\n", $shown)
            .($more > 0 ? "\n... and {$more} more" : '');
    }

    public function test_source_tree_contains_no_mojibake(): void
    {
        $pattern = $this->markerClass();
        $offenders = [];

        foreach ($this->sourceFiles() as $file) {
            $text = file_get_contents($file);
            if ($text === false || ! mb_check_encoding($text, 'UTF-8')) {
                continue; // invalid encoding is covered by the test below
            }

            foreach (explode("\n", $text) as $index => $line) {
                if (preg_match($pattern, $line)) {
                    $offenders[] = sprintf(
                        '%s:%d',
                        str_replace(dirname(__DIR__, 2).'/', '', $file),
                        $index + 1
                    );
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            $this->describe(
                $offenders,
                'Mojibake found in source (UTF-8 re-encoded as cp1252). '
                .'Do not bulk-edit these files with a non-UTF-8 aware editor or shell command.'
            )
        );
    }

    public function test_source_files_are_valid_utf8(): void
    {
        $invalid = [];

        foreach ($this->sourceFiles() as $file) {
            $text = file_get_contents($file);
            if ($text === false) {
                continue;
            }
            if (! mb_check_encoding($text, 'UTF-8')) {
                $invalid[] = str_replace(dirname(__DIR__, 2).'/', '', $file);
            }
        }

        $this->assertSame(
            [],
            $invalid,
            $this->describe($invalid, 'Files that are not valid UTF-8')
        );
    }

    public function test_no_unicode_replacement_character_anywhere(): void
    {
        $offenders = [];

        foreach ($this->sourceFiles() as $file) {
            $text = file_get_contents($file);
            if ($text === false || ! str_contains($text, "\u{FFFD}")) {
                continue;
            }
            foreach (explode("\n", $text) as $index => $line) {
                if (str_contains($line, "\u{FFFD}")) {
                    $offenders[] = sprintf(
                        '%s:%d',
                        str_replace(dirname(__DIR__, 2).'/', '', $file),
                        $index + 1
                    );
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            $this->describe(
                $offenders,
                'U+FFFD found: an earlier repair was lossy and discarded original bytes'
            )
        );
    }
}
