<?php
/*
 * snort_chk_log_dir_size.php
 * part of pfSense
 *
 * Modified for the Pfsense snort package v. 1.8+
 * Copyright (C) 2009-2010 Robert Zelaya Developer
 * All rights reserved.
 *
 * Redistribution and use in source and binary forms, with or without
 * modification, are permitted provided that the following conditions are met:
 *
 * 1. Redistributions of source code must retain the above copyright notice,
 * this list of conditions and the following disclaimer.
 *
 * 2. Redistributions in binary form must reproduce the above copyright
 * notice, this list of conditions and the following disclaimer in the
 * documentation and/or other materials provided with the distribution.
 *
 * THIS SOFTWARE IS PROVIDED ``AS IS'' AND ANY EXPRESS OR IMPLIED WARRANTIES,
 * INCLUDING, BUT NOT LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY
 * AND FITNESS FOR A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE
 * AUTHOR BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY,
 * OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF
 * SUBSTITUTE GOODS OR SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS
 * INTERRUPTION) HOWEVER CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN
 * CONTRACT, STRICT LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE)
 * ARISING IN ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE
 * POSSIBILITY OF SUCH DAMAGE.
 */

require_once("/usr/local/pkg/snort/snort.inc");

//        'B' => 1,
//        'KB' => 1024,
//        'MB' => 1024 * 1024,
//        'GB' => 1024 * 1024 * 1024,
//        'TB' => 1024 * 1024 * 1024 * 1024,
//        'PB' => 1024 * 1024 * 1024 * 1024 * 1024,


/* chk if snort log dir is full if so clear it */
$snortloglimit = $config['installedpackages']['snortglobal']['snortloglimit'];
$snortloglimitsize = $config['installedpackages']['snortglobal']['snortloglimitsize'];

if ($g['booting']==true)
	return;

if ($snortloglimit == 'off')
	return;

if (!is_array($config['installedpackages']['snortglobal']['rule']))
	return;

/* Convert Log Limit Size setting from MB to KB */
$snortloglimitsizeKB = round($snortloglimitsize * 1024);
$snortlogdirsizeKB = snort_Getdirsize(SNORTLOGDIR);
if ($snortlogdirsizeKB > 0 && $snortlogdirsizeKB > $snortloglimitsizeKB) {
	log_error(gettext("[Snort] Log directory size exceeds configured limit of " . number_format($snortloglimitsize) . " MB set on Global Settings tab. All Snort log files will be truncated."));
	conf_mount_rw();

	/* Truncate the Rules Update Log file if it exists */
	if (file_exists(RULES_UPD_LOGFILE)) {
		log_error(gettext("[Snort] Truncating the Rules Update Log file..."));
		$fd = @fopen(RULES_UPD_LOGFILE, "w+");
		if ($fd)
			fclose($fd);
	}

	/* Clean-up the logs for each configured Snort instance */
	foreach ($config['installedpackages']['snortglobal']['rule'] as $value) {
		$if_real = snort_get_real_interface($value['interface']);
		$snort_uuid = $value['uuid'];
		$snort_log_dir = SNORTLOGDIR . "/snort_{$if_real}{$snort_uuid}";
		log_error(gettext("[Snort] Truncating logs for {$value['descr']} ({$if_real})..."));
		snort_post_delete_logs($snort_uuid);

		/* Truncate the alert log file if it exists */
		if (file_exists("{$snort_log_dir}/alert")) {
			$fd = @fopen("{$snort_log_dir}/alert", "w+");
			if ($fd)
				fclose($fd);
		}

		/* This is needed if snort is run as snort user */
		mwexec('/bin/chmod 660 /var/log/snort/*', true);

		/* Soft-restart Snort process to resync logging */
		if (file_exists("{$g['varrun_path']}/snort_{$if_real}{$snort_uuid}.pid")) {
			log_error(gettext("[Snort] Restarting logging on {$value['descr']} ({$if_real})..."));
			mwexec("/bin/pkill -HUP -F {$g['varrun_path']}/snort_{$if_real}{$snort_uuid}.pid -a");
		}
	}
	conf_mount_ro();
	log_error(gettext("[Snort] Automatic clean-up of Snort logs completed."));
}

?>
