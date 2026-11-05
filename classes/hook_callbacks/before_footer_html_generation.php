<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

namespace local_ragassistant\hook_callbacks;

defined('MOODLE_INTERNAL') || die();

use core\hook\output\before_footer_html_generation as before_footer_hook;

/**
 * Registers the MathJax requirements for the RAG Assistant chat.
 *
 * @package    local_ragassistant
 */
final class before_footer_html_generation {
    /**
     * Add the MathJax configuration and library to the page requirements.
     *
     * @param before_footer_hook $hook
     * @return void
     */
    public static function handle(before_footer_hook $hook): void {
        // MathJax is configured and loaded by Moodle's filter_mathjaxloader.
        // Registering another CDN instance here overwrites that configuration.
    }
}
