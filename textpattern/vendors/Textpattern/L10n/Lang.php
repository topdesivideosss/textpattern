<?php

/*
 * Textpattern Content Management System
 * https://textpattern.com/
 *
 * Copyright (C) 2019 The Textpattern Development Team
 *
 * This file is part of Textpattern.
 *
 * Textpattern is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License
 * as published by the Free Software Foundation, version 2.
 *
 * Textpattern is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with Textpattern. If not, see <https://www.gnu.org/licenses/>.
 */

/**
 * Language manipulation.
 *
 * @since   4.7.0
 * @package L10n
 */

namespace Textpattern\L10n;

class Lang implements \Textpattern\Container\ReusableInterface
{
    /**
     * Language base directory that houses all the language files/textpacks.
     *
     * @var string
     */

    protected $langDirectory = null;

    /**
     * List of files in the $langDirectory.
     *
     * @var array
     */

    protected $files = array();

    /**
     * The currently active language designator.
     *
     * @var string
     */

    protected $activeLang = null;

    /**
     * Metadata for languages installed in the database.
     *
     * @var array
     */

    protected $dbLangs = array();

    /**
     * Metadata for all available languages in the filesystem.
     *
     * @var array
     */

    protected $allLangs = array();

    /**
     * List of strings that have been loaded.
     *
     * @var array
     */

    protected $strings = null;

    /**
     * Date format to use for the lastmod column.
     *
     * @var string
     */

    protected $lastmodFormat = 'YmdHis';

    /**
     * Constructor.
     *
     * @param string $langDirectory Language directory to use
     */

    public function __construct($langDirectory = null)
    {
        if ($langDirectory === null) {
            $langDirectory = txpath.DS.'lang'.DS;
        }

        $this->langDirectory = $langDirectory;

        if (!$this->files) {
            $this->files = $this->files();
        }
    }

    /**
     * Return all installed languages in the database.
     *
     * @return array Available language codes
     */

    public function installed()
    {
        if (!$this->dbLangs) {
            $this->available();
        }

        $installed_langs = array();

        foreach ($this->dbLangs as $row) {
            $installed_langs[] = $row['lang'];
        }

        return $installed_langs;
    }

    /**
     * Return all language files in the lang directory.
     *
     * @param  array $extensions Language files extensions
     * @return array Available language filenames
     */

    public function files($extensions = array('ini', 'textpack', 'txt'))
    {
        if (!is_dir($this->langDirectory) || !is_readable($this->langDirectory)) {
            trigger_error('Lang directory is not accessible: '.$this->langDirectory, E_USER_WARNING);

            return array();
        }

        if (defined('GLOB_BRACE')) {
            return glob($this->langDirectory.'*.{'.implode(',', $extensions).'}', GLOB_BRACE);
        }

        $files = array();

        foreach ((array)$extensions as $ext) {
            $files = array_merge($files, (array) glob($this->langDirectory.'*.'.$ext));
        }

        return $files;
    }

    /**
     * Locate a file in the lang directory based on a language code.
     *
     * @param  string $lang_code The language code to look up
     * @return string|null       The matching filename
     */

    public function findFilename($lang_code)
    {
        $out = null;

        foreach ($this->files as $file) {
            $pathinfo = pathinfo($file);

            if ($pathinfo['filename'] === $lang_code) {
                $out = $file;
                break;
            }
        }

        return $out;
    }

    /**
     * Read the meta info from the top of the given language file.
     *
     * @param  string $file The filename to read
     * @return array        Meta info such as language name, language code, language direction and last modified time
     */

