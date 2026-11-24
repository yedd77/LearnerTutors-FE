<?php
// This file is part of Moodle - https://moodle.org/

/**
 * Page count of a PDF, read from its page tree, without extra libraries.
 *
 * @package local_ragassistant
 */

namespace local_ragassistant\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Reads the /Count of a PDF's root page tree node.
 *
 * Used to reject PDFs over the backend's page limit at upload time instead of
 * after a failed indexing task. The backend stays the real check: when the count
 * cannot be read (damaged or encrypted files, unusual structures) this returns
 * null and the upload is allowed.
 */
class pdf_page_counter {
    /** Pages the backend indexes per PDF (FastAPI MAX_PAGES, 300 by default). */
    public const MAX_PAGES = 300;

    /** Compressed object streams larger than this are not inflated. */
    private const MAX_STREAM_BYTES = 8 * 1024 * 1024;

    /**
     * Number of pages, or null when it cannot be determined.
     *
     * @param \stored_file $file
     * @return int|null
     */
    public static function count(\stored_file $file): ?int {
        try {
            return self::count_content($file->get_content());
        } catch (\Throwable $exception) {
            return null;
        }
    }

    /**
     * Number of pages in raw PDF bytes, or null when it cannot be determined.
     *
     * @param string $content
     * @return int|null
     */
    public static function count_content(string $content): ?int {
        if (strncmp(ltrim(substr($content, 0, 1024)), '%PDF-', 5) !== 0) {
            return null;
        }

        // Classic PDFs: the page tree dictionaries are plain text. The root node is
        // the /Pages dictionary without a /Parent; with incremental updates, the
        // last one written is the current one.
        $count = self::root_count([$content], true);
        if ($count !== null) {
            return $count;
        }

        // PDF 1.5+: dictionaries may sit inside compressed object streams.
        $objects = [];
        if (preg_match_all('#/Type\s*/ObjStm(?![A-Za-z0-9])#', $content, $matches, PREG_OFFSET_CAPTURE)) {
            foreach ($matches[0] as $match) {
                $found = self::enclosing_dictionary($content, $match[1]);
                if ($found === null) {
                    continue;
                }
                [$dictionary, $end] = $found;
                if (!preg_match('#/Filter\s*/FlateDecode#', $dictionary) ||
                        !preg_match('#/Length\s+(\d+)(?!\s+\d+\s+R)#', $dictionary, $length) ||
                        !preg_match('#\G\s*stream\r?\n#', $content, $stream, 0, $end)) {
                    continue;
                }
                $size = (int) $length[1];
                if ($size <= 0 || $size > self::MAX_STREAM_BYTES) {
                    continue;
                }
                $data = @gzuncompress(substr($content, $end + strlen($stream[0]), $size));
                if ($data !== false) {
                    $objects[] = $data;
                }
            }
        }
        return $objects ? self::root_count($objects, false) : null;
    }

    /**
     * /Count of the root /Pages dictionary found in the given texts.
     *
     * @param string[] $texts
     * @param bool $lastwins true: take the last root (incremental updates), false: the largest
     * @return int|null
     */
    private static function root_count(array $texts, bool $lastwins): ?int {
        $result = null;
        foreach ($texts as $text) {
            // A cheap search for the type, then the enclosing dictionary is found by
            // scanning brackets: a regex over the whole dictionary hits PCRE's
            // backtracking limit on large files.
            if (!preg_match_all('#/Type\s*/Pages(?![A-Za-z0-9])#', $text, $matches, PREG_OFFSET_CAPTURE)) {
                continue;
            }
            foreach ($matches[0] as $match) {
                $found = self::enclosing_dictionary($text, $match[1]);
                $dictionary = $found[0] ?? null;
                if ($dictionary === null || preg_match('#/Parent[\s/]#', $dictionary) ||
                        !preg_match('#/Count\s+(\d+)#', $dictionary, $count)) {
                    continue;
                }
                $pages = (int) $count[1];
                $result = ($lastwins || $result === null) ? $pages : max($result, $pages);
            }
        }
        return $result;
    }

    /**
     * The dictionary around $offset: its top level (nested dictionaries left out) and the
     * offset just after its closing ">>", or null.
     *
     * @param string $text
     * @param int $offset
     * @return array|null [string $toplevel, int $end]
     */
    private static function enclosing_dictionary(string $text, int $offset): ?array {
        $window = 8192;
        $depth = 0;
        $start = null;
        for ($i = $offset - 1, $min = max(0, $offset - $window); $i > $min; $i--) {
            $pair = $text[$i - 1] . $text[$i];
            if ($pair === '>>') {
                $depth++;
                $i--;
            } else if ($pair === '<<') {
                if ($depth === 0) {
                    $start = $i + 1;
                    break;
                }
                $depth--;
                $i--;
            }
        }
        if ($start === null) {
            return null;
        }
        $depth = 0;
        $top = '';
        $length = strlen($text);
        for ($i = $start, $max = min($length - 1, $start + 2 * $window); $i < $max; $i++) {
            $pair = $text[$i] . $text[$i + 1];
            if ($pair === '<<') {
                $depth++;
                $i++;
            } else if ($pair === '>>') {
                if ($depth === 0) {
                    return [$top, $i + 2];
                }
                $depth--;
                $i++;
            } else if ($depth === 0) {
                $top .= $text[$i];
            }
        }
        return null;
    }
}
