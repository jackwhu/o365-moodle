<?php
// This file is part of Moodle - http://moodle.org/
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
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Plugin upgrade script.
 *
 * @package auth_oidc
 * @author James McQuillan <james.mcquillan@remote-learner.net>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @copyright (C) 2014 onwards Microsoft, Inc. (http://microsoft.com/)
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Update plugin.
 *
 * @param int $oldversion the version we are upgrading from
 * @return bool result
 */
function xmldb_auth_oidc_upgrade($oldversion) {
    global $DB;

    $dbman = $DB->get_manager();

    if ($oldversion < 2014111703) {
        // Lengthen field.
        $table = new xmldb_table('auth_oidc_token');
        $field = new xmldb_field('scope', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null, 'username');
        $dbman->change_field_type($table, $field);

        upgrade_plugin_savepoint(true, 2014111703, 'auth', 'oidc');
    }

    if ($oldversion < 2015012702) {
        $table = new xmldb_table('auth_oidc_state');
        $field = new xmldb_field('additionaldata', XMLDB_TYPE_TEXT, null, null, null, null, null, 'timecreated');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        upgrade_plugin_savepoint(true, 2015012702, 'auth', 'oidc');
    }

    if ($oldversion < 2015012703) {
        $table = new xmldb_table('auth_oidc_token');
        $field = new xmldb_field('oidcusername', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null, 'username');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        upgrade_plugin_savepoint(true, 2015012703, 'auth', 'oidc');
    }

    if ($oldversion < 2015012704) {
        // Update OIDC users.
        $sql = 'SELECT u.id as userid,
                       u.username as username,
                       tok.id as tokenid,
                       tok.oidcuniqid as oidcuniqid,
                       tok.idtoken as idtoken,
                       tok.oidcusername as oidcusername
                  FROM {auth_oidc_token} tok
                  JOIN {user} u ON u.username = tok.username
                 WHERE u.auth = ? AND deleted = ?';
        $params = ['oidc', 0];
        $userstoupdate = $DB->get_recordset_sql($sql, $params);
        foreach ($userstoupdate as $user) {
            if (empty($user->idtoken)) {
                continue;
            }

            try {
                // Decode idtoken and determine oidc username.
                $idtoken = \auth_oidc\jwt::instance_from_encoded($user->idtoken);
                $oidcusername = $idtoken->claim('upn');
                if (empty($oidcusername)) {
                    $oidcusername = $idtoken->claim('sub');
                }

                // Populate token oidcusername.
                if (empty($user->oidcusername)) {
                    $updatedtoken = new \stdClass;
                    $updatedtoken->id = $user->tokenid;
                    $updatedtoken->oidcusername = $oidcusername;
                    $DB->update_record('auth_oidc_token', $updatedtoken);
                }

                // Update user username (if applicable), so user can use rocreds loginflow.
                if ($user->username == strtolower($user->oidcuniqid)) {
                    // Old username, update to upn/sub.
                    if ($oidcusername != $user->username) {
                        // Update username.
                        $updateduser = new \stdClass;
                        $updateduser->id = $user->userid;
                        $updateduser->username = $oidcusername;
                        $DB->update_record('user', $updateduser);

                        $updatedtoken = new \stdClass;
                        $updatedtoken->id = $user->tokenid;
                        $updatedtoken->username = $oidcusername;
                        $DB->update_record('auth_oidc_token', $updatedtoken);
                    }
                }
            } catch (\Exception $e) {
                continue;
            }
        }
        upgrade_plugin_savepoint(true, 2015012704, 'auth', 'oidc');
    }

    if ($oldversion < 2015012707) {
        if (!$dbman->table_exists('auth_oidc_prevlogin')) {
            $dbman->install_one_table_from_xmldb_file(__DIR__.'/install.xml', 'auth_oidc_prevlogin');
        }
        upgrade_plugin_savepoint(true, 2015012707, 'auth', 'oidc');
    }

    if ($oldversion < 2015012710) {
        // Lengthen field.
        $table = new xmldb_table('auth_oidc_token');
        $field = new xmldb_field('scope', XMLDB_TYPE_TEXT, null, null, null, null, null, 'oidcusername');
        $dbman->change_field_type($table, $field);
        upgrade_plugin_savepoint(true, 2015012710, 'auth', 'oidc');
    }

    if ($oldversion < 2015111904.01) {
        // Ensure the username field in auth_oidc_token is lowercase.
        $authtokensrs = $DB->get_recordset('auth_oidc_token');
        foreach ($authtokensrs as $authtokenrec) {
            $newusername = trim(\core_text::strtolower($authtokenrec->username));
            if ($newusername !== $authtokenrec->username) {
                $updatedrec = new \stdClass;
                $updatedrec->id = $authtokenrec->id;
                $updatedrec->username = $newusername;
                $DB->update_record('auth_oidc_token', $updatedrec);
            }
        }
        upgrade_plugin_savepoint(true, 2015111904.01, 'auth', 'oidc');
    }

    if ($oldversion < 2015111905.01) {
        // Update old endpoints.
        $config = get_config('auth_oidc');
        if ($config->authendpoint === 'https://login.windows.net/common/oauth2/authorize') {
            set_config('authendpoint', 'https://login.microsoftonline.com/common/oauth2/authorize', 'auth_oidc');
        }

        if ($config->tokenendpoint === 'https://login.windows.net/common/oauth2/token') {
            set_config('tokenendpoint', 'https://login.microsoftonline.com/common/oauth2/token', 'auth_oidc');
        }

        upgrade_plugin_savepoint(true, 2015111905.01, 'auth', 'oidc');
    }

    if ($oldversion < 2018051700.01) {
        $table = new xmldb_table('auth_oidc_token');
        $field = new xmldb_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'username');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
            $sql = 'SELECT tok.id, tok.username, u.username, u.id as userid
                      FROM {auth_oidc_token} tok
                      JOIN {user} u ON u.username = tok.username';
            $records = $DB->get_recordset_sql($sql);
            foreach ($records as $record) {
                $newrec = new \stdClass;
                $newrec->id = $record->id;
                $newrec->userid = $record->userid;
                $DB->update_record('auth_oidc_token', $newrec);
            }
        }
        upgrade_plugin_savepoint(true, 2018051700.01, 'auth', 'oidc');
    }

    if ($oldversion < 2020020301) {
        $oldgraphtokens = $DB->get_records('auth_oidc_token', ['resource' => 'https://graph.windows.net']);
        foreach ($oldgraphtokens as $graphtoken) {
            $graphtoken->resource = 'https://graph.microsoft.com';
            $DB->update_record('auth_oidc_token', $graphtoken);
        }

        $oidcresource = get_config('auth_oidc', 'oidcresource');
        if ($oidcresource !== false && strpos($oidcresource, 'windows') !== false) {
            set_config('oidcresource', 'https://graph.microsoft.com', 'auth_oidc');
        }

        upgrade_plugin_savepoint(true, 2020020301, 'auth', 'oidc');
    }

    if ($oldversion < 2020071503) {
        $localo365singlesignoffsetting = get_config('local_o365', 'single_sign_off');
        if ($localo365singlesignoffsetting !== false) {
            set_config('single_sign_off', true, 'auth_oidc');
            unset_config('single_sign_off', 'local_o365');
        }

        upgrade_plugin_savepoint(true, 2020071503, 'auth', 'oidc');
    }

    if ($oldversion < 2020110901) {
        if ($dbman->field_exists('auth_oidc_token', 'resource')) {
            // Rename field resource on table auth_oidc_token to tokenresource.
            $table = new xmldb_table('auth_oidc_token');

            $field = new xmldb_field('resource', XMLDB_TYPE_CHAR, '127', null, XMLDB_NOTNULL, null, null, 'scope');

            // Launch rename field resource.
            $dbman->rename_field($table, $field, 'tokenresource');
        }

        // Oidc savepoint reached.
        upgrade_plugin_savepoint(true, 2020110901, 'auth', 'oidc');
    }

    if ($oldversion < 2020110903) {
        // Part 1: add index to auth_oidc_token table.
        $table = new xmldb_table('auth_oidc_token');

        // Define index userid (not unique) to be added to auth_oidc_token.
        $useridindex = new xmldb_index('userid', XMLDB_INDEX_NOTUNIQUE, ['userid']);

        // Conditionally launch add index userid.
        if (!$dbman->index_exists($table, $useridindex)) {
            $dbman->add_index($table, $useridindex);
        }

        // Define index username (not unique) to be added to auth_oidc_token.
        $usernameindex = new xmldb_index('username', XMLDB_INDEX_NOTUNIQUE, ['username']);

        // Conditionally launch add index username.
        if (!$dbman->index_exists($table, $usernameindex)) {
            $dbman->add_index($table, $usernameindex);
        }

        // Part 2: update Authorization and token end point URL.
        $aadtenant = get_config('local_o365', 'aadtenant');

        if ($aadtenant) {
            $authorizationendpoint = get_config('auth_oidc', 'authendpoint');
            if ($authorizationendpoint == 'https://login.microsoftonline.com/common/oauth2/authorize') {
                $authorizationendpoint = str_replace('common', $aadtenant, $authorizationendpoint);
                set_config('authendpoint', $authorizationendpoint, 'auth_oidc');
            }

            $tokenendpoint = get_config('auth_oidc', 'tokenendpoint');
            if ($tokenendpoint == 'https://login.microsoftonline.com/common/oauth2/token') {
                $tokenendpoint = str_replace('common', $aadtenant, $tokenendpoint);
                set_config('tokenendpoint', $tokenendpoint, 'auth_oidc');
            }
        }

        // Oidc savepoint reached.
        upgrade_plugin_savepoint(true, 2020110903, 'auth', 'oidc');
    }

    if ($oldversion < 2021051701) {
        // Migrate field mapping settings from local_o365.
        $existingfieldmappingsettings = get_config('local_o365', 'fieldmap');
        if ($existingfieldmappingsettings !== false) {
            $userfields = auth_oidc_get_all_user_fields();

            $existingfieldmappingsettings = @unserialize($existingfieldmappingsettings);
            if (is_array($existingfieldmappingsettings)) {
                foreach ($existingfieldmappingsettings as $existingfieldmappingsetting) {
                    $fieldmap = explode('/', $existingfieldmappingsetting);

                    if (count($fieldmap) !== 3) {
                        // Invalid settings, ignore.
                        continue;
                    }

                    list($remotefield, $localfield, $behaviour) = $fieldmap;

                    if ($remotefield == 'facsimileTelephoneNumber') {
                        $remotefield = 'faxNumber';
                    }

                    set_config('field_map_' . $localfield, $remotefield, 'auth_oidc');
                    set_config('field_lock_' . $localfield, 'unlocked', 'auth_oidc');
                    set_config('field_updatelocal_' . $localfield, $behaviour, 'auth_oidc');

                    if (($key = array_search($localfield, $userfields)) !== false) {
                        unset($userfields[$key]);
                    }
                }

                foreach ($userfields as $userfield) {
                    set_config('field_map_' . $userfield, '', 'auth_oidc');
                    set_config('field_lock_' . $userfield, 'unlocked', 'auth_oidc');
                    set_config('field_updatelocal_' . $userfield, 'always', 'auth_oidc');
                }
            }

            unset_config('fieldmap', 'local_o365');
        }

        // Oidc savepoint reached.
        upgrade_plugin_savepoint(true, 2021051701, 'auth', 'oidc');
    }

    return true;
}