    public function fetchMeta($file)
    {
        $meta = array();

        if (is_file($file) && is_readable($file)) {
            $numMetaRows = 4;
            $separator = '=>';
            extract(pathinfo($file));
            $filename = preg_replace('/\.(txt|textpack|ini)$/i', '', $basename);
            $ini = strtolower($extension) == 'ini';

            $meta['filename'] = $filename;

            if ($fp = @fopen($file, 'r')) {
                for ($idx = 0; $idx < $numMetaRows; $idx++) {
                    $rows[] = fgets($fp, 1024);
                }

                fclose($fp);
                $meta['time'] = filemtime($file);

                if ($ini) {
                    $langInfo = parse_ini_string(join($rows));
                    $meta['name'] = (!empty($langInfo['lang_name'])) ? $langInfo['lang_name'] : $filename;
                    $meta['code'] = (!empty($langInfo['lang_code'])) ? strtolower($langInfo['lang_code']) : $filename;
                    $meta['direction'] = (!empty($langInfo['lang_dir'])) ? strtolower($langInfo['lang_dir']) : 'ltr';
                } else {
                    $langName = do_list($rows[1], $separator);
                    $langCode = do_list($rows[2], $separator);
                    $langDirection = do_list($rows[3], $separator);

                    $meta['name'] = (isset($langName[1])) ? $langName[1] : $filename;
                    $meta['code'] = (isset($langCode[1])) ? strtolower($langCode[1]) : $filename;
                    $meta['direction'] = (isset($langDirection[1])) ? strtolower($langDirection[1]) : 'ltr';
                }
            }
        }

        return $meta;
    }

    /**
     * Fetch available languages.
     *
     * Depending on the flags, the returned array can contain active,
     * installed or available language metadata.
     *
     * @param  int $flags Determine which type of information to return
     * @param  int $force Force update the given information, even if it's already populated
     * @return array
     */

    public function available($flags = TEXTPATTERN_LANG_AVAILABLE, $force = 0)
    {
        if ($force & TEXTPATTERN_LANG_ACTIVE || $this->activeLang === null) {
            $this->activeLang = get_pref('language', TEXTPATTERN_DEFAULT_LANG, true);
            $this->activeLang = \Txp::get('\Textpattern\L10n\Locale')->validLocale($this->activeLang);
        }

        if ($force & TEXTPATTERN_LANG_INSTALLED || !$this->dbLangs) {
            // Need a value here for the language itself, not for each one of the rows.
            $ownClause = ($this->hasOwnerSupport() ? "owner = ''" : "1")." GROUP BY lang ORDER BY lastmod DESC";
            $this->dbLangs = safe_rows(
                "lang, UNIX_TIMESTAMP(MAX(lastmod)) AS lastmod",
                'txp_lang',
                $ownClause
            );
        }

        if ($force & TEXTPATTERN_LANG_AVAILABLE || !$this->allLangs) {
            $currently_lang = array();
            $installed_lang = array();
            $available_lang = array();

            // Set up the current and installed array. Define their names as
            // 'unknown' for now in case the file is missing or mangled. The
            // name will be overwritten when reading from the filesystem if
            // it's intact.
            foreach ($this->dbLangs as $language) {
                if ($language['lang'] === $this->activeLang) {
                    $currently_lang[$language['lang']] = array(
                        'db_lastmod' => $language['lastmod'],
                        'type'       => 'active',
                        'name'       => gTxt('unknown'),
                    );
                } else {
                    $installed_lang[$language['lang']] = array(
                        'db_lastmod' => $language['lastmod'],
                        'type'       => 'installed',
                        'name'       => gTxt('unknown'),
                    );
                }
            }

            // Get items from filesystem.
            if (!empty($this->files)) {
                foreach ($this->files as $file) {
                    $meta = $this->fetchMeta($file);

                    if ($meta && !isset($available_lang[$meta['filename']])) {
                        $name = $meta['filename'];

                        if (array_key_exists($name, $currently_lang)) {
                            $currently_lang[$name]['name'] = $meta['name'];
                            $currently_lang[$name]['direction'] = $meta['direction'];
                            $currently_lang[$name]['file_lastmod'] = $meta['time'];
                        } elseif (array_key_exists($name, $installed_lang)) {
                            $installed_lang[$name]['name'] = $meta['name'];
                            $installed_lang[$name]['direction'] = $meta['direction'];
                            $installed_lang[$name]['file_lastmod'] = $meta['time'];
                        }

                        $available_lang[$name]['file_lastmod'] = $meta['time'];
                        $available_lang[$name]['name'] = $meta['name'];
                        $available_lang[$name]['direction'] = $meta['direction'];
                        $available_lang[$name]['type'] = 'available';
                    }
                }
            }

            $this->allLangs = array(
                'active'    => $currently_lang,
                'installed' => $installed_lang,
                'available' => $available_lang,
            );
        }

        $out = array();

        if ($flags & TEXTPATTERN_LANG_ACTIVE) {
            $out = array_merge($out, $this->allLangs['active']);
        }

        if ($flags & TEXTPATTERN_LANG_INSTALLED) {
            $out = array_merge($out, $this->allLangs['installed']);
        }

        if ($flags & TEXTPATTERN_LANG_AVAILABLE) {
            $out = array_merge($out, $this->allLangs['available']);
        }

        return $out;
    }

