<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * Version details for the inactive users tool.
 *
 * Defines the version, required Moodle version, maturity, and release
 * information for the plugin.
 *
 * @package    tool_inactiveusersgc
 * @copyright  2025 Waleed ul Hassan <waleed.hassan@catalyst-eu.net>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$plugin->component = 'tool_inactiveusersgc';
$plugin->version   = 2025092903;
$plugin->requires  = 2021051700;
$plugin->release   = '1.0.0';
$plugin->maturity  = MATURITY_BETA;
