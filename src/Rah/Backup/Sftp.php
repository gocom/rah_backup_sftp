<?php

/*
 * rah_backup_sftp - SFTP transfer module for rah_backup
 * https://github.com/gocom/rah_backup_sftp
 *
 * Copyright (C) 2014 Jukka Svahn
 *
 * This file is part of rah_backup_sftp.
 *
 * rah_backup_sftp is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License
 * as published by the Free Software Foundation, version 2.
 *
 * rah_backup_sftp is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with rah_backup. If not, see <http://www.gnu.org/licenses/>.
 */

class Rah_Backup_Sftp
{
    /**
     * Constructor.
     */

    public function __construct()
    {
        add_privs('plugin_prefs.rah_backup_sftp', '1');
        add_privs('prefs.rah_bckp_sftp', '1');
        register_callback(array($this, 'sync'), 'rah_backup.created');
        register_callback(array($this, 'sync'), 'rah_backup.deleted');
        register_callback(array($this, 'prefs'), 'plugin_prefs.rah_backup_sftp');
        register_callback(array($this, 'install'), 'plugin_lifecycle.rah_backup_sftp', 'installed');
        register_callback(array($this, 'uninstall'), 'plugin_lifecycle.rah_backup_sftp', 'deleted');
    }

	/**
	 * Installer.
	 */

	public function install()
	{
		$position = 250;

		foreach (array(
			'rah_backup_sftp_host' => array('text_input', ''),
            'rah_backup_sftp_port' => array('text_input', 22),
            'rah_backup_sftp_path' => array('text_input', ''),
            'rah_backup_sftp_user' => array('text_input', ''),
            'rah_backup_sftp_pass' => array('text_input', ''),
            'rah_backup_sftp_private_key' => array('text_input', ''),
		) as $name => $val) {
			if (get_pref($name, false) === false) {
				set_pref($name, $val[1], 'rah_bckp_sftp', PREF_ADVANCED, $val[0], $position);
			}

			$position++;
		}
	}

	/**
	 * Uninstaller.
	 */

	public function uninstall()
	{
		safe_delete('txp_prefs', "name like 'rah\_backup\_stfp\_%'");
	}

    /**
     * Syncs backups.
     *
     * This callback handler syncs backups between
     * servers. On creatation it uploads files and
     * on delete it tries to remove them.
     *
     * On error it throws an exception. Failed deletes
     * are ignored to allow setting up uplaod-only
     * backup system where already created backups
     * can not be modified.
     *
     * @param  string $event Callback event
     * @param  string $step  Callback step
     * @param  array  $data  The files to upload
     * @throws Exception
     */

    public function sync($event, $step, $data)
    {
        try {

            if (!get_pref('rah_backup_sftp_host') || !get_pref('rah_backup_sftp_pass')) {
                return;
            }

            $sftp = new Net_SFTP(
                get_pref('rah_backup_sftp_host'),
                (int) get_pref('rah_backup_sftp_port'),
                90
            );

            if ($file = get_pref('rah_backup_sftp_private_key')) {
                $file = txpath . '/' . $file;
                $key = new Crypt_RSA();

                if (get_pref('rah_backup_sftp_pass') !== '') {
                    $key->setPassword(get_pref('rah_backup_sftp_pass'));
                }

                if (!file_exists($file) || !is_file($file) || !is_readable($file)) {
                    throw new Exception('Unable read private RSA key file: '.$file);
                }

                if ($key->loadKey(file_get_contents($file)) === false) {
                    throw new Exception('Unable decrypt and load RSA key.');
                }

                $login = $sftp->login(
                    get_pref('rah_backup_sftp_user'),
                    $key
                );
            } else {
                $login = $sftp->login(
                    get_pref('rah_backup_sftp_user'),
                    get_pref('rah_backup_sftp_pass')
                );
            }

            if ($login === false) {
                throw new Exception('Unable to login to SFTP server.');
            }

		    if (get_pref('rah_backup_sftp_path') && $sftp->chdir(get_pref('rah_backup_sftp_path')) === false) {
                throw new Exception('Unable change remote cwd: '.get_pref('rah_backup_sftp_path'));
		    }

            if ($event === 'rah_backup.deleted') {
                foreach ($data['files'] as $name => $path) {
                    $sftp->delete($name);
                }
            } else {
                foreach ($data['files'] as $name => $path) {
                    if ($sftp->put($name, $path, NET_SFTP_LOCAL_FILE) === false) {
                        throw new Exception('Uploading '.$name.' failed.');
                    }
                }
            }
        } catch (Exception $e) {
            throw new Exception('rah_backup_sftp: '.$e->getMessage());
        }
    }
}

new Rah_Backup_Sftp();