    /**
     * Set/overwrite the language strings. Chainable.
     *
     * @param array $strings Set of strings to use
     * @param bool  $merge   Whether to merge the strings (true) or replace them entirely (false)
     */

    public function setPack(array $strings, $merge = false)
    {
        if ((bool)$merge) {
            $this->strings = is_array($this->strings) ? array_merge($this->strings, (array)$strings) : (array)$strings;
        } else {
            $this->strings = (array)$strings;
        }

        return $this;
    }

    /**
     * Fetch Textpack strings from the file matching the given $lang_code.
     *
     * A subset of the strings may be fetched by supplying a list of
     * $group names to grab.
     *
     * @param  string|array $lang_code The language code to fetch, or array(lang_code, override_lang_code)
     * @param  string|array $group     Comma-separated list or array of headings from which to extract strings
     * @return array
     */

    public function getPack($lang_code, $group = null)
    {
        if (is_array($lang_code)) {
            $lang_over = $lang_code[1];
            $lang_code = $lang_code[0];
        } else {
            $lang_over = $lang_code;
        }

        $lang_file = $this->findFilename($lang_code);

        if ($textpack = @file_get_contents($lang_file)) {
            $parser = new \Textpattern\Textpack\Parser();
            $parser->setOwner('');
            $parser->setLanguage($lang_over);
            $parser->parse($textpack, $group);
            $textpack = $parser->getStrings($lang_over);
        }

        // Reindex the pack so it can be merged.
        $langpack = array();

        if (is_array($textpack)) {
            foreach ($textpack as $translation) {
                $langpack[$translation['name']] = $translation;
            }
        }

        return $langpack;
    }

    /**
     * Install a language pack from a file.
     *
     * @param string $lang_code The lang identifier to load
     */

    public function installFile($lang_code)
    {
        $langpack = $this->getPack($lang_code);

        if (empty($langpack)) {
            return false;
        }

        if ($lang_code !== TEXTPATTERN_DEFAULT_LANG) {
            // Load the fallback strings so we're not left with untranslated strings.
            // Note that the language is overridden to match the to-be-installed lang.
            $fallpack = $this->getPack(array(TEXTPATTERN_DEFAULT_LANG, $lang_code));
            $langpack = array_merge($fallpack, $langpack);
        }

        return ($this->upsertPack($langpack) === false) ? false : true;
    }

    /**
     * Install localisation strings from a Textpack.
     *
     * @param   string $textpack    The Textpack to install
     * @param   bool   $addNewLangs If TRUE, installs strings for any included language
     * @return  int                 Number of installed strings
     * @package L10n
     */

    public function installTextpack($textpack, $addNewLangs = false)
    {
        $parser = new \Textpattern\Textpack\Parser();
        $parser->setLanguage(get_pref('language', TEXTPATTERN_DEFAULT_LANG));
        $parser->parse($textpack);
        $packLanguages = $parser->getLanguages();

        if (empty($packLanguages)) {
            return 0;
        }

        $allpacks = array();

        foreach ($packLanguages as $lang_code) {
            $allpacks = array_merge($allpacks, $parser->getStrings($lang_code));
        }

        $installed_langs = $this->installed();
        $values = array();

        foreach ($allpacks as $translation) {
            extract(doSlash($translation));

            if (!$addNewLangs && !in_array($lang, $installed_langs)) {
                continue;
            }

            $values[] = "('$name', '$lang', '$data', '$event', '$owner', NOW())";
        }

        $value = implode(',', $values);

        !$value || safe_query("INSERT INTO ".PFX."txp_lang
            (name, lang, data, event, owner, lastmod)
            VALUES $value
            ON DUPLICATE KEY UPDATE
            data=VALUES(data), event=VALUES(event), owner=VALUES(owner), lastmod=VALUES(lastmod)");

        return count($values);
    }

    /**
     * Insert or update a language pack.
     *
     * @param  array  $langpack The language pack to store
     * @param  string $langpack The owner to use if not in the pack
     * @return result set
     */

    public function upsertPack($langpack, $owner_ref = '')
    {
        $result = false;

        if ($langpack) {
            $values = array();

            foreach ($langpack as $key => $translation) {
                extract(doSlash($translation));

                $owner = empty($owner) ? doSlash($owner_ref) : $owner;
                $lastmod = empty($lastmod) ? 'NOW()' : "'$lastmod'";
                $values[] = "('$name', '$lang', '$data', '$event', '$owner', $lastmod)";
            }

            if ($values) {
                $value = implode(',', $values);
                $result = safe_query("INSERT INTO ".PFX."txp_lang
                    (name, lang, data, event, owner, lastmod)
                    VALUES $value
                    ON DUPLICATE KEY UPDATE
                    data=VALUES(data), event=VALUES(event), owner=VALUES(owner), lastmod=VALUES(lastmod)");
            }
        }

        return $result;
    }

    /**
     * Fetch the given language's strings from the database as an array.
     *
     * If no $events are specified, only appropriate strings for the current context
     * are returned. If the 'txpinterface' constant is 'public' only strings from
     * events 'common' and 'public' are returned.
     *
     * Note the returned array includes the language if the fallback has been used.
     * This ensures (as far as possible) a full complement of strings, regardless of
     * the degree of translation that's taken place in the desired $lang code.
     * Any holes can be mopped up by the default language.
     *
     * @param  string       $lang_code The language code
     * @param  array|string $events    A list of loaded events to extract
     * @return array
     */

    public function extract($lang_code, $events = null)
    {
        $where = array(
            "lang = '".doSlash($lang_code)."'",
            "name != ''",
        );

        if ($events === null && txpinterface !== 'admin') {
            $events = array('public', 'common');
        }

        if (txpinterface === 'admin') {
            $admin_events = array('admin-side', 'common');

            if ($events) {
                $list = (is_array($events) ? $events : do_list_unique($events));
                $admin_events = array_merge($admin_events, $list);
            }

            $events = $admin_events;
        }

        if ($events) {
            // For the time being, load any non-core (plugin) strings on every
            // page too. Core strings have no owner. Plugins installed since 4.6+
            // will have either the 'site' owner or their own plugin name.
            // Longer term, when all plugins have caught up with the event
            // naming convention, the owner clause can be removed.
            $where[] = "(event IN (".join(',', quote_list((array) $events)).")".($this->hasOwnerSupport() ? " OR owner != '')" : ')');
        }

        $out = array();

        $rs = safe_rows_start("name, data", 'txp_lang', join(' AND ', $where));

        if (!empty($rs)) {
            while ($a = nextRow($rs)) {
                $out[$a['name']] = $a['data'];
            }
        }

        return $out;
    }

    /**
     * Load the given language's strings from the database into the class.
     *
     * Note the returned array includes the language if the fallback has been used.
     * This ensures (as far as possible) a full complement of strings, regardless of
     * the degree of translation that's taken place in the desired $lang code.
     * Any holes can be mopped up by the default language.
     *
     * @param  string       $lang_code The language code
     * @param  array|string $events    A list of loaded events to load
     * @see    extract()
     * @return array
     */

    public function load($lang_code, $events = null)
    {
        $out = $this->extract($lang_code, $events);
        $this->strings = $out;

        return $this->strings;
    }

    /**
     * Fetch the language strings from the loaded language.
     *
     * @return array
     */

    public function getStrings()
    {
        return $this->strings;
    }

    /**
     * Determine if a string key exists in the current pack
     *
     * @param  string  $var The string name to check
     * @return boolean
     */

    public function hasString($var)
    {
        $v = strtolower($var);

        return isset($this->strings[$v]);
    }

    /**
     * Return a localisation string.
     *
     * @param   string $var    String name
     * @param   array  $atts   Replacement pairs
     * @param   string $escape Convert special characters to HTML entities. Either "html" or ""
     * @return  string A localisation string
     * @package L10n
     */

    public function txt($var, $atts = array(), $escape = 'html')
    {
        global $textarray; // deprecated since 4.7

        $v = strtolower($var);

        if (isset($this->strings[$v])) {
            $out = $this->strings[$v];
        } else {
            $out = isset($textarray[$v]) ? $textarray[$v] : '';
        }

        if ($atts && $escape == 'html') {
            $atts = array_map('txpspecialchars', $atts);
        }

        if ($out !== '') {
            return $atts ? strtr($out, $atts) : $out;
        }

        if ($atts) {
            return $var.': '.join(', ', $atts);
        }

        return $var;
    }

    /**
     * Generate an array of languages and their localised names.
     *
     * @param  int $flags Logical OR list of flags indiacting the type of list to return:
     *                    TEXTPATTERN_LANG_ACTIVE: the active language
     *                    TEXTPATTERN_LANG_INSTALLED: all installed languages
     *                    TEXTPATTERN_LANG_AVAILABLE: all available languages in the file system
     * @return array
     */

    public function languageList($flags = null)
    {
        if ($flags === null) {
            $flags = TEXTPATTERN_LANG_ACTIVE | TEXTPATTERN_LANG_INSTALLED;
        }

        $installed_langs = $this->available((int)$flags);
        $vals = array();

        foreach ($installed_langs as $lang => $langdata) {
            $vals[$lang] = $langdata['name'];

            if (trim($vals[$lang]) == '') {
                $vals[$lang] = $lang;
            }
        }

        ksort($vals);
        reset($vals);

        return $vals;
    }

    /**
     * Generate a &lt;select&gt; element of languages.
     *
     * @param  string $name  The HTML name and ID to assign to the select control
     * @param  string $val   The currently active language identifier (en-gb, fr, de, ...)
     * @param  int    $flags Logical OR list of flags indicating the type of list to return:
     *                       TEXTPATTERN_LANG_ACTIVE: the active language
     *                       TEXTPATTERN_LANG_INSTALLED: all installed languages
     *                       TEXTPATTERN_LANG_AVAILABLE: all available languages in the file system
     * @return string HTML
     */

    public function languageSelect($name, $val, $flags = null)
    {
        $vals = $this->languageList($flags);

        return selectInput($name, $vals, $val, false, true, $name);
    }

    /**
     * Determine if the class supports the 'owner' column or not.
     *
     * Only of use during upgrades from older versions to guard against errors.
     *
     * @return boolean
     */

    protected function hasOwnerSupport()
    {
        return (bool) version_compare(get_pref('version'), '4.6.0', '>=');
    }
}
